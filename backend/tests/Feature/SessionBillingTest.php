<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Station;
use App\Models\Tariff;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sessions_api_returns_billing_and_invoice_for_closed_session(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        Tariff::query()->create([
            'price_per_kwh' => 0.50,
            'personal_price_per_kwh' => 0.20,
        ]);

        $user = $this->createAppUser([
            'email' => 'session-billing@example.test',
            'wallet_balance' => 100,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:volta-1',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-06-10 08:00:00'),
            'end_time' => Carbon::parse('2026-06-10 08:30:00'),
            'kwh_consumed' => 6,
            'charge_budget' => 10,
        ]);

        app(BillingService::class)->finalizeBillingForSession($session);

        $this->actingAs($user, 'api')
            ->getJson('/api/sessions')
            ->assertOk()
            ->assertJsonPath('0.billing.amount_spent', 3)
            ->assertJsonPath('0.billing.currency', 'MDL')
            ->assertJsonPath('0.invoice.invoice_type', 'session')
            ->assertJsonPath('0.invoice.total_amount', 3)
            ->assertJsonPath('0.invoice.total_kwh', 6);
    }

    public function test_ensure_session_invoice_does_not_double_settle_wallet(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $user = $this->createAppUser([
            'email' => 'session-backfill@example.test',
            'wallet_balance' => 90,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 2',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:volta-2',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-06-10 08:00:00'),
            'end_time' => Carbon::parse('2026-06-10 08:30:00'),
            'kwh_consumed' => 4,
            'charge_budget' => 10,
        ]);

        app(BillingService::class)->finalizeBillingForSession($session);
        $balanceAfterBilling = (float) $user->fresh()->wallet_balance;

        Invoice::query()->where('source_session_id', $session->id)->delete();

        app(BillingService::class)->ensureSessionInvoice($session->fresh());

        $this->assertEquals($balanceAfterBilling, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('invoices', [
            'source_session_id' => $session->id,
            'invoice_type' => 'session',
            'total_amount' => 2,
        ]);
    }
}
