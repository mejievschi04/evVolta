<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletLocalTopupTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_endpoint_does_not_expose_dev_topup(): void
    {
        config([
            'billing.prepaid_wallet_enabled' => true,
        ]);

        $user = $this->createAppUser(['wallet_balance' => 0]);

        $this->actingAs($user, 'api')
            ->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonMissingPath('dev_topup_enabled')
            ->assertJsonMissingPath('dev_topup_max_amount')
            ->assertJsonMissingPath('dev_topup_daily_remaining');
    }

    public function test_local_topup_route_is_not_available(): void
    {
        config([
            'billing.prepaid_wallet_enabled' => true,
        ]);

        $user = $this->createAppUser(['wallet_balance' => 50]);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/local-topup', ['amount' => 200])
            ->assertNotFound();

        $this->assertSame(50.0, (float) $user->fresh()->wallet_balance);
    }
}
