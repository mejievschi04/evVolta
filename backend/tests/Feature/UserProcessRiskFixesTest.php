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
            'payment_provider' => 'stripe',
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
}
