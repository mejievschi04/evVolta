<?php

namespace Database\Seeders;

use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'demo@local-ev.test'],
            [
                'name' => 'Demo User',
                'first_name' => 'Demo',
                'last_name' => 'User',
                'currency' => 'MDL',
                'password' => Hash::make('password123'),
            ]
        );

        Tariff::query()->updateOrCreate(
            ['id' => 1],
            ['price_per_kwh' => 0.20]
        );

        $stations = [
            ['name' => 'Station A1', 'location' => 'Main Parking - Slot 1', 'latitude' => 47.010452, 'longitude' => 28.86381, 'status' => 'available', 'power_kw' => 22, 'connector_type' => 'Type 2'],
            ['name' => 'Station A2', 'location' => 'Main Parking - Slot 2', 'latitude' => 47.011214, 'longitude' => 28.861845, 'status' => 'available', 'power_kw' => 50, 'connector_type' => 'CCS2'],
            ['name' => 'Station B1', 'location' => 'Visitor Parking - Slot 1', 'latitude' => 47.008977, 'longitude' => 28.866073, 'status' => 'offline', 'power_kw' => 11, 'connector_type' => 'Type 2'],
        ];

        foreach ($stations as $station) {
            Station::query()->updateOrCreate(
                ['name' => $station['name']],
                $station + [
                    'currency' => 'MDL',
                    'qr_code' => 'station:' . str_replace(' ', '-', strtolower($station['name'])),
                ]
            );
        }
    }
}
