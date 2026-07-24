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
            'services.maib.client_id' => 'test-client',
            'services.maib.client_secret' => 'test-secret',
            'services.maib.signature_key' => '67be8e54-ac28-485d-9369-27f6d3c55a27',
            'services.maib.base_url' => 'https://api.maibmerchants.md',
            'services.maib.language' => 'ro',
            'services.stripe.secret' => null,
        ]);
    }

    public function test_create_topup_checkout_returns_maib_checkout_url(): void
    {
        Http::fake([
            'https://api.maibmerchants.md/v2/auth/token' => Http::response([
                'ok' => true,
                'result' => [
                    'accessToken' => 'access-token',
                    'expiresIn' => 300,
                    'tokenType' => 'Bearer',
                ],
            ]),
            'https://api.maibmerchants.md/v2/checkouts' => Http::response([
                'ok' => true,
                'result' => [
                    'checkoutId' => 'f6d0812a-50ee-47ec-bb3f-d3b3a4dda40d',
                    'checkoutUrl' => 'https://checkout.maib.md/test',
                ],
            ]),
        ]);

        $user = $this->createAppUser(['wallet_balance' => 0]);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/topup-checkout', ['amount' => 100])
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://checkout.maib.md/test')
            ->assertJsonPath('payment_provider', 'maib');

        $topup = WalletTopup::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($topup);
        $this->assertSame('maib', $topup->payment_provider);
        $this->assertSame('f6d0812a-50ee-47ec-bb3f-d3b3a4dda40d', $topup->payment_session_id);
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
            'payment_session_id' => '5a4d27a4-79f5-426b-9403-cccdeee81747',
        ]);

        $payload = [
            'checkoutId' => '5a4d27a4-79f5-426b-9403-cccdeee81747',
            'amount' => 100,
            'currency' => 'MDL',
            'orderId' => 'wallet-topup-'.$topup->id,
            'paymentId' => '379b31a3-8283-43d4-8a7b-eef8c0736a32',
            'paymentAmount' => 100,
            'paymentCurrency' => 'MDL',
            'paymentStatus' => 'Executed',
            'retrievalReferenceNumber' => 'ABC324353245',
            'processingStatus' => 'OK',
            'paymentMethod' => 'Card',
        ];

        $this->postSignedMaibCallback($payload)->assertOk()->assertJsonPath('matched', true);

        $this->assertSame(120.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame('paid', $topup->fresh()->status);
        $this->assertSame('5a4d27a4-79f5-426b-9403-cccdeee81747', $topup->fresh()->payment_session_id);
        $this->assertSame('379b31a3-8283-43d4-8a7b-eef8c0736a32', $topup->fresh()->payment_intent_id);

        $this->postSignedMaibCallback($payload)->assertOk();
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
            'payment_session_id' => 'checkout-invalid-sig',
        ]);

        $payload = [
            'checkoutId' => 'checkout-invalid-sig',
            'orderId' => 'wallet-topup-'.$topup->id,
            'paymentId' => 'pay-1',
            'paymentStatus' => 'Executed',
            'amount' => 50,
            'currency' => 'MDL',
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) (int) (microtime(true) * 1000);

        $this->call(
            'POST',
            '/api/maib/callback',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGNATURE' => 'invalid-signature',
                'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
            ],
            $raw
        )->assertForbidden();

        $this->assertSame(0.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame('pending', $topup->fresh()->status);
    }

    public function test_verify_payment_fallback_credits_when_checkout_completed(): void
    {
        Http::fake([
            'https://api.maibmerchants.md/v2/auth/token' => Http::response([
                'ok' => true,
                'result' => [
                    'accessToken' => 'access-token',
                    'expiresIn' => 300,
                    'tokenType' => 'Bearer',
                ],
            ]),
            'https://api.maibmerchants.md/v2/checkouts/*' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => 'pay-verify-1',
                    'status' => 'Completed',
                    'amount' => 80,
                    'currency' => 'MDL',
                    'payment' => [
                        'paymentId' => 'pay-verify-payment-1',
                        'status' => 'Executed',
                        'referenceNumber' => 'rrn-1',
                    ],
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
        $this->assertSame('pay-verify-1', $topup->fresh()->payment_session_id);
        $this->assertSame('pay-verify-payment-1', $topup->fresh()->payment_intent_id);
    }

    public function test_callback_hmac_matches_checkout_algorithm(): void
    {
        $rawBody = '{"checkoutId":"5a4d27a4-79f5-426b-9403-cccdeee81747","amount":1234.56}';
        $timestamp = '1761032516817';
        $key = '67be8e54-ac28-485d-9369-27f6d3c55a27';

        $signature = app(MaibPaymentService::class)->buildCallbackSignature($rawBody, $timestamp, $key);
        $expected = base64_encode(hash_hmac('sha256', $rawBody.'.'.$timestamp, $key, true));

        $this->assertSame($expected, $signature);
    }

    public function test_refund_uses_stored_payment_id_without_checkout_lookup(): void
    {
        Http::fake([
            'https://api.maibmerchants.md/v2/auth/token' => Http::response([
                'ok' => true,
                'result' => [
                    'accessToken' => 'access-token',
                    'expiresIn' => 300,
                    'tokenType' => 'Bearer',
                ],
            ]),
            'https://api.maibmerchants.md/v2/payments/*/refund' => Http::response([
                'ok' => true,
                'result' => [
                    'refundId' => 'refund-1',
                    'status' => 'Created',
                ],
            ]),
        ]);

        $admin = $this->createAdminUser();
        $user = $this->createAppUser(['wallet_balance' => 100]);
        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'maib',
            'payment_session_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1',
            'payment_intent_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbb2',
            'paid_at' => now(),
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/wallet-topups/'.$topup->id.'/refund', ['amount' => 40])
            ->assertOk()
            ->assertJsonPath('topup.amount_refunded', 40)
            ->assertJsonPath('user_wallet_balance', 60);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/payments/bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbb2/refund');
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/v2/checkouts/');
        });
    }

    public function test_refund_falls_back_to_session_id_when_legacy_payment_id_was_saved_there(): void
    {
        Http::fake([
            'https://api.maibmerchants.md/v2/auth/token' => Http::response([
                'ok' => true,
                'result' => [
                    'accessToken' => 'access-token',
                    'expiresIn' => 300,
                    'tokenType' => 'Bearer',
                ],
            ]),
            'https://api.maibmerchants.md/v2/checkouts/*' => Http::response([
                'ok' => false,
                'errors' => [
                    ['errorMessage' => 'Cannot found Checkout by id'],
                ],
            ], 404),
            'https://api.maibmerchants.md/v2/payments/*/refund' => Http::response([
                'ok' => true,
                'result' => [
                    'refundId' => 'refund-legacy-1',
                    'status' => 'Created',
                ],
            ]),
        ]);

        $admin = $this->createAdminUser();
        $user = $this->createAppUser(['wallet_balance' => 80]);
        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 80,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'maib',
            // Legacy bug: paymentId was saved over checkoutId, RRN in payment_intent_id.
            'payment_session_id' => '379b31a3-8283-43d4-8a7b-eef8c0736a32',
            'payment_intent_id' => 'ABC324353245',
            'paid_at' => now(),
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/wallet-topups/'.$topup->id.'/refund', ['amount' => 80])
            ->assertOk()
            ->assertJsonPath('topup.amount_refunded', 80)
            ->assertJsonPath('user_wallet_balance', 0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/payments/379b31a3-8283-43d4-8a7b-eef8c0736a32/refund');
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedMaibCallback(array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($raw);
        $timestamp = (string) (int) (microtime(true) * 1000);
        $signature = app(MaibPaymentService::class)->buildCallbackSignature($raw, $timestamp);

        return $this->call(
            'POST',
            '/api/maib/callback',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGNATURE' => $signature,
                'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
            ],
            $raw
        );
    }
}
