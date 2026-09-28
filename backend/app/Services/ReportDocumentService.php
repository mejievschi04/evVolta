<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\WalletTopup;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReportDocumentService
{
    /**
     * @return array{pdf: string, filename: string}
     */
    public function stationsDaily(Carbon $day): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $rows = $this->stationAggregates($from, $to);

        $title = 'Raport zilnic pe statii';
        $periodLabel = $day->format('d.m.Y');
        $filename = sprintf('raport-statii-zilnic-%s.pdf', $day->format('Y-m-d'));

        return [
            'pdf' => $this->renderStationsPdf($title, $periodLabel, $rows),
            'filename' => $filename,
        ];
    }

    /**
     * @return array{pdf: string, filename: string}
     */
    public function stationsMonthly(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $rows = $this->stationAggregates($from, $to);

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
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $topups = WalletTopup::query()
            ->with('user:id,name,email')
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->orderBy('paid_at')
            ->get();

        $totalAmount = round((float) $topups->sum('amount'), 2);
        $totalRefunded = round((float) $topups->sum('amount_refunded'), 2);
        $net = round($totalAmount - $totalRefunded, 2);

        $rowsHtml = $topups->map(function (WalletTopup $topup) {
            $paidAt = $topup->paid_at?->format('d.m.Y H:i') ?? '-';
            $name = e($topup->user?->name ?? '-');
            $email = e($topup->user?->email ?? '-');
            $amount = number_format((float) $topup->amount, 2, '.', ' ');
            $refunded = number_format((float) $topup->amount_refunded, 2, '.', ' ');
            $provider = e((string) ($topup->payment_provider ?: '-'));

            return "<tr>
                <td>{$paidAt}</td>
                <td>{$name}<br><span class=\"muted\">{$email}</span></td>
                <td class=\"num\">{$amount}</td>
                <td class=\"num\">{$refunded}</td>
                <td>{$provider}</td>
                <td>paid</td>
            </tr>";
        })->implode('');

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6" class="empty">Nicio alimentare in interval.</td></tr>';
        }

        $periodLabel = $from->format('d.m.Y') . ' – ' . $to->format('d.m.Y');
        $html = $this->wrapReportHtml(
            'Raport alimentari wallet',
            $periodLabel,
            <<<HTML
<table>
  <thead>
    <tr>
      <th>Data</th>
      <th>Utilizator</th>
      <th class="num">Suma (MDL)</th>
      <th class="num">Returnat</th>
      <th>Provider</th>
      <th>Status</th>
    </tr>
  </thead>
  <tbody>
    {$rowsHtml}
  </tbody>
</table>
<div class="totals">
  <p><strong>Total alimentat:</strong> {$this->money($totalAmount)} MDL</p>
  <p><strong>Total returnat:</strong> {$this->money($totalRefunded)} MDL</p>
  <p><strong>Net:</strong> {$this->money($net)} MDL</p>
  <p><strong>Tranzactii:</strong> {$topups->count()}</p>
</div>
HTML
        );

        return [
            'pdf' => Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output(),
            'filename' => sprintf(
                'raport-alimentari-%s-%s.pdf',
                $from->format('Ymd'),
                $to->format('Ymd')
            ),
        ];
    }

    /**
     * @return Collection<int, array{station_id: int|null, station_name: string, sessions_count: int, total_kwh: float, revenue: float}>
     */
    private function stationAggregates(Carbon $from, Carbon $to): Collection
    {
        $sessions = ChargingSession::query()
            ->with(['station:id,name', 'invoice:id,source_session_id,total_amount,invoice_type'])
            ->whereNotNull('end_time')
            ->whereBetween('end_time', [$from, $to])
            ->get();

        $byStation = $sessions->groupBy(fn (ChargingSession $session) => (string) ($session->station_id ?? 0));

        $stationIds = $byStation->keys()->map(fn ($id) => (int) $id)->filter()->values();
        $stationNames = Station::query()
            ->whereIn('id', $stationIds)
            ->pluck('name', 'id');

        return $byStation->map(function (Collection $group, string $stationKey) use ($stationNames) {
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
                'station_id' => $stationId > 0 ? $stationId : null,
                'station_name' => $name,
                'sessions_count' => $group->count(),
                'total_kwh' => round((float) $group->sum('kwh_consumed'), 3),
                'revenue' => $revenue,
            ];
        })->sortBy('station_name')->values();
    }

    /**
     * @param  Collection<int, array{station_id: int|null, station_name: string, sessions_count: int, total_kwh: float, revenue: float}>  $rows
     */
    private function renderStationsPdf(string $title, string $periodLabel, Collection $rows): string
    {
        $totalSessions = (int) $rows->sum('sessions_count');
        $totalKwh = round((float) $rows->sum('total_kwh'), 3);
        $totalRevenue = round((float) $rows->sum('revenue'), 2);

        $bodyRows = $rows->map(function (array $row) {
            return sprintf(
                '<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
                e($row['station_name']),
                number_format($row['sessions_count'], 0, '.', ' '),
                number_format($row['total_kwh'], 3, '.', ' '),
                number_format($row['revenue'], 2, '.', ' ')
            );
        })->implode('');

        if ($bodyRows === '') {
            $bodyRows = '<tr><td colspan="4" class="empty">Nicio sesiune in interval.</td></tr>';
        }

        $html = $this->wrapReportHtml(
            $title,
            $periodLabel,
            <<<HTML
<table>
  <thead>
    <tr>
      <th>Statie</th>
      <th class="num">Sesiuni</th>
      <th class="num">kWh</th>
      <th class="num">Venit facturat (MDL)</th>
    </tr>
  </thead>
  <tbody>
    {$bodyRows}
    <tr class="grand">
      <td><strong>Total</strong></td>
      <td class="num"><strong>{$this->int($totalSessions)}</strong></td>
      <td class="num"><strong>{$this->kwh($totalKwh)}</strong></td>
      <td class="num"><strong>{$this->money($totalRevenue)}</strong></td>
    </tr>
  </tbody>
</table>
HTML
        );

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    private function wrapReportHtml(string $title, string $periodLabel, string $body): string
    {
        $safeTitle = e($title);
        $safePeriod = e($periodLabel);
        $generated = e(now()->format('d.m.Y H:i'));

        return <<<HTML
<!doctype html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <title>{$safeTitle}</title>
  <style>
    body { margin: 0; padding: 24px; font-family: DejaVu Sans, sans-serif; color: #111; font-size: 12px; }
    h1 { margin: 0 0 4px; font-size: 18px; }
    .meta { color: #555; margin-bottom: 16px; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 7px 8px; vertical-align: top; }
    th { background: #f3f4f6; text-align: left; font-size: 10px; text-transform: uppercase; }
    td.num, th.num { text-align: right; white-space: nowrap; }
    tr.grand td { background: #f9fafb; }
    .totals { margin-top: 14px; line-height: 1.6; }
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
