<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use App\Services\BillingService;
use App\Services\WalletService;
use Carbon\Carbon;
use App\Models\WalletTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_invoice_job_is_disabled_for_prepaid_billing(): void
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
            'start_time' => Carbon::parse('2026-04-05 08:00:00'),
            'end_time' => Carbon::parse('2026-04-05 09:00:00'),
            'kwh_consumed' => 3.00,
        ]);

        $count = app(BillingService::class)->generateMonthlyInvoices(Carbon::parse('2026-04-01'));

        $this->assertSame(0, $count);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_finalize_billing_creates_session_invoice_when_session_closes(): void
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

        $this->assertNotNull($invoice);
        $this->assertSame('session', $invoice->invoice_type);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(3.0, (float) $invoice->total_amount);
        $this->assertSame(6.0, (float) $invoice->total_kwh);
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'source_session_id' => $session->id,
            'invoice_type' => 'session',
        ]);
    }

    public function test_finalize_billing_settles_wallet_for_personal_users(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        $user = $this->createPersonalUser([
            'email' => 'personal-wallet@example.test',
            'wallet_balance' => 100,
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

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-04-16 09:00:00'),
            'end_time' => Carbon::parse('2026-04-16 09:20:00'),
            'kwh_consumed' => 6.00,
        ]);

        app(WalletService::class)->holdBudgetForSession($user, $session, 10);
        $invoice = app(BillingService::class)->finalizeBillingForSession($session->fresh());

        $this->assertEquals(97.0, (float) $user->fresh()->wallet_balance);
        $this->assertNotNull($invoice);
        $this->assertSame('session', $invoice->invoice_type);
    }

    public function test_credit_topup_creates_wallet_invoice(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        $user = $this->createAppUser(['wallet_balance' => 0]);

        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 250,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'stripe',
            'payment_session_id' => 'cs_test_topup_invoice',
        ]);

        app(WalletService::class)->creditTopup($topup->fresh(), 'cs_test_topup_invoice', 'pi_test_topup_invoice');

        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'wallet_topup_id' => $topup->id,
            'invoice_type' => 'wallet_topup',
            'status' => 'paid',
            'total_amount' => 250,
        ]);
    }
}
