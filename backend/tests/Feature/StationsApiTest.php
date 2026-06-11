<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_stations_api_supports_filters_and_favorites(): void
    {
        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
        ]);

        $favoriteStation = Station::query()->create([
            'name' => 'Alpha 1',
            'location' => 'North Parking',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:alpha-1',
            'power_kw' => 22,
            'connector_type' => 'Type 2',
            'currency' => 'MDL',
        ]);

        Station::query()->create([
            'name' => 'Beta 1',
            'location' => 'South Parking',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:beta-1',
            'power_kw' => 50,
            'connector_type' => 'CCS2',
            'currency' => 'MDL',
        ]);

        $matchingStation = Station::query()->create([
            'name' => 'Gamma 1',
            'location' => 'East Parking',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:gamma-1',
            'power_kw' => 11,
            'connector_type' => 'CCS2',
            'currency' => 'MDL',
            'latitude' => 47.010452,
            'longitude' => 28.86381,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/stations/' . $favoriteStation->id . '/favorite')
            ->assertOk()
            ->assertJsonPath('station_id', $favoriteStation->id)
            ->assertJsonPath('is_favorite', true);

        $this->assertDatabaseHas('station_favorites', [
            'user_id' => $user->id,
            'station_id' => $favoriteStation->id,
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/stations?status=available&connector=CCS2')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $matchingStation->id)
            ->assertJsonPath('0.latitude', 47.010452)
            ->assertJsonPath('0.longitude', 28.86381)
            ->assertJsonPath('0.live_status.availability', Station::STATUS_AVAILABLE)
            ->assertJsonPath('0.live_status.can_start', false)
            ->assertJsonPath('0.live_status.connection_status', Station::OCPP_CONNECTION_NOT_CONFIGURED);

        $this->actingAs($user, 'api')
            ->getJson('/api/stations?favorite_only=1')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $favoriteStation->id)
            ->assertJsonPath('0.is_favorite', true);

        $this->actingAs($user, 'api')
            ->postJson('/api/stations/' . $favoriteStation->id . '/favorite')
            ->assertOk()
            ->assertJsonPath('is_favorite', false);

        $this->assertDatabaseMissing('station_favorites', [
            'user_id' => $user->id,
            'station_id' => $favoriteStation->id,
        ]);
    }
}
