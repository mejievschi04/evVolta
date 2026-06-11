<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_login_is_rate_limited_after_five_attempts(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'missing@example.test',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/login', [
            'email' => 'missing@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_protected_api_routes_return_json_unauthorized(): void
    {
        $this->getJson('/api/stations')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
