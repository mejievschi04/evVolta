<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\WalletTopup;
use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use App\Support\LocalTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReportDocumentService
{
    /**
     * @return array{pdf: string, filename: string}
     */
    public function stationsMonthly(Carbon $month): array
    {
        $month = LocalTime::of($month);
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $rows = $this->stationDayAggregates($from, $to);

        $title = 'Raport lunar pe statii';
        $periodLabel = $month->translatedFormat('F Y');
        $filename = sprintf('raport-statii-lunar-%s.pdf', $month->format('Y-m'));

        return [
            'pdf' => $this->renderStationsPdf($title, $periodLabel, $rows),
            'filename' => $filename,
        ];
    }

    /**
     * Top-up report as an Excel workbook: one row per payment, real date and number cells.
     *
     * @return array{content: string, filename: string}
     */
    public function walletTopups(Carbon $from, Carbon $to): array
    {
        $from = LocalTime::of($from)->startOfDay();
        $to = LocalTime::of($to)->endOfDay();
        $rows = $this->topupRows($from, $to);
        $seller = $this->seller();

        $bold = (new Style())->setFontBold();
        $title = (new Style())->setFontBold()->setFontSize(14);
        $header = (new Style())->setFontBold()->setBackgroundColor('F3F4F6');
        $money = (new Style())->setFormat('#,##0.00');
        $moneyBold = (new Style())->setFontBold()->setFormat('#,##0.00');
        $date = (new Style())->setFormat('dd.mm.yyyy hh:mm');

        $options = new Options();
        $options->setColumnWidth(6, 1);
        $options->setColumnWidth(18, 2);
        $options->setColumnWidth(26, 3);
        $options->setColumnWidth(30, 4);
        $options->setColumnWidth(14, 5);
        $options->setColumnWidth(14, 6);

        $path = tempnam(sys_get_temp_dir(), 'topups');
        $writer = new Writer($options);
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues([mb_strtoupper($seller['name'])], $bold));
        $writer->addRow(Row::fromValues([$seller['idno'] !== '' ? 'Cod fiscal (IDNO): '.$seller['idno'] : '']));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['RAPORT ALIMENTARI CONT'], $title));
        $writer->addRow(Row::fromValues([sprintf(
            'Perioada: %s – %s · Generat: %s · V CHARGE',
            $from->format('d.m.Y'),
            $to->format('d.m.Y'),
            LocalTime::now()->format('d.m.Y H:i')
        )]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Nr.', 'Data achitarii', 'Client', 'E-mail', 'Metoda', 'Suma (MDL)'], $header));

        foreach ($rows->values() as $index => $row) {
            $writer->addRow(new Row([
                Cell::fromValue($index + 1),
                Cell::fromValue($row['paid_at_local'], $date),
                Cell::fromValue($row['user_name']),
                Cell::fromValue($row['user_email']),
                Cell::fromValue($row['method']),
                Cell::fromValue($row['amount'], $money),
            ]));
        }

        if ($rows->isEmpty()) {
            $writer->addRow(Row::fromValues(['Nicio alimentare in interval.']));
        }

        // Labels start in column A (text flows over the empty cells): some viewers stop at rows starting later.
        $writer->addRow(new Row([
            Cell::fromValue(sprintf('Suma totala (%d alimentari)', $rows->count()), $bold),
            Cell::fromValue(''),
            Cell::fromValue(''),
            Cell::fromValue(''),
            Cell::fromValue(''),
            Cell::fromValue(round((float) $rows->sum('amount'), 2), $moneyBold),
        ]));

        $byMethod = $rows->groupBy('method')->sortKeys();
        if ($byMethod->isNotEmpty()) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(['Pe metode de plata', '', '', '', 'Alimentari', 'Suma (MDL)'], $header));
            foreach ($byMethod as $method => $group) {
                $writer->addRow(new Row([
                    Cell::fromValue((string) $method),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                    Cell::fromValue($group->count()),
                    Cell::fromValue(round((float) $group->sum('amount'), 2), $money),
                ]));
            }
        }

        $writer->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        return [
            'content' => $content,
            'filename' => sprintf('raport-alimentari-%s-%s.xlsx', $from->format('Ymd'), $to->format('Ymd')),
        ];
    }

    /**
     * One row per calendar day × station (sessions closed that day).
     *
     * @return Collection<int, array{date: string, station_id: int|null, station_name: string, sessions_count: int, total_kwh: float, revenue: float}>
     */
    private function stationDayAggregates(Carbon $from, Carbon $to): Collection
    {
        $sessions = ChargingSession::query()
            ->with(['station:id,name', 'invoice:id,source_session_id,total_amount,invoice_type'])
            ->whereNotNull('end_time')
            ->whereBetween('end_time', [LocalTime::utc($from), LocalTime::utc($to)])
            ->get();

        $stationIds = $sessions->pluck('station_id')->filter()->unique()->values();
        $stationNames = Station::query()
            ->whereIn('id', $stationIds)
            ->pluck('name', 'id');

        $grouped = $sessions->groupBy(function (ChargingSession $session) {
            $day = LocalTime::of($session->end_time)?->format('Y-m-d') ?? 'unknown';
            $stationId = (int) ($session->station_id ?? 0);

            return $day.'|'.$stationId;
        });

        return $grouped->map(function (Collection $group, string $key) use ($stationNames) {
            [$day, $stationKey] = explode('|', $key, 2);
            $stationId = (int) $stationKey;
            $name = $stationId > 0
                ? (string) ($stationNames[$stationId] ?? $group->first()?->station?->name ?? 'Statie #'.$stationId)
                : 'Fara statie';

            $revenue = round($group->sum(function (ChargingSession $session) {
                $invoice = $session->invoice;
                if ($invoice && $invoice->invoice_type === 'session') {
                    return (float) $invoice->total_amount;
                }

                return 0.0;
            }), 2);

            return [
                'date' => $day,
                'station_id' => $stationId > 0 ? $stationId : null,
                'station_name' => $name,
                'sessions_count' => $group->count(),
                'total_kwh' => round((float) $group->sum('kwh_consumed'), 3),
                'revenue' => $revenue,
            ];
        })
            ->sortBy([
                ['date', 'asc'],
                ['station_name', 'asc'],
            ])
            ->values();
    }

    /**
     * One row per paid top-up, in payment order (local time).
     *
     * @return Collection<int, array{id: int, paid_at: string, user_name: string, user_email: string, method: string, amount: float}>
     */
    private function topupRows(Carbon $from, Carbon $to): Collection
    {
        return WalletTopup::query()
            ->with('user:id,name,email')
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [LocalTime::utc($from), LocalTime::utc($to)])
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get()
            ->map(fn (WalletTopup $topup) => [
                'id' => (int) $topup->id,
                'paid_at' => LocalTime::of($topup->paid_at)->format('d.m.Y H:i'),
                // Local wall-clock time as a naive date: Excel cells carry no timezone.
                'paid_at_local' => new \DateTimeImmutable(LocalTime::of($topup->paid_at)->format('Y-m-d H:i:s')),
                'user_name' => (string) ($topup->user?->name ?? ($topup->user_id ? 'User #'.$topup->user_id : 'Fara utilizator')),
                'user_email' => (string) ($topup->user?->email ?? ''),
                'method' => WalletTopup::paymentMethodLabel($topup->payment_method),
                'amount' => round((float) $topup->amount, 2),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array{date: string, station_id: int|null, station_name: string, sessions_count: int, total_kwh: float, revenue: float}>  $rows
     */
    private function renderStationsPdf(string $title, string $periodLabel, Collection $rows): string
    {
        $totalSessions = (int) $rows->sum('sessions_count');
        $totalKwh = round((float) $rows->sum('total_kwh'), 3);
        $totalRevenue = round((float) $rows->sum('revenue'), 2);

        $bodyRows = $rows->map(function (array $row) {
            $dateLabel = $row['date'] !== 'unknown'
                ? Carbon::createFromFormat('Y-m-d', $row['date'])->format('d.m.Y')
                : '-';

            return sprintf(
                '<tr><td>%s</td><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
                e($dateLabel),
                e($row['station_name']),
                number_format($row['sessions_count'], 0, '.', ' '),
                number_format($row['total_kwh'], 3, '.', ' '),
                number_format($row['revenue'], 2, '.', ' ')
            );
        })->implode('');

        if ($bodyRows === '') {
            $bodyRows = '<tr><td colspan="5" class="empty">Nicio sesiune in interval.</td></tr>';
        }

        $html = $this->wrapReportHtml(
            $title,
            $periodLabel,
            <<<HTML
<table>
  <thead>
    <tr>
      <th>Data</th>
      <th>Statie</th>
      <th class="num">Sesiuni</th>
      <th class="num">kWh</th>
      <th class="num">Venit facturat (MDL)</th>
    </tr>
  </thead>
  <tbody>
    {$bodyRows}
    <tr class="grand">
      <td colspan="2"><strong>Total luna</strong></td>
      <td class="num"><strong>{$this->int($totalSessions)}</strong></td>
      <td class="num"><strong>{$this->kwh($totalKwh)}</strong></td>
      <td class="num"><strong>{$this->money($totalRevenue)}</strong></td>
    </tr>
  </tbody>
</table>
HTML
        );

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();
    }

    /**
     * Same seller identity as on invoices (INVOICE_SELLER_* / LEGAL_COMPANY_*).
     *
     * @return array{name: string, idno: string}
     */
    private function seller(): array
    {
        $snapshot = app(InvoiceFiscalCalculator::class)->sellerSnapshot();

        return [
            'name' => $snapshot['seller_name'] !== '' ? $snapshot['seller_name'] : (string) config('legal.company_name', 'Volta SRL'),
            'idno' => $snapshot['seller_idno'] !== '' ? $snapshot['seller_idno'] : (string) config('legal.company_idno', ''),
        ];
    }

    private function wrapReportHtml(string $title, string $periodLabel, string $body): string
    {
        $safeTitle = e($title);
        $safePeriod = e($periodLabel);
        $generated = e(LocalTime::now()->format('d.m.Y H:i'));

        return <<<HTML
<!doctype html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <title>{$safeTitle}</title>
  <style>
    body { margin: 0; padding: 18px; font-family: DejaVu Sans, Arial, sans-serif; color: #111; font-size: 11px; }
    h1 { margin: 0 0 4px; font-size: 16px; text-transform: uppercase; }
    .meta { color: #555; margin-bottom: 12px; font-size: 11px; }
    table, table.lines { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #111; padding: 6px 5px; vertical-align: top; }
    th { background: #f3f4f6; text-align: left; font-size: 9px; text-transform: uppercase; }
    td.num, th.num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    tr.grand td, tr.totals td { background: #f9fafb; font-weight: 700; }
    .totals-note { margin-top: 14px; line-height: 1.6; }
    .muted { color: #6b7280; font-size: 10px; }
    .empty { text-align: center; color: #6b7280; }
  </style>
</head>
<body>
  <h1>{$safeTitle}</h1>
  <p class="meta">Perioada: {$safePeriod} · Generat: {$generated} · V CHARGE</p>
  {$body}
</body>
</html>
HTML;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', ' ');
    }

    private function kwh(float $value): string
    {
        return number_format($value, 3, '.', ' ');
    }

    private function int(int $value): string
    {
        return number_format($value, 0, '.', ' ');
    }
}
