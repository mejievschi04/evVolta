<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTopup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalletTopupReconcileTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_paid_topup_without_callback_is_credited_once(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $topup = $this->pendingTopup($user, 'chk-paid', minutesAgo: 10);
        $this->fakeCheckouts(['chk-paid' => $this->completedCheckout()]);

        $this->artisan('wallet:reconcile-topups')->assertSuccessful();
        $this->artisan('wallet:reconcile-topups')->assertSuccessful();

        $this->assertSame('paid', $topup->fresh()->status);
        $this->assertSame('pay-1', $topup->fresh()->payment_intent_id);
        $this->assertSame(150.0, (float) $user->fresh()->wallet_balance, 'Must be credited exactly once');
    }

    public function test_unpaid_and_out_of_window_topups_are_left_alone(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $unpaid = $this->pendingTopup($user, 'chk-open', minutesAgo: 10);
        $tooFresh = $this->pendingTopup($user, 'chk-fresh', minutesAgo: 1);
        $tooOld = $this->pendingTopup($user, 'chk-old', minutesAgo: 60 * 72);
        $this->fakeCheckouts([
            'chk-open' => ['status' => 'WaitingForPayment'],
            'chk-fresh' => $this->completedCheckout(),
            'chk-old' => $this->completedCheckout(),
        ]);

        $this->artisan('wallet:reconcile-topups')->assertSuccessful();

        $this->assertSame('pending', $unpaid->fresh()->status);
        $this->assertSame('pending', $tooFresh->fresh()->status);
        $this->assertSame('pending', $tooOld->fresh()->status);
        $this->assertSame(0.0, (float) $user->fresh()->wallet_balance);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chk-fresh') || str_contains($request->url(), 'chk-old'));
    }

    public function test_maib_error_on_one_topup_does_not_stop_the_others(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $broken = $this->pendingTopup($user, 'chk-broken', minutesAgo: 10);
        $paid = $this->pendingTopup($user, 'chk-paid', minutesAgo: 10);
        $this->fakeCheckouts([
            'chk-broken' => Http::response('', 500),
            'chk-paid' => $this->completedCheckout(),
        ]);

        $this->artisan('wallet:reconcile-topups')->assertSuccessful();

        $this->assertSame('pending', $broken->fresh()->status);
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame(150.0, (float) $user->fresh()->wallet_balance);
    }

    private function pendingTopup(User $user, string $checkoutId, int $minutesAgo): WalletTopup
    {
        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 150,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'maib',
            'payment_session_id' => $checkoutId,
        ]);
        $topup->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->saveQuietly();

        return $topup->fresh();
    }

    private function completedCheckout(): array
    {
        return [
            'status' => 'Completed',
            'payment' => ['paymentId' => 'pay-1', 'status' => 'Executed'],
        ];
    }

    /**
     * @param  array<string, mixed>  $checkouts  checkoutId => result array sau Http::response
     */
    private function fakeCheckouts(array $checkouts): void
    {
        $fakes = [
            'maib.test/v2/auth/token' => Http::response([
                'ok' => true,
                'result' => ['accessToken' => 'token', 'expiresIn' => 300],
            ]),
        ];

        foreach ($checkouts as $id => $result) {
            $fakes['maib.test/v2/checkouts/' . $id] = is_array($result)
                ? Http::response(['ok' => true, 'result' => $result])
                : $result;
        }

        Http::fake($fakes);
    }
}
