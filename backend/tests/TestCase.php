<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    protected function createAdminUser(array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'name' => 'Backoffice Admin',
            'email' => 'admin-' . uniqid() . '@example.test',
            'password' => Hash::make('password123'),
        ], $overrides));

        $user->forceFill(['is_admin' => true])->save();

        return $user->fresh();
    }

    protected function createAppUser(array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'name' => 'App User',
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => Hash::make('password123'),
        ], $overrides));

        $user->forceFill([
            'is_admin' => false,
            'account_type' => $overrides['account_type'] ?? User::ACCOUNT_TYPE_CUSTOMER,
            'wallet_balance' => $overrides['wallet_balance']
                ?? (($overrides['account_type'] ?? User::ACCOUNT_TYPE_CUSTOMER) === User::ACCOUNT_TYPE_CUSTOMER ? 500 : 0),
        ])->save();

        return $user->fresh();
    }

    protected function createPersonalUser(array $overrides = []): User
    {
        return $this->createAppUser(array_merge([
            'account_type' => User::ACCOUNT_TYPE_PERSONAL,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createLiveGatewayStation(array $attributes = []): Station
    {
        return Station::query()->create(array_merge([
            'name' => 'Gateway Station',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'last_ocpp_message_at' => now(),
        ], $attributes));
    }
}
