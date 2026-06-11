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

    public function test_session_invoice_is_generated_when_a_charging_session_is_closed(): void
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

        $invoice = app(BillingService::class)->createSessionInvoice($session);

        $this->assertSame($user->id, $invoice->user_id);
        $this->assertSame('session', $invoice->invoice_type);
        $this->assertSame('EVS-' . $session->id, $invoice->invoice_number);
        $this->assertSame($session->id, $invoice->source_session_id);
        $this->assertSame('2026-04', $invoice->month);
        $this->assertSame('2026-04-16', $invoice->period_start?->toDateString());
        $this->assertSame('2026-04-16', $invoice->period_end?->toDateString());
        $this->assertSame(1, $invoice->sessions_count);
        $this->assertEquals(6.00, $invoice->total_kwh);
        $this->assertEquals(3.00, $invoice->total_amount);
    }

    public function test_multiple_session_invoices_can_exist_for_the_same_user_in_the_same_month(): void
    {
        $user = $this->createAppUser([
            'name' => 'Public Client',
            'email' => 'client@example.test',
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

        $firstSession = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-04-16 09:00:00'),
            'end_time' => Carbon::parse('2026-04-16 09:20:00'),
            'kwh_consumed' => 6.00,
        ]);

        $secondSession = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-04-18 10:00:00'),
            'end_time' => Carbon::parse('2026-04-18 10:45:00'),
            'kwh_consumed' => 8.40,
        ]);

        $firstInvoice = app(BillingService::class)->createSessionInvoice($firstSession);
        $secondInvoice = app(BillingService::class)->createSessionInvoice($secondSession);

        $this->assertSame(2, Invoice::query()->count());
        $this->assertSame($firstSession->id, $firstInvoice->source_session_id);
        $this->assertSame($secondSession->id, $secondInvoice->source_session_id);
        $this->assertSame('session', $firstInvoice->invoice_type);
        $this->assertSame('session', $secondInvoice->invoice_type);
    }
}
