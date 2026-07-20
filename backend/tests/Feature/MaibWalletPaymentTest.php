<?php

namespace Tests\Feature;

use App\Models\WalletTopup;
use App\Services\MaibPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MaibWalletPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.prepaid_wallet_enabled' => true,
            'services.payment.provider' => 'maib',
            'services.maib.project_id' => 'test-project',
            'services.maib.project_secret' => 'test-secret',
            'services.maib.signature_key' => '8508706b-3454-4733-8295-56e617c4abcf',
            'services.maib.base_url' => 'https://api.maibmerchants.md/v1',
            'services.maib.language' => 'ro',
            'services.stripe.secret' => null,
        ]);
    }

    public function test_create_topup_checkout_returns_maib_pay_url(): void
    {
        Http::fake([
            'https://api.maibmerchants.md/v1/generate-token' => Http::response([
                'ok' => true,
                'result' => [
                    'accessToken' => 'access-token',
                    'expiresIn' => 300,
                    'refreshToken' => 'refresh-token',
                    'refreshExpiresIn' => 1800,
                    'tokenType' => 'Bearer',
                ],
            ]),
            'https://api.maibmerchants.md/v1/pay' => Http::response([
                'ok' => true,
                'result' => [
                    'payId' => 'f16a9006-128a-46bc-8e2a-77a6ee99df75',
                    'orderId' => 'wallet-topup-1',
                    'payUrl' => 'https://maib.ecommerce.md/pay/test',
                ],
            ]),
        ]);

        $user = $this->createAppUser(['wallet_balance' => 0]);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/topup-checkout', ['amount' => 100])
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://maib.ecommerce.md/pay/test')
            ->assertJsonPath('payment_provider', 'maib');

        $topup = WalletTopup::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($topup);
        $this->assertSame('maib', $topup->payment_provider);
        $this->assertSame('f16a9006-128a-46bc-8e2a-77a6ee99df75', $topup->payment_session_id);
        $this->assertSame('pending', $topup->status);
    }

    public function test_callback_with_valid_signature_credits_wallet_once(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 20]);
        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'maib',
            'payment_session_id' => 'f16a9006-128a-46bc-8e2a-77a6ee99df75',
        ]);

        $result = [
            'payId' => 'f16a9006-128a-46bc-8e2a-77a6ee99df75',
            'orderId' => 'wallet-topup-'.$topup->id,
            'status' => 'OK',
            'statusCode' => '000',
            'statusMessage' => 'Approved',
            'threeDs' => 'AUTHENTICATED',
            'rrn' => '331711380059',
            'approval' => '327593',
            'cardNumber' => '510218******1124',
            'amount' => 100,
            'currency' => 'MDL',
        ];

        $signature = app(MaibPaymentService::class)->buildSignature($result);

        $this->postJson('/api/maib/callback', [
            'result' => $result,
            'signature' => $signature,
        ])->assertOk()->assertJsonPath('matched', true);

        $this->assertSame(120.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame('paid', $topup->fresh()->status);
        $this->assertSame('331711380059', $topup->fresh()->payment_intent_id);

        // Idempotent second callback
        $this->postJson('/api/maib/callback', [
            'result' => $result,
            'signature' => $signature,
        ])->assertOk();

        $this->assertSame(120.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_callback_with_invalid_signature_is_rejected(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 50,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'maib',
            'payment_session_id' => 'pay-invalid-sig',
        ]);

        $this->postJson('/api/maib/callback', [
            'result' => [
                'payId' => 'pay-invalid-sig',
                'orderId' => 'wallet-topup-'.$topup->id,
                'status' => 'OK',
                'amount' => 50,
                'currency' => 'MDL',
            ],
            'signature' => 'invalid-signature',
        ])->assertForbidden();

        $this->assertSame(0.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame('pending', $topup->fresh()->status);
    }

    public function test_verify_payment_fallback_credits_when_maib_status_ok(): void
    {
        Http::fake([
            'https://api.maibmerchants.md/v1/generate-token' => Http::response([
                'ok' => true,
                'result' => [
                    'accessToken' => 'access-token',
                    'expiresIn' => 300,
                    'refreshToken' => 'refresh-token',
                    'refreshExpiresIn' => 1800,
                    'tokenType' => 'Bearer',
                ],
            ]),
            'https://api.maibmerchants.md/v1/pay-info/*' => Http::response([
                'ok' => true,
                'result' => [
                    'payId' => 'pay-verify-1',
                    'orderId' => 'wallet-topup-99',
                    'status' => 'OK',
                    'rrn' => 'rrn-1',
                    'amount' => 80,
                    'currency' => 'MDL',
                ],
            ]),
        ]);

        $user = $this->createAppUser(['wallet_balance' => 10]);
        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 80,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'maib',
            'payment_session_id' => 'pay-verify-1',
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/topups/'.$topup->id.'/verify-payment')
            ->assertOk()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('wallet_balance', 90);

        $this->assertSame('paid', $topup->fresh()->status);
    }

    public function test_signature_matches_maib_documentation_example(): void
    {
        $result = [
            'payId' => 'f16a9006-128a-46bc-8e2a-77a6ee99df75',
            'orderId' => '123',
            'status' => 'OK',
            'statusCode' => '000',
            'statusMessage' => 'Approved',
            'threeDs' => 'AUTHENTICATED',
            'rrn' => '331711380059',
            'approval' => '327593',
            'cardNumber' => '510218******1124',
            'amount' => 10.25,
            'currency' => 'MDL',
        ];

        $signature = app(MaibPaymentService::class)->buildSignature(
            $result,
            '8508706b-3454-4733-8295-56e617c4abcf'
        );

        $this->assertSame('5wHkZvm9lFeXxSeFF0ui2CnAp7pCEFSNmuHYFYJlC0s=', $signature);
    }
}
