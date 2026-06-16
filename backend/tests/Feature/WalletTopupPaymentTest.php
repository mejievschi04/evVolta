<?php

namespace Tests\Feature;

use App\Models\WalletTopup;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WalletTopupPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.prepaid_wallet_enabled' => true]);
    }

    public function test_verify_wallet_topup_credits_balance_when_stripe_is_paid(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 50]);

        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'stripe',
            'payment_session_id' => 'cs_test_wallet_1',
        ]);

        $stripe = Mockery::mock(StripePaymentService::class);
        $stripe->shouldReceive('retrieveCheckoutSession')
            ->once()
            ->with('cs_test_wallet_1')
            ->andReturn([
                'id' => 'cs_test_wallet_1',
                'payment_status' => 'paid',
                'status' => 'complete',
                'payment_intent' => 'pi_test_wallet_1',
            ]);
        $this->app->instance(StripePaymentService::class, $stripe);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/topups/' . $topup->id . '/verify-payment')
            ->assertOk()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('topup.status', 'paid')
            ->assertJsonPath('wallet_balance', 150);

        $this->assertSame(150.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame('pi_test_wallet_1', $topup->fresh()->payment_intent_id);
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'wallet_topup_id' => $topup->id,
            'invoice_type' => 'wallet_topup',
            'status' => 'paid',
            'total_amount' => 100,
        ]);
    }

    public function test_verify_wallet_topup_is_idempotent_when_already_paid(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 200]);

        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'stripe',
            'payment_session_id' => 'cs_test_wallet_paid',
            'paid_at' => now(),
        ]);

        $stripe = Mockery::mock(StripePaymentService::class);
        $stripe->shouldNotReceive('retrieveCheckoutSession');
        $this->app->instance(StripePaymentService::class, $stripe);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/topups/' . $topup->id . '/verify-payment')
            ->assertOk()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('wallet_balance', 200);
    }

    public function test_verify_wallet_topup_forbidden_for_other_user(): void
    {
        $owner = $this->createAppUser();
        $other = $this->createAppUser();

        $topup = WalletTopup::query()->create([
            'user_id' => $owner->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'stripe',
            'payment_session_id' => 'cs_test_wallet_2',
        ]);

        $this->actingAs($other, 'api')
            ->postJson('/api/wallet/topups/' . $topup->id . '/verify-payment')
            ->assertForbidden();
    }
}
