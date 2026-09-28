<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackofficeSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_session_can_be_deleted_and_releases_active_station(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
        ]);

        $user = $this->createAppUser([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chișinău',
            'status' => Station::STATUS_CHARGING,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now()->subMinutes(20),
            'end_time' => null,
            'kwh_consumed' => 0,
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'month' => now()->format('Y-m'),
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => 'EVS-TEST-1',
            'source_session_id' => $session->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'total_kwh' => 1.2,
            'total_amount' => 5.4,
            'sessions_count' => 1,
            'status' => 'unpaid',
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/sessions/' . $session->id . '/delete')
            ->assertOk()
            ->assertJsonPath('message', 'Sesiunea a fost stearsa.');

        $this->assertDatabaseMissing('charging_sessions', [
            'id' => $session->id,
        ]);

        $this->assertDatabaseMissing('invoices', [
            'id' => $invoice->id,
        ]);

        $this->assertDatabaseHas('stations', [
            'id' => $station->id,
            'status' => Station::STATUS_AVAILABLE,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backoffice.session.deleted',
            'actor_user_id' => $admin->id,
            'subject_id' => $session->id,
        ]);
    }

    public function test_backoffice_delete_keeps_paid_invoices_and_settles_open_hold(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);

        $admin = $this->createAdminUser(['email' => 'admin-del@example.test']);
        $user = $this->createAppUser([
            'email' => 'driver-hold@example.test',
            'wallet_balance' => 80,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA Hold',
            'location' => 'Chisinau',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:hold-del',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now()->subMinutes(15),
            'end_time' => null,
            'kwh_consumed' => 2,
            'charge_budget' => 20,
        ]);

        $paidInvoice = Invoice::query()->create([
            'user_id' => $user->id,
            'month' => now()->format('Y-m'),
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => '0000099',
            'source_session_id' => $session->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'total_kwh' => 2,
            'total_amount' => 1,
            'sessions_count' => 1,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/sessions/' . $session->id . '/delete')
            ->assertOk();

        $this->assertDatabaseMissing('charging_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('invoices', [
            'id' => $paidInvoice->id,
            'source_session_id' => null,
            'status' => 'paid',
        ]);
        // Open hold must be settled/released (not abandoned with the deleted session).
        $this->assertGreaterThanOrEqual(80.0, (float) $user->fresh()->wallet_balance);
    }
}
