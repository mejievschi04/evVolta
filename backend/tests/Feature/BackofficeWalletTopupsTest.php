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
            ->assertJsonPath('data.0.user.email', 'wallet.client@example.test');
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

    public function test_personal_user_detail_has_no_wallet_topups(): void
    {
        $admin = $this->createAdminUser();
        $personal = $this->createPersonalUser([
            'email' => 'personal.no.wallet@example.test',
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson('/backoffice/users/' . $personal->id)
            ->assertOk()
            ->assertJsonPath('data.wallet_topups', []);
    }
}
