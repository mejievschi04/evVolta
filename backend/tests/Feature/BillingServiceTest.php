<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_billing_uses_completed_sessions_that_end_in_the_target_month(): void
    {
        $user = $this->createPersonalUser([
            'name' => 'Fleet Driver',
            'email' => 'fleet@example.test',
        ]);

        $station = Station::query()->create([
            'name' => 'Depot A1',
            'location' => 'Private Depot',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:depot-a1',
        ]);

        Tariff::query()->create([
            'price_per_kwh' => 0.50,
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-03-31 23:50:00'),
            'end_time' => Carbon::parse('2026-04-01 00:10:00'),
            'kwh_consumed' => 5.50,
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-04-05 08:00:00'),
            'end_time' => Carbon::parse('2026-04-05 09:00:00'),
            'kwh_consumed' => 3.00,
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-03-10 08:00:00'),
            'end_time' => Carbon::parse('2026-03-10 09:00:00'),
            'kwh_consumed' => 2.00,
        ]);

        $count = app(BillingService::class)->generateMonthlyInvoices(Carbon::parse('2026-04-01'));

        $this->assertSame(1, $count);

        $invoice = Invoice::query()->firstOrFail();

        $this->assertSame($user->id, $invoice->user_id);
        $this->assertSame('2026-04', $invoice->month);
        $this->assertSame('2026-04-01', $invoice->period_start?->toDateString());
        $this->assertSame('2026-04-30', $invoice->period_end?->toDateString());
        $this->assertSame(2, $invoice->sessions_count);
        $this->assertEquals(8.50, $invoice->total_kwh);
        $this->assertEquals(4.25, $invoice->total_amount);
    }

    public function test_finalize_billing_does_not_create_invoice_when_session_closes(): void
    {
        $user = $this->createAppUser([
            'name' => 'Public Client',
            'email' => 'client@example.test',
        ]);

        $station = Station::query()->create([
            'name' => 'Depot B1',
            'location' => 'Private Depot',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:depot-b1',
        ]);

        Tariff::query()->create([
            'price_per_kwh' => 0.50,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-04-16 09:00:00'),
            'end_time' => Carbon::parse('2026-04-16 09:20:00'),
            'kwh_consumed' => 6.00,
        ]);

        $invoice = app(BillingService::class)->finalizeBillingForSession($session);

        $this->assertNull($invoice);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_monthly_job_creates_one_invoice_for_multiple_sessions_in_same_month(): void
    {
        $user = $this->createPersonalUser([
            'name' => 'Fleet Driver',
            'email' => 'fleet-monthly@example.test',
        ]);

        $station = Station::query()->create([
            'name' => 'Depot C1',
            'location' => 'Private Depot',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:depot-c1',
        ]);

        Tariff::query()->create([
            'price_per_kwh' => 0.50,
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-04-16 09:00:00'),
            'end_time' => Carbon::parse('2026-04-16 09:20:00'),
            'kwh_consumed' => 6.00,
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-04-18 10:00:00'),
            'end_time' => Carbon::parse('2026-04-18 10:45:00'),
            'kwh_consumed' => 8.40,
        ]);

        app(BillingService::class)->generateMonthlyInvoices(Carbon::parse('2026-04-01'));

        $this->assertSame(1, Invoice::query()->count());
        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('monthly', $invoice->invoice_type);
        $this->assertSame(2, $invoice->sessions_count);
        $this->assertEquals(14.40, $invoice->total_kwh);
        $this->assertEquals(7.20, $invoice->total_amount);
    }
}
