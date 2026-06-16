<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use App\Services\BillingService;
use App\Services\TariffService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTariffTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_users_use_personal_tariff_from_settings(): void
    {
        Tariff::query()->create([
            'price_per_kwh' => 0.50,
            'personal_price_per_kwh' => 0.10,
        ]);

        $personal = $this->createPersonalUser(['email' => 'personal.tariff@example.test']);
        $tariffService = app(TariffService::class);

        $this->assertSame(0.50, $tariffService->globalPricePerKwh());
        $this->assertSame(0.10, $tariffService->personalPricePerKwh());
        $this->assertSame(0.10, $tariffService->pricePerKwhForUser($personal));
    }

    public function test_customers_use_customer_tariff_from_settings(): void
    {
        Tariff::query()->create([
            'price_per_kwh' => 0.40,
            'personal_price_per_kwh' => 0.08,
        ]);

        $customer = $this->createAppUser(['email' => 'customer.tariff@example.test']);

        $this->assertSame(0.40, app(TariffService::class)->pricePerKwhForUser($customer));
    }

    public function test_personal_tariff_falls_back_to_customer_tariff_when_not_set(): void
    {
        Tariff::query()->create(['price_per_kwh' => 0.35]);

        $personal = $this->createPersonalUser(['email' => 'personal.fallback@example.test']);

        $this->assertSame(0.35, app(TariffService::class)->pricePerKwhForUser($personal));
    }

    public function test_session_billing_uses_personal_tariff_for_staff(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        Tariff::query()->create([
            'price_per_kwh' => 0.50,
            'personal_price_per_kwh' => 0.10,
        ]);

        $personal = $this->createPersonalUser([
            'email' => 'personal.bill@example.test',
            'wallet_balance' => 100,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:volta-1',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $personal->id,
            'station_id' => $station->id,
            'start_time' => now()->subHour(),
            'end_time' => now(),
            'kwh_consumed' => 10,
            'charge_budget' => 50,
        ]);

        $invoice = app(BillingService::class)->finalizeBillingForSession($session);

        $this->assertNotNull($invoice);
        $this->assertSame(1.0, (float) $invoice->total_amount);
    }

    public function test_wallet_charge_options_use_account_type_tariff(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        Tariff::query()->create([
            'price_per_kwh' => 0.50,
            'personal_price_per_kwh' => 0.25,
        ]);

        $personal = $this->createPersonalUser(['email' => 'personal.wallet@example.test']);
        $customer = $this->createAppUser(['email' => 'customer.wallet@example.test']);

        $this->assertSame(0.25, app(WalletService::class)->chargeOptions($personal)['price_per_kwh']);
        $this->assertSame(0.50, app(WalletService::class)->chargeOptions($customer)['price_per_kwh']);
    }

    public function test_backoffice_settings_update_both_tariffs(): void
    {
        $admin = $this->createAdminUser(['email' => 'admin.tariff@example.test']);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/settings', [
                'first_name' => 'Ana',
                'last_name' => 'Popescu',
                'price_per_kwh' => 0.45,
                'personal_price_per_kwh' => 0.12,
            ])
            ->assertOk()
            ->assertJsonPath('data.tariff.price_per_kwh', 0.45)
            ->assertJsonPath('data.tariff.personal_price_per_kwh', 0.12);

        $this->assertDatabaseHas('tariffs', [
            'price_per_kwh' => 0.45,
            'personal_price_per_kwh' => 0.12,
        ]);
    }

    public function test_api_tariff_endpoint_returns_account_type_price(): void
    {
        Tariff::query()->create([
            'price_per_kwh' => 0.50,
            'personal_price_per_kwh' => 0.12,
        ]);

        $personal = $this->createPersonalUser(['email' => 'personal.api@example.test']);

        $this->actingAs($personal, 'api')
            ->getJson('/api/tariff/current')
            ->assertOk()
            ->assertJsonPath('price_per_kwh', 0.12)
            ->assertJsonPath('customer_price_per_kwh', 0.5)
            ->assertJsonPath('personal_price_per_kwh', 0.12)
            ->assertJsonPath('account_type', User::ACCOUNT_TYPE_PERSONAL);
    }
}
