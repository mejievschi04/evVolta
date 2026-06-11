<?php

namespace Tests\Feature;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevicePushTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_push_token_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson('/api/device-push/register', [
                'token' => 'ExponentPushToken[test-token-123]',
                'platform' => 'ios',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Token inregistrat.');

        $this->assertDatabaseHas('device_push_tokens', [
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[test-token-123]',
            'platform' => 'ios',
        ]);
    }

    public function test_register_updates_existing_token_owner(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $token = 'ExponentPushToken[shared-token]';

        DevicePushToken::query()->create([
            'user_id' => $firstUser->id,
            'token' => $token,
            'platform' => 'android',
        ]);

        $this->actingAs($secondUser, 'api')
            ->postJson('/api/device-push/register', [
                'token' => $token,
                'platform' => 'ios',
            ])
            ->assertOk();

        $this->assertDatabaseHas('device_push_tokens', [
            'user_id' => $secondUser->id,
            'token' => $token,
            'platform' => 'ios',
        ]);

        $this->assertDatabaseMissing('device_push_tokens', [
            'user_id' => $firstUser->id,
            'token' => $token,
        ]);
    }

    public function test_unregister_push_token(): void
    {
        $user = User::factory()->create();
        $token = 'ExponentPushToken[remove-me]';

        DevicePushToken::query()->create([
            'user_id' => $user->id,
            'token' => $token,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/device-push/unregister', [
                'token' => $token,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Token eliminat.');

        $this->assertDatabaseMissing('device_push_tokens', [
            'user_id' => $user->id,
            'token' => $token,
        ]);
    }
}
