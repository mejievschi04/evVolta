<?php

namespace Tests\Feature;

use App\Models\WalletTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTopupsHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_own_wallet_topups(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        $user = $this->createAppUser(['email' => 'customer@example.test']);
        $other = $this->createAppUser(['email' => 'other@example.test']);

        WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 150,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'stripe',
            'paid_at' => now()->subDay(),
        ]);

        WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 80,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'stripe',
        ]);

        WalletTopup::query()->create([
            'user_id' => $other->id,
            'amount' => 500,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'stripe',
            'paid_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/wallet/topups')
            ->assertOk()
            ->assertJsonCount(2, 'topups')
            ->assertJsonPath('summary.paid_count', 1)
            ->assertJsonPath('summary.pending_count', 1)
            ->assertJsonPath('summary.total_credited', 150)
            ->assertJsonPath('topups.0.amount', 80);
    }

    public function test_personal_user_can_list_wallet_topups(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        $personal = $this->createPersonalUser(['email' => 'personal@example.test']);

        WalletTopup::query()->create([
            'user_id' => $personal->id,
            'amount' => 200,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'stripe',
            'paid_at' => now(),
        ]);

        $this->actingAs($personal, 'api')
            ->getJson('/api/wallet/topups')
            ->assertOk()
            ->assertJsonCount(1, 'topups')
            ->assertJsonPath('topups.0.amount', 200);
    }
}
