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
                'password' => Hash::make('password123'),
            ]
        );

        Tariff::query()->updateOrCreate(
            ['id' => 1],
            ['price_per_kwh' => 0.20]
        );

        $stations = [
            ['name' => 'Station A1', 'location' => 'Main Parking - Slot 1', 'status' => 'available'],
            ['name' => 'Station A2', 'location' => 'Main Parking - Slot 2', 'status' => 'available'],
            ['name' => 'Station B1', 'location' => 'Visitor Parking - Slot 1', 'status' => 'offline'],
        ];

        foreach ($stations as $station) {
            Station::query()->updateOrCreate(
                ['name' => $station['name']],
                $station + ['qr_code' => 'station:' . str_replace(' ', '-', strtolower($station['name']))]
            );
        }
    }
}
