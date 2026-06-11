<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_to_backoffice_but_not_mobile_app(): void
    {
        $admin = $this->createAdminUser([
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/backoffice/login', [
            'email' => 'admin@example.test',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.test');

        $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Contul de administrator se foloseste doar in backoffice.');
    }

    public function test_app_user_can_login_to_mobile_api_but_not_backoffice(): void
    {
        $user = $this->createAppUser([
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'driver@example.test',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'driver@example.test');

        $this->postJson('/backoffice/login', [
            'email' => 'driver@example.test',
            'password' => 'password123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Acces backoffice permis doar conturilor de administrator.');
    }

    public function test_backoffice_routes_reject_non_admin_session(): void
    {
        $user = $this->createAppUser([
            'email' => 'driver@example.test',
        ]);

        $this->withSession([
            'backoffice_user_id' => $user->id,
            'backoffice_user_name' => $user->name,
        ])
            ->getJson('/backoffice/stations')
            ->assertStatus(401);
    }
}
