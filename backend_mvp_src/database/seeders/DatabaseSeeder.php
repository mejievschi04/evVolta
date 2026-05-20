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
            [
                'name' => 'VOLTA 1',
                'location' => 'str. Pădurii 19, Chișinău',
                'status' => 'available',
            ],
        ];

        $seededNames = collect($stations)->pluck('name');

        foreach ($stations as $station) {
            Station::query()->updateOrCreate(
                ['name' => $station['name']],
                $station + ['qr_code' => 'station:volta-1']
            );
        }

        Station::query()
            ->whereNotIn('name', $seededNames)
            ->delete();
    }
}
