<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ion Popescu',
            'email' => 'ion@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Contul a fost creat.')
            ->assertJsonPath('user.email', 'ion@example.test')
            ->assertJsonPath('user.account_type', User::ACCOUNT_TYPE_CUSTOMER)
            ->assertJsonStructure(['access_token', 'user']);

        $this->assertDatabaseHas('users', [
            'email' => 'ion@example.test',
            'account_type' => User::ACCOUNT_TYPE_CUSTOMER,
            'is_admin' => false,
        ]);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->createAppUser(['email' => 'ion@example.test']);

        $this->postJson('/api/register', [
            'name' => 'Alt Ion',
            'email' => 'ion@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422);
    }

    public function test_register_requires_password_confirmation(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Ion Popescu',
            'email' => 'ion@example.test',
            'password' => 'password123',
            'password_confirmation' => 'different',
        ])->assertStatus(422);
    }
}
