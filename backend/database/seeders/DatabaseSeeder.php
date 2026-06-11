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
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@local-ev.test'],
            [
                'name' => 'VOLTA Admin',
                'first_name' => 'VOLTA',
                'last_name' => 'Admin',
                'currency' => 'MDL',
                'password' => Hash::make('password123'),
            ]
        );
        $admin->forceFill(['is_admin' => true, 'account_type' => User::ACCOUNT_TYPE_CUSTOMER])->save();

        $driver = User::query()->updateOrCreate(
            ['email' => 'demo@local-ev.test'],
            [
                'name' => 'Demo Client',
                'first_name' => 'Demo',
                'last_name' => 'Client',
                'currency' => 'MDL',
                'password' => Hash::make('password123'),
            ]
        );
        $driver->forceFill([
            'is_admin' => false,
            'account_type' => User::ACCOUNT_TYPE_CUSTOMER,
            'wallet_balance' => 500,
        ])->save();

        $personal = User::query()->updateOrCreate(
            ['email' => 'personal@local-ev.test'],
            [
                'name' => 'Personal Demo',
                'first_name' => 'Personal',
                'last_name' => 'VOLTA',
                'currency' => 'MDL',
                'password' => Hash::make('password123'),
            ]
        );
        $personal->forceFill([
            'is_admin' => false,
            'account_type' => User::ACCOUNT_TYPE_PERSONAL,
        ])->save();

        Tariff::query()->updateOrCreate(
            ['id' => 1],
            ['price_per_kwh' => 0.20]
        );

        Station::query()->updateOrCreate(
            ['name' => 'VOLTA 1'],
            [
                'location' => 'str. Pădurii 19, Chișinău',
                'latitude' => 46.980428,
                'longitude' => 28.890762,
                'status' => 'available',
                'power_kw' => 22,
                'connector_type' => 'Type 2',
                'currency' => 'MDL',
                'qr_code' => 'station:volta-1',
                'ocpp_identity' => 'volta-1',
                'ocpp_version' => '1.6J',
                'ocpp_connection_status' => Station::OCPP_CONNECTION_NOT_CONFIGURED,
            ]
        );
    }
}
