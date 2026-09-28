<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use App\Models\WalletTopup;
use App\Services\BillingService;
use App\Services\SocialAuthService;
use App\Services\UserDeletionService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserProcessRiskFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_settle_refunds_hold_when_user_flipped_to_service(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $user = $this->createAppUser([
            'email' => 'flip-service@example.test',
            'wallet_balance' => 40,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA Flip',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:flip',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-06-10 08:00:00'),
            'end_time' => Carbon::parse('2026-06-10 08:20:00'),
            'kwh_consumed' => 4,
            'charge_budget' => 20,
        ]);

        // Simulate hold already taken at start.
        $user->forceFill(['wallet_balance' => 40])->save();

        $user->forceFill(['account_type' => User::ACCOUNT_TYPE_SERVICE])->save();

        $charged = app(WalletService::class)->settleSession($session->fresh(), 0.50);

        $this->assertSame(0.0, $charged);
        $this->assertEquals(60.0, (float) $user->fresh()->wallet_balance);
        $this->assertEquals(0.0, (float) $session->fresh()->charge_budget);
        $this->assertNull(app(BillingService::class)->finalizeBillingForSession($session->fresh()));
    }

    public function test_settle_debits_wallet_when_session_started_without_hold_on_prepaid(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $user = $this->createAppUser([
            'email' => 'no-hold@example.test',
            'wallet_balance' => 100,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA NoHold',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:no-hold',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-06-10 08:00:00'),
            'end_time' => Carbon::parse('2026-06-10 08:20:00'),
            'kwh_consumed' => 4,
            'charge_budget' => 0,
        ]);

        $invoice = app(BillingService::class)->finalizeBillingForSession($session);

        $this->assertNotNull($invoice);
        $this->assertEquals(2.0, (float) $invoice->total_amount);
        $this->assertEquals(98.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_backoffice_blocks_plan_change_during_open_session(): void
    {
        $admin = $this->createAdminUser(['email' => 'admin-plan@example.test']);
        $user = $this->createAppUser(['email' => 'open-session@example.test']);
        $station = Station::query()->create([
            'name' => 'VOLTA Open',
            'location' => 'Chisinau',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:open',
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now()->subMinutes(5),
            'end_time' => null,
            'kwh_consumed' => 1,
            'charge_budget' => 50,
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/users/'.$user->id.'/update', [
                'email' => $user->email,
                'account_type' => User::ACCOUNT_TYPE_SERVICE,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nu poti schimba planul in timpul unei incarcari active. Opreste sesiunea mai intai.');

        $this->assertSame(User::ACCOUNT_TYPE_CUSTOMER, $user->fresh()->account_type);
    }

    public function test_credit_topup_is_idempotent_under_double_call(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        $user = $this->createAppUser([
            'email' => 'topup-race@example.test',
            'wallet_balance' => 10,
        ]);

        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 50,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'maib',
        ]);

        $wallet = app(WalletService::class);
        $wallet->creditTopup($topup);
        $wallet->creditTopup($topup->fresh());

        $this->assertEquals(60.0, (float) $user->fresh()->wallet_balance);
        $this->assertEquals(1, Invoice::query()->where('wallet_topup_id', $topup->id)->count());
    }

    public function test_ensure_session_invoice_settles_hold_instead_of_inventing_amount(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $user = $this->createAppUser([
            'email' => 'ensure-settle@example.test',
            'wallet_balance' => 80,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA Ensure',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:ensure',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-06-10 08:00:00'),
            'end_time' => Carbon::parse('2026-06-10 08:30:00'),
            'kwh_consumed' => 4,
            'charge_budget' => 20,
        ]);

        $invoice = app(BillingService::class)->ensureSessionInvoice($session->fresh());

        $this->assertNotNull($invoice);
        $this->assertEquals(2.0, (float) $invoice->total_amount);
        // 80 + refund(18) after charging 2 from 20 hold
        $this->assertEquals(98.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_social_link_requires_verified_email(): void
    {
        $existing = $this->createPersonalUser([
            'email' => 'unverified-link@example.test',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Email-ul din provider nu este verificat');

        app(SocialAuthService::class)->findOrCreateUser('google', [
            'provider_user_id' => 'google-unverified-1',
            'email' => 'unverified-link@example.test',
            'email_verified' => false,
            'name' => 'X',
            'first_name' => 'X',
            'last_name' => null,
        ]);

        $this->assertNull($existing->fresh()->google_id);
    }

    public function test_service_account_with_leftover_balance_can_be_deleted(): void
    {
        $user = $this->createServiceUser([
            'email' => 'service-balance@example.test',
            'wallet_balance' => 25,
            'password' => Hash::make('password123'),
        ]);

        app(UserDeletionService::class)->delete($user);

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.account_deleted',
            'subject_id' => $user->id,
        ]);
    }

    public function test_privacy_purge_keeps_financial_audit_longer(): void
    {
        config([
            'privacy.retention.audit_logs_days' => 7,
            'privacy.retention.audit_logs_financial_days' => 365,
        ]);

        $keepFinancial = AuditLog::query()->create([
            'action' => 'wallet.topup_credited',
            'metadata' => [],
        ]);
        $keepFinancial->forceFill(['created_at' => now()->subDays(30)])->save();

        $dropOps = AuditLog::query()->create([
            'action' => 'auth.login',
            'metadata' => [],
        ]);
        $dropOps->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->artisan('privacy:purge-expired')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['id' => $keepFinancial->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $dropOps->id]);
    }

    public function test_remember_me_defaults_to_false(): void
    {
        $this->createAppUser([
            'email' => 'remember-default@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'remember-default@example.test',
            'password' => 'password123',
            'accept_terms' => true,
        ])
            ->assertOk()
            ->assertJsonPath('remember_me', false);
    }

    public function test_cannot_start_on_another_station_while_session_open(): void
    {
        config([
            'billing.prepaid_wallet_enabled' => true,
            'services.ocpp.mode' => 'simulator',
        ]);

        $user = $this->createAppUser([
            'email' => 'cross-station@example.test',
            'wallet_balance' => 500,
        ]);

        $stationA = Station::query()->create([
            'name' => 'Station A',
            'location' => 'A',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station-a',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Charging'],
                ],
            ],
        ]);

        $stationB = Station::query()->create([
            'name' => 'Station B',
            'location' => 'B',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station-b',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Preparing'],
                ],
            ],
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $stationA->id,
            'ocpp_connector_id' => 1,
            'start_time' => now()->subMinutes(5),
            'end_time' => null,
            'kwh_consumed' => 1,
            'charge_budget' => 50,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $stationB->id,
                'budget_amount' => 50,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Ai deja o incarcare activa pe alta statie. Opreste-o inainte de a porni alta.'
            );
    }

    public function test_settle_releases_hold_when_prepaid_disabled(): void
    {
        config(['billing.prepaid_wallet_enabled' => false]);
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $user = $this->createAppUser([
            'email' => 'prepaid-off@example.test',
            'wallet_balance' => 50,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA Off',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:prepaid-off',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-06-10 08:00:00'),
            'end_time' => Carbon::parse('2026-06-10 08:20:00'),
            'kwh_consumed' => 4,
            'charge_budget' => 20,
        ]);

        $charged = app(WalletService::class)->settleSession($session->fresh(), 0.50);

        $this->assertSame(2.0, $charged);
        // Hold 20 - charged 2 = refund 18 → wallet 50 + 18
        $this->assertEquals(68.0, (float) $user->fresh()->wallet_balance);
        $this->assertEquals(2.0, (float) $session->fresh()->charge_budget);
    }

    public function test_social_signup_rejects_missing_email(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Providerul nu a furnizat un email');

        app(SocialAuthService::class)->findOrCreateUser('apple', [
            'provider_user_id' => 'apple-no-email-1',
            'email' => null,
            'email_verified' => true,
            'name' => 'Apple User',
            'first_name' => 'Apple',
            'last_name' => 'User',
        ]);

        $this->assertDatabaseMissing('users', ['apple_id' => 'apple-no-email-1']);
    }

    public function test_refund_topup_calls_provider_outside_and_completes(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        $admin = $this->createAdminUser(['email' => 'admin-refund@example.test']);
        $customer = $this->createAppUser([
            'email' => 'refund-maib@example.test',
            'wallet_balance' => 200,
        ]);

        $topup = WalletTopup::query()->create([
            'user_id' => $customer->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'maib',
            'payment_session_id' => 'checkout_test_refund',
            'payment_intent_id' => 'pay_test_refund',
            'paid_at' => now(),
        ]);

        $maib = \Mockery::mock(\App\Services\MaibPaymentService::class);
        $maib->shouldReceive('isConfigured')->once()->andReturn(true);
        $maib->shouldReceive('refund')
            ->once()
            ->with('checkout_test_refund', 40.0, 'pay_test_refund')
            ->andReturn(['id' => 'rf_test_1']);
        $this->app->instance(\App\Services\MaibPaymentService::class, $maib);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/wallet-topups/' . $topup->id . '/refund', ['amount' => 40])
            ->assertOk()
            ->assertJsonPath('user_wallet_balance', 160)
            ->assertJsonPath('topup.amount_refunded', 40);

        $this->assertDatabaseHas('wallet_refunds', [
            'wallet_topup_id' => $topup->id,
            'amount' => 40,
            'status' => 'completed',
            'provider_refund_id' => 'rf_test_1',
        ]);
    }

    public function test_refund_rolls_back_reservation_when_provider_fails(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        $admin = $this->createAdminUser(['email' => 'admin-refund-fail@example.test']);
        $customer = $this->createAppUser([
            'email' => 'refund-fail@example.test',
            'wallet_balance' => 200,
        ]);

        $topup = WalletTopup::query()->create([
            'user_id' => $customer->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'maib',
            'payment_session_id' => 'checkout_test_fail',
            'payment_intent_id' => 'pay_test_fail',
            'paid_at' => now(),
        ]);

        $maib = \Mockery::mock(\App\Services\MaibPaymentService::class);
        $maib->shouldReceive('isConfigured')->once()->andReturn(true);
        $maib->shouldReceive('refund')
            ->once()
            ->andThrow(new \RuntimeException('MAIB down', 502));
        $this->app->instance(\App\Services\MaibPaymentService::class, $maib);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/wallet-topups/' . $topup->id . '/refund', ['amount' => 40])
            ->assertStatus(502);

        $this->assertEquals(200.0, (float) $customer->fresh()->wallet_balance);
        $this->assertEquals(0.0, (float) $topup->fresh()->amount_refunded);
        $this->assertDatabaseHas('wallet_refunds', [
            'wallet_topup_id' => $topup->id,
            'status' => 'failed',
        ]);
    }

    public function test_refund_timeout_keeps_wallet_debit_for_review(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        $admin = $this->createAdminUser(['email' => 'admin-refund-timeout@example.test']);
        $customer = $this->createAppUser([
            'email' => 'refund-timeout@example.test',
            'wallet_balance' => 200,
        ]);

        $topup = WalletTopup::query()->create([
            'user_id' => $customer->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'maib',
            'payment_session_id' => 'checkout_test_timeout',
            'payment_intent_id' => 'pay_test_timeout',
            'paid_at' => now(),
        ]);

        $maib = \Mockery::mock(\App\Services\MaibPaymentService::class);
        $maib->shouldReceive('isConfigured')->once()->andReturn(true);
        $maib->shouldReceive('refund')
            ->once()
            ->andThrow(new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out'));
        $this->app->instance(\App\Services\MaibPaymentService::class, $maib);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/wallet-topups/' . $topup->id . '/refund', ['amount' => 40])
            ->assertStatus(503);

        $this->assertEquals(160.0, (float) $customer->fresh()->wallet_balance);
        $this->assertEquals(40.0, (float) $topup->fresh()->amount_refunded);
        $this->assertDatabaseHas('wallet_refunds', [
            'wallet_topup_id' => $topup->id,
            'status' => 'needs_review',
            'amount' => 40,
        ]);
    }

    public function test_pending_refund_blocks_charging_start(): void
    {
        config([
            'billing.prepaid_wallet_enabled' => true,
            'services.ocpp.mode' => 'simulator',
        ]);

        $user = $this->createAppUser([
            'email' => 'pending-refund@example.test',
            'wallet_balance' => 200,
        ]);

        \App\Models\WalletRefund::query()->create([
            'user_id' => $user->id,
            'amount' => 20,
            'currency' => 'MDL',
            'status' => 'pending',
            'payment_provider' => 'maib',
        ]);

        $station = Station::query()->create([
            'name' => 'Pending Refund Station',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station-pending-refund',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Preparing'],
                ],
            ],
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
                'budget_amount' => 50,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Ai un retur in curs. Asteapta finalizarea inainte de a porni incarcarea.'
            );
    }

    public function test_resume_carry_uses_settled_charge_not_estimate(): void
    {
        config([
            'billing.prepaid_wallet_enabled' => true,
            'services.ocpp.mode' => 'simulator',
        ]);
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $user = $this->createAppUser([
            'email' => 'resume-carry@example.test',
            'wallet_balance' => 100,
        ]);

        $station = Station::query()->create([
            'name' => 'Resume Carry',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => 'resume-carry-01',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'NumberOfConnectors' => 1,
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'SuspendedEV'],
                ],
            ],
        ]);

        $oldSession = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_id_tag' => 'VOLTA00000001',
            'ocpp_transaction_id' => '88',
            'start_source' => 'app',
            'start_time' => now()->subMinutes(20),
            'kwh_consumed' => 4,
            'charge_budget' => 50,
            'live_metrics' => [
                // Inflated estimate must not drive carry — settle uses kwh * price = 2.0
                'budget_spent' => 40,
                'energy_kwh' => 4,
            ],
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/charging/resume', [
                'station_id' => $station->id,
                'session_id' => $oldSession->id,
                'connector_id' => 1,
            ])
            ->assertCreated();

        $newSessionId = (int) $response->json('session.id');
        $newSession = ChargingSession::query()->findOrFail($newSessionId);

        // Settled spent = 4 * 0.50 = 2; carry = 50 - 2 = 48
        $this->assertEquals(48.0, (float) $newSession->charge_budget);
        $this->assertEquals(2.0, (float) $oldSession->fresh()->charge_budget);
    }

    public function test_start_on_already_charging_session_skips_remote_start(): void
    {
        config([
            'billing.prepaid_wallet_enabled' => true,
            'services.ocpp.mode' => 'simulator',
        ]);

        $user = $this->createAppUser([
            'email' => 'already-active@example.test',
            'wallet_balance' => 200,
        ]);

        $station = Station::query()->create([
            'name' => 'Already Active',
            'location' => 'Test',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station-already-active',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Charging'],
                ],
            ],
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_transaction_id' => 'tx-active-1',
            'start_time' => now()->subMinutes(5),
            'end_time' => null,
            'kwh_consumed' => 1,
            'charge_budget' => 50,
        ]);

        // Avoid binding a partial OcppService mock — the early already_active path
        // must not reach queueRemoteStart at all.
        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
                'connector_id' => 1,
                'budget_amount' => 50,
            ])
            ->assertOk()
            ->assertJsonPath('already_active', true)
            ->assertJsonPath('session.id', $session->id);

        $this->assertSame(1, ChargingSession::query()->where('user_id', $user->id)->whereNull('end_time')->count());
    }
}
