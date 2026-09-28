<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_stations_api_is_public(): void
    {
        $this->getJson('/api/stations')
            ->assertOk();
    }

    public function test_api_login_is_not_blocked_after_a_few_failed_attempts(): void
    {
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'missing@example.test',
                'password' => 'wrong-password',
                'accept_terms' => true,
            ])->assertStatus(401);
        }
    }

    public function test_protected_api_routes_return_json_unauthorized(): void
    {
        $this->getJson('/api/wallet')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
