<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccountBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_users_receive_monthly_invoices_only(): void
    {
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $personalUser = $this->createPersonalUser(['email' => 'personal@example.test']);
        $customerUser = $this->createAppUser(['email' => 'customer@example.test']);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:volta-1',
        ]);

        foreach ([$personalUser, $customerUser] as $user) {
            ChargingSession::query()->create([
                'user_id' => $user->id,
                'station_id' => $station->id,
                'start_time' => Carbon::parse('2026-04-05 08:00:00'),
                'end_time' => Carbon::parse('2026-04-05 09:00:00'),
                'kwh_consumed' => 4.5,
            ]);
        }

        $created = app(BillingService::class)->generateMonthlyInvoices(Carbon::parse('2026-04-01'));

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('invoices', [
            'user_id' => $personalUser->id,
            'invoice_type' => 'monthly',
        ]);
        $this->assertDatabaseMissing('invoices', [
            'user_id' => $customerUser->id,
            'invoice_type' => 'monthly',
        ]);
    }

    public function test_customer_users_receive_session_invoices_only(): void
    {
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $customer = $this->createAppUser(['email' => 'customer@example.test']);
        $personal = $this->createPersonalUser(['email' => 'personal@example.test']);
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:volta-1',
        ]);

        $customerSession = ChargingSession::query()->create([
            'user_id' => $customer->id,
            'station_id' => $station->id,
            'start_time' => now()->subHour(),
            'end_time' => now(),
            'kwh_consumed' => 6,
        ]);

        $personalSession = ChargingSession::query()->create([
            'user_id' => $personal->id,
            'station_id' => $station->id,
            'start_time' => now()->subHour(),
            'end_time' => now(),
            'kwh_consumed' => 6,
        ]);

        $customerInvoice = app(BillingService::class)->createSessionInvoice($customerSession);
        $personalInvoice = app(BillingService::class)->createSessionInvoice($personalSession);

        $this->assertInstanceOf(Invoice::class, $customerInvoice);
        $this->assertNull($personalInvoice);
    }

    public function test_personal_session_stop_creates_monthly_invoice(): void
    {
        Tariff::query()->create(['price_per_kwh' => 0.50]);

        $personal = $this->createPersonalUser(['email' => 'personal@example.test']);
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:volta-1',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $personal->id,
            'station_id' => $station->id,
            'start_time' => now()->subHour(),
            'kwh_consumed' => 0,
        ]);

        $result = app(\App\Services\ChargingStopService::class)->finalizeStop($session, $station, 'app');

        $this->assertSame('completed', $result['status']);
        $this->assertNotNull($result['invoice']);
        $this->assertSame('monthly', $result['invoice']->invoice_type);
        $this->assertSame($personal->id, $result['invoice']->user_id);
        $session->refresh();
        $this->assertGreaterThan(0, (float) $session->kwh_consumed);
        $this->assertEquals((float) $session->kwh_consumed, (float) $result['invoice']->total_kwh);
    }

    public function test_personal_user_cannot_start_stripe_checkout(): void
    {
        $personal = $this->createPersonalUser(['email' => 'personal@example.test']);

        $invoice = Invoice::query()->create([
            'user_id' => $personal->id,
            'month' => '2026-04',
            'currency' => 'MDL',
            'invoice_type' => 'monthly',
            'invoice_number' => 'EVM-202604-1',
            'total_kwh' => 10,
            'total_amount' => 5,
            'sessions_count' => 2,
            'status' => 'unpaid',
        ]);

        $this->actingAs($personal, 'api')
            ->postJson('/api/invoices/' . $invoice->id . '/checkout-session')
            ->assertStatus(403);
    }
}
