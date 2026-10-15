<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\Tariff;
use App\Services\BillingService;
use App\Services\TariffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionPriceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.prepaid_wallet_enabled' => true]);
        Tariff::query()->create(['price_per_kwh' => 5.00, 'personal_price_per_kwh' => 5.00]);
    }

    public function test_tariff_change_mid_session_does_not_apply_retroactively(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $session = $this->openSession($user->id, snapshot: app(TariffService::class)->snapshotPriceForNewSession($user));

        // Operator raises the price while the car is charging.
        Tariff::query()->create(['price_per_kwh' => 8.00, 'personal_price_per_kwh' => 8.00]);
        $session->update(['end_time' => now(), 'kwh_consumed' => 10]);

        $invoice = app(BillingService::class)->finalizeBillingForSession($session->fresh());

        $this->assertSame(50.0, (float) $invoice->total_amount);
        $this->assertSame(50.0, (float) $user->fresh()->wallet_balance, 'Unused budget (100 - 50) returned');
    }

    public function test_session_without_snapshot_uses_current_tariff(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $session = $this->openSession($user->id, snapshot: null);

        Tariff::query()->create(['price_per_kwh' => 8.00, 'personal_price_per_kwh' => 8.00]);
        $session->update(['end_time' => now(), 'kwh_consumed' => 10]);

        $invoice = app(BillingService::class)->finalizeBillingForSession($session->fresh());

        $this->assertSame(80.0, (float) $invoice->total_amount);
    }

    private function openSession(int $userId, ?float $snapshot): ChargingSession
    {
        $station = Station::query()->create([
            'name' => 'VOLTA PRICE',
            'location' => 'Chisinau',
            'status' => 'available',
        ]);

        return ChargingSession::query()->create([
            'user_id' => $userId,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'start_time' => now()->subHour(),
            'kwh_consumed' => 0,
            'charge_budget' => 100,
            'price_per_kwh' => $snapshot,
        ]);
    }
}
