<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletRefund;
use App\Models\WalletTopup;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MaibRefundUncertainResponseTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.prepaid_wallet_enabled' => true,
            'services.maib.client_id' => 'client',
            'services.maib.client_secret' => 'secret',
            'services.maib.signature_key' => 'sig',
            'services.maib.base_url' => 'https://maib.test',
        ]);
    }

    public function test_server_error_on_refund_keeps_wallet_debited_for_review(): void
    {
        [$user, $topup] = $this->paidTopup();
        $this->fakeMaibRefund(Http::response('<html>Bad Gateway</html>', 502));

        $this->assertRefundThrows($topup, 503);

        $this->assertSame(100.0, (float) $user->fresh()->wallet_balance, 'Wallet must stay debited: MAIB may have refunded');
        $this->assertSame(100.0, (float) $topup->fresh()->amount_refunded);
        $this->assertSame('needs_review', WalletRefund::query()->sole()->status);
    }

    public function test_client_error_on_refund_restores_wallet(): void
    {
        [$user, $topup] = $this->paidTopup();
        $this->fakeMaibRefund(Http::response([
            'ok' => false,
            'errors' => [['errorMessage' => 'Refund amount exceeds payment']],
        ], 400));

        $this->assertRefundThrows($topup, 422);

        $this->assertSame(200.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame(0.0, (float) $topup->fresh()->amount_refunded);
        $this->assertSame('failed', WalletRefund::query()->sole()->status);
    }

    public function test_successful_refund_completes(): void
    {
        [$user, $topup] = $this->paidTopup();
        $this->fakeMaibRefund(Http::response([
            'ok' => true,
            'result' => ['refundId' => 'r-1', 'status' => 'OK'],
        ]));

        $result = app(WalletService::class)->refundTopup($topup->fresh(), 100);

        $this->assertSame(100.0, $result['refunded']);
        $this->assertSame(100.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame('completed', WalletRefund::query()->sole()->status);
    }

    public function test_uncertain_refund_resolved_as_failed_restores_wallet_and_unblocks_user(): void
    {
        [$user, $topup] = $this->paidTopup();
        $this->fakeMaibRefund(Http::response('', 504));
        $this->assertRefundThrows($topup, 503);
        $this->assertTrue(app(WalletService::class)->hasPendingRefund($user->fresh()));

        $refund = WalletRefund::query()->sole();
        $this->artisan('wallet:resolve-refund', ['refund' => $refund->id, 'outcome' => 'failed', '--force' => true])
            ->assertSuccessful();

        $this->assertSame('failed', $refund->fresh()->status);
        $this->assertSame(200.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame(0.0, (float) $topup->fresh()->amount_refunded);
        $this->assertFalse(app(WalletService::class)->hasPendingRefund($user->fresh()));
    }

    public function test_uncertain_refund_resolved_as_completed_keeps_debit(): void
    {
        [$user, $topup] = $this->paidTopup();
        $this->fakeMaibRefund(Http::response('', 502));
        $this->assertRefundThrows($topup, 503);

        $refund = WalletRefund::query()->sole();
        $this->artisan('wallet:resolve-refund', [
            'refund' => $refund->id,
            'outcome' => 'completed',
            '--provider-id' => 'maib-r-77',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('completed', $refund->fresh()->status);
        $this->assertSame('maib-r-77', $refund->fresh()->provider_refund_id);
        $this->assertSame(100.0, (float) $user->fresh()->wallet_balance);
        $this->assertFalse(app(WalletService::class)->hasPendingRefund($user->fresh()));
    }

    public function test_resolved_refund_cannot_be_resolved_again(): void
    {
        [$user, $topup] = $this->paidTopup();
        $this->fakeMaibRefund(Http::response('', 502));
        $this->assertRefundThrows($topup, 503);
        $refund = WalletRefund::query()->sole();

        $this->artisan('wallet:resolve-refund', ['refund' => $refund->id, 'outcome' => 'failed', '--force' => true])
            ->assertSuccessful();
        $this->artisan('wallet:resolve-refund', ['refund' => $refund->id, 'outcome' => 'failed', '--force' => true])
            ->assertFailed();

        $this->assertSame(200.0, (float) $user->fresh()->wallet_balance, 'Second resolve must not credit twice');
    }

    /**
     * @return array{0: User, 1: WalletTopup}
     */
    private function paidTopup(): array
    {
        $user = $this->createAppUser(['wallet_balance' => 200]);
        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 200,
            'currency' => 'MDL',
            'status' => 'paid',
            'paid_at' => now(),
            'payment_provider' => 'maib',
            'payment_session_id' => 'checkout-1',
            'payment_intent_id' => self::PAYMENT_ID,
        ]);

        return [$user, $topup];
    }

    private function fakeMaibRefund($refundResponse): void
    {
        Http::fake([
            'maib.test/v2/auth/token' => Http::response([
                'ok' => true,
                'result' => ['accessToken' => 'token', 'expiresIn' => 300],
            ]),
            'maib.test/v2/payments/' . self::PAYMENT_ID . '/refund' => $refundResponse,
        ]);
    }

    private function assertRefundThrows(WalletTopup $topup, int $expectedCode): void
    {
        try {
            app(WalletService::class)->refundTopup($topup->fresh(), 100);
            $this->fail('Refund should have thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedCode, $exception->getCode(), $exception->getMessage());
        }
    }
}
