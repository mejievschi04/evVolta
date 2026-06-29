<?php

namespace Tests\Feature;

use App\Models\OcppCommand;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_book_reservation_in_simulator_mode(): void
    {
        Config::set('services.ocpp.mode', 'simulator');
        Config::set('billing.prepaid_wallet_enabled', true);

        $user = $this->createPersonalUser([
            'wallet_balance' => 200,
        ]);

        $station = $this->createReservableStation();

        $startsAt = now()->addHour()->startOfMinute();

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/reservations', [
                'station_id' => $station->id,
                'connector_id' => 1,
                'starts_at' => $startsAt->toIso8601String(),
                'duration_minutes' => 60,
            ]);

        $response->assertCreated()
            ->assertJsonPath('reservation.status', Reservation::STATUS_CONFIRMED)
            ->assertJsonPath('reservation.connector_id', 1);

        $this->assertDatabaseHas('reservations', [
            'user_id' => $user->id,
            'station_id' => $station->id,
            'status' => Reservation::STATUS_CONFIRMED,
            'fee_charged' => true,
        ]);

        $this->assertSame(185.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_overlapping_reservation_is_rejected(): void
    {
        Config::set('services.ocpp.mode', 'simulator');
        Config::set('billing.prepaid_wallet_enabled', true);

        $user = $this->createPersonalUser(['wallet_balance' => 500]);
        $other = $this->createPersonalUser(['wallet_balance' => 500]);
        $station = $this->createReservableStation();
        $startsAt = now()->addHours(2)->startOfMinute();

        Reservation::query()->create([
            'user_id' => $other->id,
            'station_id' => $station->id,
            'connector_id' => 1,
            'ocpp_reservation_id' => 1,
            'id_tag' => 'VOLTA00000002',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'status' => Reservation::STATUS_CONFIRMED,
            'fee_amount' => 15,
            'fee_charged' => true,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/reservations', [
                'station_id' => $station->id,
                'connector_id' => 1,
                'starts_at' => $startsAt->copy()->addMinutes(30)->toIso8601String(),
                'duration_minutes' => 60,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Intervalul selectat se suprapune cu o alta rezervare.');
    }

    public function test_booking_on_occupied_connector_is_rejected(): void
    {
        Config::set('services.ocpp.mode', 'simulator');
        Config::set('billing.prepaid_wallet_enabled', true);

        $user = $this->createPersonalUser(['wallet_balance' => 500]);
        $station = $this->createReservableStation([
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Charging'],
                    2 => ['connectorId' => 2, 'status' => 'Available'],
                ],
            ],
        ]);

        $startsAt = now()->addHour()->startOfMinute();

        $this->actingAs($user, 'api')
            ->postJson('/api/reservations', [
                'station_id' => $station->id,
                'connector_id' => 1,
                'starts_at' => $startsAt->toIso8601String(),
                'duration_minutes' => 60,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Conectorul este ocupat sau indisponibil pentru rezervare.');

        $this->actingAs($user, 'api')
            ->getJson("/api/stations/{$station->id}/reservations/availability")
            ->assertOk()
            ->assertJsonPath('connectors.0.can_reserve', false)
            ->assertJsonPath('connectors.1.can_reserve', true);
    }

    public function test_booking_queues_reserve_now_in_gateway_mode(): void
    {
        Config::set('services.ocpp.mode', 'gateway');
        Config::set('billing.prepaid_wallet_enabled', false);

        $user = $this->createPersonalUser();
        $station = $this->createReservableStation([
            'ocpp_identity' => 'reserve-station',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
        ]);

        $startsAt = now()->addHour()->startOfMinute();

        $this->actingAs($user, 'api')
            ->postJson('/api/reservations', [
                'station_id' => $station->id,
                'connector_id' => 1,
                'starts_at' => $startsAt->toIso8601String(),
                'duration_minutes' => 45,
            ])
            ->assertCreated()
            ->assertJsonPath('reservation.status', Reservation::STATUS_PENDING);

        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'action' => 'ReserveNow',
            'status' => OcppCommand::STATUS_PENDING,
        ]);
    }

    public function test_user_can_cancel_future_reservation_with_refund(): void
    {
        Config::set('services.ocpp.mode', 'simulator');
        Config::set('billing.prepaid_wallet_enabled', true);

        $user = $this->createPersonalUser(['wallet_balance' => 200]);
        $station = $this->createReservableStation();

        $reservation = Reservation::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'connector_id' => 1,
            'ocpp_reservation_id' => 1,
            'id_tag' => 'VOLTA00000001',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => Reservation::STATUS_CONFIRMED,
            'fee_amount' => 15,
            'fee_charged' => true,
        ]);

        $user->decrement('wallet_balance', 15);

        $this->actingAs($user, 'api')
            ->postJson("/api/reservations/{$reservation->id}/cancel")
            ->assertOk()
            ->assertJsonPath('reservation.status', Reservation::STATUS_CANCELLED);

        $this->assertSame(200.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_require_for_start_blocks_walk_in_without_reservation(): void
    {
        Config::set('services.ocpp.mode', 'simulator');
        Config::set('billing.prepaid_wallet_enabled', false);

        $user = $this->createPersonalUser();
        $station = $this->createReservableStation([
            'reservation_require_for_start' => true,
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                ],
            ],
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
                'connector_id' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ai nevoie de o rezervare activa pentru a porni incarcarea.');
    }

    public function test_no_show_processing_charges_fee(): void
    {
        Config::set('services.ocpp.mode', 'simulator');
        Config::set('billing.prepaid_wallet_enabled', true);

        $user = $this->createPersonalUser(['wallet_balance' => 100]);
        $station = $this->createReservableStation();

        Reservation::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'connector_id' => 1,
            'ocpp_reservation_id' => 1,
            'id_tag' => 'VOLTA00000001',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'status' => Reservation::STATUS_CONFIRMED,
            'fee_amount' => 15,
            'fee_charged' => true,
            'no_show_fee_amount' => 30,
        ]);

        $this->artisan('reservations:process')->assertSuccessful();

        $this->assertDatabaseHas('reservations', [
            'user_id' => $user->id,
            'status' => Reservation::STATUS_NO_SHOW,
        ]);

        $this->assertSame(70.0, (float) $user->fresh()->wallet_balance);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReservableStation(array $overrides = []): Station
    {
        return Station::query()->create(array_merge([
            'name' => 'Reservable',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'reservations_enabled' => true,
            'reservation_fee' => 15,
            'reservation_no_show_fee' => 30,
            'reservation_max_duration_minutes' => 120,
            'reservation_advance_days' => 14,
            'reservation_grace_minutes' => 20,
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                ],
            ],
        ], $overrides));
    }
}
