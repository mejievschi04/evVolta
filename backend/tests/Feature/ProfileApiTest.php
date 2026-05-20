<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_read_and_update_profile(): void
    {
        $user = User::query()->create([
            'name' => 'Driver One',
            'first_name' => 'Driver',
            'last_name' => 'One',
            'email' => 'driver@example.test',
            'currency' => 'MDL',
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'driver@example.test')
            ->assertJsonPath('user.currency', 'MDL');

        $this->actingAs($user, 'api')
            ->patchJson('/api/me', [
                'first_name' => 'Ion',
                'last_name' => 'Popescu',
                'email' => 'ion@example.test',
                'currency' => 'eur',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Ion Popescu')
            ->assertJsonPath('user.first_name', 'Ion')
            ->assertJsonPath('user.last_name', 'Popescu')
            ->assertJsonPath('user.email', 'ion@example.test')
            ->assertJsonPath('user.currency', 'EUR');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Ion Popescu',
            'email' => 'ion@example.test',
            'currency' => 'EUR',
        ]);
    }

    public function test_profile_email_must_be_unique(): void
    {
        User::query()->create([
            'name' => 'Existing User',
            'email' => 'existing@example.test',
            'password' => Hash::make('password123'),
        ]);

        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user, 'api')
            ->patchJson('/api/me', [
                'name' => 'Driver One',
                'email' => 'existing@example.test',
                'currency' => 'MDL',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }
}
