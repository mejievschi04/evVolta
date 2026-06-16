<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackofficeWalletTopupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_lists_wallet_topups_with_summary(): void
    {
        $admin = $this->createAdminUser();
        $customer = $this->createAppUser([
            'name' => 'Wallet Client',
            'email' => 'wallet.client@example.test',
            'wallet_balance' => 250,
        ]);

        WalletTopup::query()->create([
            'user_id' => $customer->id,
            'amount' => 150,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'stripe',
            'payment_session_id' => 'cs_test_paid_1',
            'paid_at' => now(),
        ]);

        WalletTopup::query()->create([
            'user_id' => $customer->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'stripe',
            'payment_session_id' => 'cs_test_pending_1',
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson('/backoffice/wallet-topups')
            ->assertOk()
            ->assertJsonPath('summary.count_paid', 1)
            ->assertJsonPath('summary.count_pending', 1)
            ->assertJsonPath('summary.volume_paid', 150)
            ->assertJsonPath('summary.volume_pending', 100)
            ->assertJsonPath('summary.refunds_count', 0)
            ->assertJsonPath('summary.volume_refunded', 0)
            ->assertJsonPath('data.0.user.email', 'wallet.client@example.test')
            ->assertJsonPath('refunds', []);
    }

    public function test_customer_detail_includes_wallet_topups(): void
    {
        $admin = $this->createAdminUser();
        $customer = $this->createAppUser([
            'email' => 'detail.wallet@example.test',
        ]);

        WalletTopup::query()->create([
            'user_id' => $customer->id,
            'amount' => 200,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'stripe',
            'paid_at' => now(),
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson('/backoffice/users/' . $customer->id)
            ->assertOk()
            ->assertJsonPath('data.wallet_topups.0.amount', 200)
            ->assertJsonPath('data.wallet_topups.0.status', 'paid');
    }

    public function test_personal_user_detail_includes_wallet_sections(): void
    {
        $admin = $this->createAdminUser();
        $personal = $this->createPersonalUser([
            'email' => 'personal.wallet@example.test',
        ]);

        WalletTopup::query()->create([
            'user_id' => $personal->id,
            'amount' => 300,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'local',
            'paid_at' => now(),
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson('/backoffice/users/' . $personal->id)
            ->assertOk()
            ->assertJsonPath('data.wallet_topups.0.amount', 300)
            ->assertJsonPath('data.wallet_summary.topups_paid_total', 300)
            ->assertJsonPath('data.wallet_refunds', []);
    }

    public function test_wallet_topups_lists_refunds_after_backoffice_refund(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

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
            ->postJson('/backoffice/wallet-topups/' . $topup->id . '/refund', ['amount' => 50])
            ->assertOk();

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson('/backoffice/wallet-topups')
            ->assertOk()
            ->assertJsonPath('summary.refunds_count', 1)
            ->assertJsonPath('summary.volume_refunded', 50)
            ->assertJsonPath('refunds.0.amount', 50)
            ->assertJsonPath('refunds.0.user.email', $customer->email);
    }
}
