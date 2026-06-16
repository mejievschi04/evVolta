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
use Tests\TestCase;

class UserAccountBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_invoice_job_does_not_create_invoices_for_any_app_user(): void
    {
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $personalUser = $this->createPersonalUser(['email' => 'personal@example.test']);
        $customerUser = $this->createAppUser(['email' => 'customer@example.test']);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:volta-1',
        ]);

        foreach ([$personalUser, $customerUser] as $user) {
            ChargingSession::query()->create([
                'user_id' => $user->id,
                'station_id' => $station->id,
                'start_time' => Carbon::parse('2026-04-05 08:00:00'),
                'end_time' => Carbon::parse('2026-04-05 09:00:00'),
                'kwh_consumed' => 4.5,
            ]);
        }

        $created = app(BillingService::class)->generateMonthlyInvoices(Carbon::parse('2026-04-01'));

        $this->assertSame(0, $created);
        $this->assertDatabaseMissing('invoices', [
            'invoice_type' => 'monthly',
        ]);
    }

    public function test_session_stop_creates_per_session_invoice(): void
    {
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $customer = $this->createAppUser(['email' => 'customer@example.test']);
        $personal = $this->createPersonalUser(['email' => 'personal@example.test']);
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:volta-1',
        ]);

        foreach ([$customer, $personal] as $user) {
            $session = ChargingSession::query()->create([
                'user_id' => $user->id,
                'station_id' => $station->id,
                'start_time' => now()->subHour(),
                'end_time' => now(),
                'kwh_consumed' => 6,
            ]);

            $invoice = app(BillingService::class)->finalizeBillingForSession($session);

            $this->assertNotNull($invoice);
            $this->assertSame('session', $invoice->invoice_type);
            $this->assertSame('paid', $invoice->status);
        }

        $this->assertSame(2, Invoice::query()->where('invoice_type', 'session')->count());
    }

    public function test_personal_session_stop_with_zero_kwh_does_not_create_invoice(): void
    {
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $personal = $this->createPersonalUser(['email' => 'personal@example.test']);
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:volta-1',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $personal->id,
            'station_id' => $station->id,
            'start_time' => now()->subHour(),
            'kwh_consumed' => 0,
            'meter_start_kwh' => 50,
        ]);

        $station->update(['meter_value_kwh' => 50]);

        $result = app(\App\Services\ChargingStopService::class)->finalizeStop(
            $session->fresh(),
            $station->fresh(),
            'app',
            null,
            50,
        );

        $this->assertSame('completed', $result['status']);
        $this->assertNull($result['invoice']);
        $this->assertDatabaseMissing('invoices', [
            'user_id' => $personal->id,
        ]);
    }

    public function test_personal_user_cannot_pay_monthly_invoice_from_app(): void
    {
        $personal = $this->createPersonalUser(['email' => 'personal@example.test']);

        $invoice = Invoice::query()->create([
            'user_id' => $personal->id,
            'month' => '2026-04',
            'currency' => 'MDL',
            'invoice_type' => 'monthly',
            'invoice_number' => 'EVM-202604-1',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'total_kwh' => 10,
            'total_amount' => 5,
            'sessions_count' => 2,
            'status' => 'unpaid',
        ]);

        $this->actingAs($personal, 'api')
            ->postJson('/api/invoices/' . $invoice->id . '/checkout-session')
            ->assertForbidden();
    }

    public function test_personal_invoice_index_returns_prepay_statistics(): void
    {
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $personal = $this->createPersonalUser([
            'email' => 'personal@example.test',
            'wallet_balance' => 200,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:volta-1',
        ]);

        ChargingSession::query()->create([
            'user_id' => $personal->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-06-05 08:00:00'),
            'end_time' => Carbon::parse('2026-06-05 09:00:00'),
            'kwh_consumed' => 8,
            'charge_budget' => 5,
        ]);

        $session = ChargingSession::query()->where('user_id', $personal->id)->first();
        app(BillingService::class)->finalizeBillingForSession($session);

        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));

        try {
            $this->actingAs($personal, 'api')
                ->getJson('/api/invoices')
                ->assertOk()
                ->assertJsonPath('summary.billing_model', 'prepay')
                ->assertJsonCount(1, 'invoices')
                ->assertJsonPath('invoices.0.invoice_type', 'session')
                ->assertJsonPath('statistics.wallet_balance', 200)
                ->assertJsonPath('statistics.lifetime.sessions_count', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_customer_invoice_index_returns_invoices_and_usage_statistics(): void
    {
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $customer = $this->createAppUser([
            'email' => 'customer@example.test',
            'wallet_balance' => 120,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:volta-1',
        ]);

        $sessionOne = ChargingSession::query()->create([
            'user_id' => $customer->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-06-05 08:00:00'),
            'end_time' => Carbon::parse('2026-06-05 09:00:00'),
            'kwh_consumed' => 10,
            'charge_budget' => 8,
        ]);

        $sessionTwo = ChargingSession::query()->create([
            'user_id' => $customer->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-05-12 08:00:00'),
            'end_time' => Carbon::parse('2026-05-12 09:00:00'),
            'kwh_consumed' => 4,
            'charge_budget' => 5,
        ]);

        app(BillingService::class)->finalizeBillingForSession($sessionOne);
        app(BillingService::class)->finalizeBillingForSession($sessionTwo);

        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));

        try {
            $this->actingAs($customer, 'api')
                ->getJson('/api/invoices')
                ->assertOk()
                ->assertJsonPath('summary.billing_model', 'prepay')
                ->assertJsonCount(2, 'invoices')
                ->assertJsonPath('statistics.wallet_balance', 120)
                ->assertJsonPath('statistics.lifetime.sessions_count', 2)
                ->assertJsonPath('statistics.lifetime.total_kwh', 14)
                ->assertJsonPath('statistics.lifetime.total_spent', 7)
                ->assertJsonPath('statistics.current_month.sessions_count', 1)
                ->assertJsonPath('statistics.current_month.total_kwh', 10)
                ->assertJsonPath('statistics.current_month.total_spent', 5)
                ->assertJsonCount(2, 'statistics.monthly');
        } finally {
            Carbon::setTestNow();
        }
    }
}
