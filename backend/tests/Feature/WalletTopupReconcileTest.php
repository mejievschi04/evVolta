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

    public function test_unpaid_and_too_fresh_topups_stay_pending(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $unpaid = $this->pendingTopup($user, 'chk-open', minutesAgo: 10);
        $tooFresh = $this->pendingTopup($user, 'chk-fresh', minutesAgo: 1);
        $this->fakeCheckouts([
            'chk-open' => ['status' => 'WaitingForPayment'],
            'chk-fresh' => $this->completedCheckout(),
        ]);

        $this->artisan('wallet:reconcile-topups')->assertSuccessful();

        $this->assertSame('pending', $unpaid->fresh()->status);
        $this->assertSame('pending', $tooFresh->fresh()->status);
        $this->assertSame(0.0, (float) $user->fresh()->wallet_balance);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chk-fresh'));
    }

    public function test_unpaid_topup_older_than_a_day_is_expired(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $stale = $this->pendingTopup($user, 'chk-stale', minutesAgo: 60 * 25);
        $backlog = $this->pendingTopup($user, 'chk-backlog', minutesAgo: 60 * 24 * 14);
        $this->fakeCheckouts([
            'chk-stale' => ['status' => 'Expired'],
            'chk-backlog' => ['status' => 'WaitingForPayment'],
        ]);

        $this->artisan('wallet:reconcile-topups')->assertSuccessful();

        $this->assertSame('expired', $stale->fresh()->status);
        $this->assertSame('expired', $backlog->fresh()->status);
        $this->assertSame(0.0, (float) $user->fresh()->wallet_balance);
        Http::assertSentCount(3); // token + 2 checkouts, each checked once
    }

    public function test_old_topup_paid_at_maib_is_credited_not_expired(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $topup = $this->pendingTopup($user, 'chk-late', minutesAgo: 60 * 30);
        $this->fakeCheckouts(['chk-late' => $this->completedCheckout()]);

        $this->artisan('wallet:reconcile-topups')->assertSuccessful();

        $this->assertSame('paid', $topup->fresh()->status);
        $this->assertSame(150.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_old_topup_stays_pending_while_maib_errors_until_forced(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $recent = $this->pendingTopup($user, 'chk-err-recent', minutesAgo: 60 * 30);
        $ancient = $this->pendingTopup($user, 'chk-err-ancient', minutesAgo: 60 * 24 * 8);
        $this->fakeCheckouts([
            'chk-err-recent' => Http::response('', 500),
            'chk-err-ancient' => Http::response('', 500),
        ]);

        $this->artisan('wallet:reconcile-topups')->assertSuccessful();

        $this->assertSame('pending', $recent->fresh()->status);
        $this->assertSame('expired', $ancient->fresh()->status);
    }

    public function test_without_maib_config_topups_expire_only_after_force_window(): void
    {
        config(['services.maib.client_id' => null, 'services.maib.client_secret' => null]);
        Http::fake();

        $user = $this->createAppUser(['wallet_balance' => 0]);
        $dayOld = $this->pendingTopup($user, 'chk-day', minutesAgo: 60 * 30);
        $weekOld = $this->pendingTopup($user, 'chk-week', minutesAgo: 60 * 24 * 8);

        $this->artisan('wallet:reconcile-topups')->assertSuccessful();

        $this->assertSame('pending', $dayOld->fresh()->status);
        $this->assertSame('expired', $weekOld->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_failing_topups_rotate_so_others_are_still_checked(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $broken = $this->pendingTopup($user, 'chk-broken', minutesAgo: 60 * 30);
        $stale = $this->pendingTopup($user, 'chk-stale', minutesAgo: 60 * 30);
        $this->fakeCheckouts([
            'chk-broken' => Http::response('', 500),
            'chk-stale' => ['status' => 'Expired'],
        ]);

        $this->artisan('wallet:reconcile-topups', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('pending', $broken->fresh()->status);
        $this->assertNotNull($broken->fresh()->last_checked_at);
        $this->assertSame('pending', $stale->fresh()->status);

        $this->artisan('wallet:reconcile-topups', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('expired', $stale->fresh()->status);
        $this->assertSame('pending', $broken->fresh()->status);
    }

    public function test_paid_topup_whose_credit_fails_is_never_expired(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $topup = $this->pendingTopup($user, 'chk-paid-old', minutesAgo: 60 * 24 * 10);
        $this->fakeCheckouts(['chk-paid-old' => $this->completedCheckout()]);

        $wallet = \Mockery::mock(\App\Services\WalletService::class);
        $wallet->shouldReceive('creditTopup')->andThrow(new \RuntimeException('db down'));
        $this->app->instance(\App\Services\WalletService::class, $wallet);

        $this->artisan('wallet:reconcile-topups')->assertSuccessful();

        $this->assertSame('pending', $topup->fresh()->status);
    }

    public function test_expired_topup_is_still_credited_by_late_payment(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $topup = $this->pendingTopup($user, 'chk-expired', minutesAgo: 60 * 25);
        $topup->update(['status' => 'expired']);

        app(\App\Services\WalletService::class)->creditTopup($topup->fresh(), 'chk-expired', 'pay-late');

        $this->assertSame('paid', $topup->fresh()->status);
        $this->assertSame(150.0, (float) $user->fresh()->wallet_balance);
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
