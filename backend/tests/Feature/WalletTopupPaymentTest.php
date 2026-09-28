<?php

namespace Tests\Feature;

use App\Models\WalletTopup;
use App\Services\MaibPaymentService;
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

    public function test_verify_wallet_topup_credits_balance_when_maib_is_paid(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 50]);

        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'maib',
            'payment_session_id' => 'checkout-wallet-1',
        ]);

        $maib = Mockery::mock(MaibPaymentService::class);
        $maib->shouldReceive('getPaymentInfo')
            ->once()
            ->with('checkout-wallet-1')
            ->andReturn([
                'status' => 'Executed',
                'paymentId' => 'pay-wallet-1',
            ]);
        $maib->shouldReceive('isCheckoutPaid')->once()->andReturn(true);
        $maib->shouldReceive('extractPaymentId')->once()->andReturn('pay-wallet-1');
        $this->app->instance(MaibPaymentService::class, $maib);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/topups/' . $topup->id . '/verify-payment')
            ->assertOk()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('topup.status', 'paid')
            ->assertJsonPath('wallet_balance', 150);

        $this->assertSame(150.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame('pay-wallet-1', $topup->fresh()->payment_intent_id);
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
            'payment_provider' => 'maib',
            'payment_session_id' => 'checkout-wallet-paid',
            'paid_at' => now(),
        ]);

        $maib = Mockery::mock(MaibPaymentService::class);
        $maib->shouldNotReceive('getPaymentInfo');
        $this->app->instance(MaibPaymentService::class, $maib);

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
            'payment_provider' => 'maib',
            'payment_session_id' => 'checkout-other',
        ]);

        $this->actingAs($other, 'api')
            ->postJson('/api/wallet/topups/' . $topup->id . '/verify-payment')
            ->assertForbidden();
    }
}
