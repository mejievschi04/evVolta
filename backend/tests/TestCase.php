<?php

namespace Tests;

use App\Models\Station;
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

        $accountType = $overrides['account_type'] ?? User::ACCOUNT_TYPE_CUSTOMER;
        $prepaidAccount = in_array($accountType, [User::ACCOUNT_TYPE_CUSTOMER, User::ACCOUNT_TYPE_PERSONAL], true);

        $user->forceFill([
            'is_admin' => false,
            'account_type' => $accountType,
            'wallet_balance' => array_key_exists('wallet_balance', $overrides)
                ? $overrides['wallet_balance']
                : ($prepaidAccount ? 500 : 0),
            'legal_accepted_at' => array_key_exists('legal_accepted_at', $overrides)
                ? $overrides['legal_accepted_at']
                : now(),
            'legal_version' => array_key_exists('legal_version', $overrides)
                ? $overrides['legal_version']
                : config('legal.version'),
            'legal_accepted_source' => array_key_exists('legal_accepted_source', $overrides)
                ? $overrides['legal_accepted_source']
                : 'test',
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
