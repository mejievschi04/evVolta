<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\WalletTopup;
use Barryvdh\DomPDF\Facade\Pdf;
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
     * @return array{pdf: string, filename: string}
     */
    public function walletTopups(Carbon $from, Carbon $to): array
    {
        $from = LocalTime::of($from)->startOfDay();
        $to = LocalTime::of($to)->endOfDay();
        $rows = $this->topupRows($from, $to);

        $totalAmount = round((float) $rows->sum('amount'), 2);

        $bodyRows = $rows->values()->map(function (array $row, int $index) {
            $userLabel = e($row['user_name']);
            if ($row['user_email'] !== '') {
                $userLabel .= '<br><span class="muted">'.e($row['user_email']).'</span>';
            }

            return sprintf(
                '<tr><td class="center">%d</td><td>%s</td><td>%s</td><td>%s</td><td class="num">%s</td></tr>',
                $index + 1,
                e($row['paid_at']),
                $userLabel,
                e($row['method']),
                $this->money($row['amount'])
            );
        })->implode('');

        if ($bodyRows === '') {
            $bodyRows = '<tr><td colspan="5" class="empty">Nicio alimentare in interval.</td></tr>';
        }

        $byMethod = $rows->groupBy('method')
            ->map(fn (Collection $group, string $method) => sprintf(
                '%s: <strong>%s MDL</strong> (%s)',
                e($method),
                $this->money(round((float) $group->sum('amount'), 2)),
                $this->int($group->count())
            ))
            ->sortKeys()
            ->implode(' · ');
        $methodsNote = $byMethod !== '' ? '<p class="totals-note">'.$byMethod.'</p>' : '';
        $count = $this->int($rows->count());

        $periodLabel = $from->format('d.m.Y') . ' – ' . $to->format('d.m.Y');
        $html = $this->wrapReportHtml(
            'Raport alimentari cont',
            $periodLabel,
            body: <<<HTML
<table>
  <thead>
    <tr>
      <th class="center">Nr.</th>
      <th>Data achitarii</th>
      <th>Client</th>
      <th>Metoda</th>
      <th class="num">Suma (MDL)</th>
    </tr>
  </thead>
  <tbody>
    {$bodyRows}
    <tr class="grand">
      <td colspan="4"><strong>Suma totala ({$count} alimentari)</strong></td>
      <td class="num"><strong>{$this->money($totalAmount)}</strong></td>
    </tr>
  </tbody>
</table>
{$methodsNote}
HTML,
            companyHeader: $this->companyHeader(),
        );

        return [
            'pdf' => Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output(),
            'filename' => sprintf(
                'raport-alimentari-%s-%s.pdf',
                $from->format('Ymd'),
                $to->format('Ymd')
            ),
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
     */
    private function companyHeader(): string
    {
        $seller = app(InvoiceFiscalCalculator::class)->sellerSnapshot();
        $name = e(mb_strtoupper($seller['seller_name'] !== '' ? $seller['seller_name'] : (string) config('legal.company_name', 'Volta SRL')));
        $idno = $seller['seller_idno'] !== '' ? $seller['seller_idno'] : (string) config('legal.company_idno', '');

        return '<div class="company"><strong>'.$name.'</strong>'
            .($idno !== '' ? '<br>Cod fiscal (IDNO): '.e($idno) : '')
            .'</div>';
    }

    private function wrapReportHtml(string $title, string $periodLabel, string $body, string $companyHeader = ''): string
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
    .company { margin-bottom: 12px; font-size: 12px; line-height: 1.5; }
  </style>
</head>
<body>
  {$companyHeader}
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
