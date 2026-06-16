<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Models\WalletRefund;
use App\Models\WalletTopup;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.prepaid_wallet_enabled' => true]);
    }

    public function test_backoffice_refund_reduces_wallet_balance(): void
    {
        $admin = $this->createAdminUser();
        $customer = $this->createAppUser(['wallet_balance' => 200]);

        $topup = WalletTopup::query()->create([
            'user_id' => $customer->id,
            'amount' => 500,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'local',
            'paid_at' => now(),
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/wallet-topups/' . $topup->id . '/refund', ['amount' => 100])
            ->assertOk()
            ->assertJsonPath('user_wallet_balance', 100)
            ->assertJsonPath('topup.amount_refunded', 100);

        $this->assertSame(100.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(1, WalletRefund::query()->where('wallet_topup_id', $topup->id)->count());
    }

    public function test_api_wallet_refund_route_is_not_available(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 100]);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/refund', ['amount' => 50])
            ->assertNotFound();
    }

    public function test_cannot_refund_more_than_topup_remaining(): void
    {
        $admin = $this->createAdminUser();
        $customer = $this->createAppUser(['wallet_balance' => 300]);

        $topup = WalletTopup::query()->create([
            'user_id' => $customer->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'local',
            'paid_at' => now(),
            'amount_refunded' => 0,
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/wallet-topups/' . $topup->id . '/refund', ['amount' => 150])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Suma depaseste restul nereturnat din aceasta alimentare.');
    }

    public function test_cannot_refund_during_active_session(): void
    {
        $admin = $this->createAdminUser();
        $customer = $this->createAppUser(['wallet_balance' => 100]);

        $topup = WalletTopup::query()->create([
            'user_id' => $customer->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'local',
            'paid_at' => now(),
        ]);

        ChargingSession::query()->create([
            'user_id' => $customer->id,
            'station_id' => Station::query()->create([
                'name' => 'Active station',
                'location' => 'Test',
                'status' => Station::STATUS_CHARGING,
                'qr_code' => 'active-station',
            ])->id,
            'start_time' => now()->subMinutes(5),
            'kwh_consumed' => 1,
            'charge_budget' => 50,
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/wallet-topups/' . $topup->id . '/refund', ['amount' => 50])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Clientul are o incarcare activa. Opreste sesiunea inainte de retur.');
    }

    public function test_wallet_topups_list_includes_refundable_amount(): void
    {
        $admin = $this->createAdminUser();
        $customer = $this->createAppUser(['wallet_balance' => 150]);

        WalletTopup::query()->create([
            'user_id' => $customer->id,
            'amount' => 200,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'local',
            'paid_at' => now(),
            'amount_refunded' => 50,
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson('/backoffice/wallet-topups')
            ->assertOk()
            ->assertJsonPath('data.0.amount_refunded', 50)
            ->assertJsonPath('data.0.refundable_amount', 150);
    }

    public function test_backoffice_partial_refund(): void
    {
        $admin = $this->createAdminUser();
        $customer = $this->createAppUser(['wallet_balance' => 300]);

        $topup = WalletTopup::query()->create([
            'user_id' => $customer->id,
            'amount' => 500,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'local',
            'paid_at' => now(),
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/wallet-topups/' . $topup->id . '/refund', ['amount' => 75.5])
            ->assertOk()
            ->assertJsonPath('topup.amount_refunded', 75.5)
            ->assertJsonPath('user_wallet_balance', 224.5);

        $this->assertSame(224.5, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(75.5, (float) $topup->fresh()->amount_refunded);
    }

    public function test_refundable_balance_uses_wallet_service(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 80]);

        WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'local',
            'paid_at' => now(),
            'amount_refunded' => 0,
        ]);

        $this->assertSame(80.0, app(WalletService::class)->refundableBalance($user));
    }
}
