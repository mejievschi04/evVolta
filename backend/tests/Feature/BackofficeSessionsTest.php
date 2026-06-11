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
}
