<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BackofficeStationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_station_creation_generates_a_qr_code_and_exposes_qr_routes(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
        ]);

        $station = Station::query()->create([
            'name' => 'Depot Alpha',
            'location' => 'Main Parking',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => null,
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])->post('/backoffice/stations', [
            'name' => 'Station North 1',
            'location' => 'North Lot',
            'status' => 'available',
            'qr_code' => '',
            'latitude' => '47.010452',
            'longitude' => '28.863810',
        ])->assertStatus(302);

        $this->assertDatabaseHas('stations', [
            'name' => 'Station North 1',
            'location' => 'North Lot',
            'status' => 'available',
            'qr_code' => 'station:station-north-1',
            'latitude' => '47.010452',
            'longitude' => '28.863810',
        ]);

        $this->assertNotNull(Route::getRoutes()->getByName('backoffice.stations.qr.preview'));
        $this->assertNotNull(Route::getRoutes()->getByName('backoffice.stations.qr'));
        $this->assertSame(url('/backoffice/stations/' . $station->id . '/qr-preview'), route('backoffice.stations.qr.preview', $station));
        $this->assertSame(url('/backoffice/stations/' . $station->id . '/qr'), route('backoffice.stations.qr', $station));

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])->get('/backoffice/stations/' . $station->id . '/qr-preview')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])->get('/backoffice/stations/' . $station->id . '/qr')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'attachment; filename="station-' . $station->id . '-qr.png"');
    }

    public function test_backoffice_stations_list_includes_coordinates_for_map(): void
    {
        $admin = $this->createAdminUser();

        Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'latitude' => 47.010452,
            'longitude' => 28.86381,
        ]);

        Station::query()->create([
            'name' => 'VOLTA 2',
            'location' => 'Centru',
            'status' => Station::STATUS_OFFLINE,
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson('/backoffice/stations')
            ->assertOk()
            ->assertJsonPath('data.0.latitude', 47.010452)
            ->assertJsonPath('data.0.longitude', 28.86381)
            ->assertJsonPath('data.1.latitude', null)
            ->assertJsonPath('data.1.longitude', null);
    }
}
