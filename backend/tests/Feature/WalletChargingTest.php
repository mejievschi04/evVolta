<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Models\WalletTopup;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WalletChargingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('billing.prepaid_wallet_enabled', true);
    }

    public function test_customer_can_start_without_budget_when_prepaid_disabled(): void
    {
        Config::set('billing.prepaid_wallet_enabled', false);

        $user = $this->createAppUser(['wallet_balance' => 0]);
        $station = $this->walletStation();

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
            ])
            ->assertCreated();
    }

    private function walletStation(array $overrides = []): Station
    {
        return Station::query()->create(array_merge([
            'name' => 'Wallet station',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'wallet-station-' . uniqid(),
            'ocpp_identity' => 'wallet-' . uniqid(),
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                ],
            ],
        ], $overrides));
    }

    public function test_customer_cannot_start_without_budget(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 200]);
        $station = $this->walletStation();

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
            ])
            ->assertStatus(422);
    }

    public function test_customer_cannot_start_with_insufficient_wallet(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 20]);
        $station = $this->walletStation();

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
                'budget_amount' => 100,
            ])
            ->assertStatus(422);
    }

    public function test_customer_start_holds_budget_from_wallet(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 300]);
        $station = $this->walletStation();

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
                'budget_amount' => 100,
            ])
            ->assertCreated()
            ->assertJsonPath('session.charge_budget', 100);

        $this->assertSame(200.0, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('charging_sessions', [
            'user_id' => $user->id,
            'charge_budget' => 100,
        ]);
    }

    public function test_wallet_topup_checkout_requires_customer_account(): void
    {
        $user = $this->createPersonalUser();

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/topup-checkout', ['amount' => 100])
            ->assertStatus(422);
    }

    public function test_wallet_service_credits_topup_once(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);

        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 150,
            'currency' => 'MDL',
            'status' => 'pending',
        ]);

        app(WalletService::class)->creditTopup($topup);
        app(WalletService::class)->creditTopup($topup->fresh());

        $this->assertSame(150.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame('paid', $topup->fresh()->status);
    }

    public function test_personal_account_can_start_without_budget(): void
    {
        $user = $this->createPersonalUser();
        $station = $this->walletStation(['qr_code' => 'personal-station']);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('charging_sessions', [
            'user_id' => $user->id,
            'charge_budget' => null,
        ]);
    }

    public function test_settle_session_refunds_unused_budget(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => Station::query()->create([
                'name' => 'Settle station',
                'location' => 'Test',
                'status' => Station::STATUS_CHARGING,
                'qr_code' => 'settle-station',
            ])->id,
            'start_time' => now()->subMinutes(10),
            'end_time' => now(),
            'kwh_consumed' => 5,
            'charge_budget' => 100,
        ]);

        $charged = app(WalletService::class)->settleSession($session, 0.20);

        $this->assertSame(1.0, $charged);
        $this->assertSame(99.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_customer_can_start_with_target_kwh(): void
    {
        Config::set('billing.price_per_kwh', 0.20);

        $user = $this->createAppUser(['wallet_balance' => 300]);
        $station = $this->walletStation();

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
                'target_kwh' => 50,
            ])
            ->assertCreated()
            ->assertJsonPath('session.target_kwh', 50)
            ->assertJsonPath('session.charge_budget', 10);

        $this->assertSame(290.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_customer_cannot_start_with_both_budget_and_target_kwh(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 300]);
        $station = $this->walletStation();

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
                'budget_amount' => 100,
                'target_kwh' => 20,
            ])
            ->assertStatus(422);
    }

    public function test_wallet_endpoint_exposes_charge_options(): void
    {
        Config::set('billing.price_per_kwh', 0.20);

        $user = $this->createAppUser(['wallet_balance' => 200]);

        $this->actingAs($user, 'api')
            ->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonPath('charge_options.price_per_kwh', 0.2)
            ->assertJsonPath('charge_options.min_target_kwh', 50)
            ->assertJsonPath('charge_options.suggested_budgets', [50, 100, 200, 500])
            ->assertJsonPath('charge_options.suggested_kwh', [10, 20, 30, 50]);
    }

    public function test_should_stop_when_target_kwh_is_reached(): void
    {
        $session = ChargingSession::query()->create([
            'user_id' => User::factory()->create()->id,
            'station_id' => Station::query()->create([
                'name' => 'Stop station',
                'location' => 'Test',
                'status' => Station::STATUS_CHARGING,
                'qr_code' => 'stop-station',
            ])->id,
            'start_time' => now()->subMinutes(5),
            'kwh_consumed' => 20,
            'target_kwh' => 20,
            'charge_budget' => 4,
            'live_metrics' => [
                'energy_integrated_kwh' => 20,
            ],
        ]);

        $this->assertTrue(app(WalletService::class)->shouldStopForBudget($session, 0.20));
    }
}
