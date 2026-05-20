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
        $admin = User::query()->create([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
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
}
