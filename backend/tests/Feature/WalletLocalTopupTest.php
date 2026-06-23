<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\WalletTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletLocalTopupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.prepaid_wallet_enabled' => true,
            'billing.wallet_dev_topup_enabled' => true,
            'billing.wallet_dev_topup_max_amount' => 1000,
            'billing.wallet_dev_topup_daily_limit' => 5000,
        ]);
    }

    public function test_wallet_endpoint_exposes_dev_topup_flag(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);

        $this->actingAs($user, 'api')
            ->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonPath('dev_topup_enabled', true)
            ->assertJsonPath('dev_topup_max_amount', 1000)
            ->assertJsonPath('dev_topup_daily_remaining', 5000);
    }

    public function test_local_topup_credits_wallet_balance(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 50]);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/local-topup', ['amount' => 200])
            ->assertOk()
            ->assertJsonPath('credited', 200)
            ->assertJsonPath('wallet_balance', 250);

        $this->assertSame(250.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_local_topup_writes_audit_log(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/local-topup', ['amount' => 150])
            ->assertOk();

        $auditLog = AuditLog::query()->where('action', 'wallet.dev_topup')->first();

        $this->assertNotNull($auditLog);
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(150.0, (float) ($auditLog->metadata['credited'] ?? 0));
    }

    public function test_local_topup_rejects_amount_above_max(): void
    {
        $user = $this->createAppUser();

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/local-topup', ['amount' => 1500])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_local_topup_rejects_when_daily_limit_exceeded(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);

        WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 4600,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'local',
            'paid_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/local-topup', ['amount' => 500])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Limita zilnica de alimentare test este 5000.00 MDL (ramas 400.00 MDL).');
    }

    public function test_local_topup_is_hidden_when_dev_flag_is_off(): void
    {
        config(['billing.wallet_dev_topup_enabled' => false]);
        app()->detectEnvironment(fn () => 'production');

        $user = $this->createAppUser();

        $this->actingAs($user, 'api')
            ->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonPath('dev_topup_enabled', false);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/local-topup', ['amount' => 100])
            ->assertNotFound();
    }
}
