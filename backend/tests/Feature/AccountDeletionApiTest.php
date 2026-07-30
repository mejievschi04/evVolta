<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeletionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_delete_own_account(): void
    {
        $user = $this->createAppUser([
            'email' => 'delete-me@example.test',
            'wallet_balance' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/me/delete', [
                'password' => 'password123',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Contul a fost sters.');

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.account_deleted',
            'subject_id' => $user->id,
        ]);
    }

    public function test_account_deletion_requires_correct_password(): void
    {
        $user = $this->createAppUser([
            'email' => 'delete-me@example.test',
            'wallet_balance' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/me/delete', [
                'password' => 'wrong-password',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Parola este incorecta.');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'delete-me@example.test']);
    }

    public function test_account_deletion_is_blocked_with_active_session(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $station = Station::query()->create([
            'name' => 'Test Station',
            'location' => 'Chisinau',
            'status' => 'available',
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now()->subMinutes(10),
            'end_time' => null,
            'kwh_delivered' => 1.2,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/me/delete', [
                'password' => 'password123',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Exista o incarcare activa. Opreste sesiunea inainte de stergerea contului.');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_account_deletion_is_blocked_with_wallet_balance(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 25.5]);

        $this->actingAs($user, 'api')
            ->postJson('/api/me/delete', [
                'password' => 'password123',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Soldul contului trebuie sa fie zero inainte de stergere.');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_account_deletion_is_blocked_with_unpaid_invoices(): void
    {
        $user = $this->createPersonalUser(['wallet_balance' => 0]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'month' => '2026-04',
            'currency' => 'MDL',
            'invoice_type' => 'monthly',
            'invoice_number' => 'EVM-202604-9',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'total_kwh' => 10,
            'total_amount' => 45,
            'sessions_count' => 2,
            'status' => 'unpaid',
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/me/delete', [
                'password' => 'password123',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Contul are facturi neplatite. Achita sau contacteaza suportul.');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_account_deletion_cancels_blocking_reservations(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $station = Station::query()->create([
            'name' => 'Test Station',
            'location' => 'Chisinau',
            'status' => 'available',
        ]);

        $reservation = Reservation::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'connector_id' => 1,
            'ocpp_reservation_id' => 42,
            'id_tag' => 'VOLTA00000001',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'status' => Reservation::STATUS_CONFIRMED,
            'fee_amount' => 0,
        ]);

        $reservationId = $reservation->id;

        $this->actingAs($user, 'api')
            ->postJson('/api/me/delete', [
                'password' => 'password123',
            ])
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'status' => Reservation::STATUS_CANCELLED,
        ]);
    }
}
