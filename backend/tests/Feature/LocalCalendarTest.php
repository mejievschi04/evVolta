<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\WalletTopup;
use App\Services\InvoiceIssuanceService;
use App\Services\ReportDocumentService;
use App\Support\LocalTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Timestamps are stored in UTC; days and months follow Europe/Chisinau (UTC+3 in summer, UTC+2 in winter).
 */
class LocalCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_month_input_does_not_overflow_on_late_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'UTC'));

        $month = LocalTime::month('2026-02');

        $this->assertSame('2026-02-01 00:00:00', $month->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Chisinau', $month->getTimezone()->getName());
    }

    public function test_session_invoice_month_and_period_follow_local_calendar(): void
    {
        $user = $this->createAppUser();
        $station = $this->station();

        // 2026-10-31 22:30 UTC = 2026-11-01 00:30 in Chisinau (UTC+2 after DST ends).
        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-10-31 21:30:00', 'UTC'),
            'end_time' => Carbon::parse('2026-10-31 22:30:00', 'UTC'),
            'kwh_consumed' => 4,
        ]);

        $invoice = app(InvoiceIssuanceService::class)->createSessionInvoice($session, 16.0);

        $this->assertSame('2026-11', $invoice->month);
        $this->assertSame('2026-10-31', $invoice->period_start->toDateString());
        $this->assertSame('2026-11-01', $invoice->period_end->toDateString());
    }

    public function test_topup_report_groups_by_local_day(): void
    {
        $user = $this->createAppUser();

        // 2026-10-14 22:30 UTC = 2026-10-15 01:30 in Chisinau (UTC+3).
        $lateNight = $this->paidTopup($user->id, '2026-10-14 22:30:00');
        // 2026-10-14 20:30 UTC = 2026-10-14 23:30 in Chisinau: previous local day.
        $this->paidTopup($user->id, '2026-10-14 20:30:00');

        $rows = $this->topupRows(LocalTime::day('2026-10-15'), LocalTime::day('2026-10-15')->endOfDay());

        $this->assertCount(1, $rows);
        $this->assertSame('15.10.2026 01:30', $rows[0]['paid_at']);
        $this->assertSame((float) $lateNight->amount, $rows[0]['amount']);
    }

    public function test_dashboard_today_uses_local_day(): void
    {
        // 2026-10-15 22:30 UTC = 2026-10-16 01:30 in Chisinau.
        Carbon::setTestNow(Carbon::parse('2026-10-15 22:30:00', 'UTC'));

        $admin = $this->createAdminUser();
        $user = $this->createAppUser();
        $station = $this->station();

        // Local 2026-10-16 01:00 -> today.
        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-10-15 22:00:00', 'UTC'),
        ]);
        // Local 2026-10-15 23:00 -> yesterday, although it is the same UTC date.
        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-10-15 20:00:00', 'UTC'),
            'end_time' => Carbon::parse('2026-10-15 20:30:00', 'UTC'),
        ]);

        $this->withSession(['backoffice_user_id' => $admin->id])
            ->getJson('/backoffice/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.sessionsToday', 1)
            ->assertJsonPath('period.to', '2026-10-16');
    }

    private function station(): Station
    {
        return Station::query()->create([
            'name' => 'VOLTA TZ',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:tz-'.uniqid(),
        ]);
    }

    private function paidTopup(int $userId, string $paidAtUtc): WalletTopup
    {
        return WalletTopup::query()->create([
            'user_id' => $userId,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'maib',
            'paid_at' => Carbon::parse($paidAtUtc, 'UTC'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topupRows(Carbon $from, Carbon $to): array
    {
        $method = new \ReflectionMethod(ReportDocumentService::class, 'topupRows');

        return $method->invoke(app(ReportDocumentService::class), $from, $to)->all();
    }
}
