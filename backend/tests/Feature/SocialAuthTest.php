<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SocialAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_login_creates_user_and_returns_token(): void
    {
        $this->mock(SocialAuthService::class, function (MockInterface $mock) {
            $mock->shouldReceive('verifyGoogleIdToken')
                ->once()
                ->with('google-token')
                ->andReturn([
                    'provider_user_id' => 'google-sub-1',
                    'email' => 'google.user@example.test',
                    'email_verified' => true,
                    'name' => 'Google User',
                    'first_name' => 'Google',
                    'last_name' => 'User',
                ]);

            $mock->shouldReceive('findOrCreateUser')
                ->once()
                ->withArgs(function (string $provider, array $identity) {
                    return $provider === 'google'
                        && $identity['provider_user_id'] === 'google-sub-1';
                })
                ->andReturnUsing(function () {
                    $user = User::query()->create([
                        'name' => 'Google User',
                        'first_name' => 'Google',
                        'last_name' => 'User',
                        'email' => 'google.user@example.test',
                        'password' => 'secret-random',
                        'currency' => 'MDL',
                        'google_id' => 'google-sub-1',
                    ]);

                    $user->forceFill([
                        'is_admin' => false,
                        'account_type' => User::ACCOUNT_TYPE_CUSTOMER,
                        'wallet_balance' => 0,
                    ])->save();

                    return $user->fresh();
                });
        });

        $this->postJson('/api/auth/google', [
            'id_token' => 'google-token',
            'accept_terms' => true,
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('user.email', 'google.user@example.test')
            ->assertJsonPath('user.auth_providers.google', true);

        $this->assertDatabaseHas('users', [
            'email' => 'google.user@example.test',
            'google_id' => 'google-sub-1',
        ]);
    }

    public function test_apple_login_links_existing_email_account(): void
    {
        $existing = $this->createPersonalUser([
            'email' => 'linked@example.test',
            'name' => 'Existing User',
        ]);

        $this->mock(SocialAuthService::class, function (MockInterface $mock) use ($existing) {
            $mock->shouldReceive('verifyAppleIdentityToken')
                ->once()
                ->andReturn([
                    'provider_user_id' => 'apple-sub-9',
                    'email' => 'linked@example.test',
                    'email_verified' => true,
                    'name' => null,
                    'first_name' => null,
                    'last_name' => null,
                ]);

            $mock->shouldReceive('findOrCreateUser')
                ->once()
                ->andReturnUsing(function () use ($existing) {
                    $existing->forceFill(['apple_id' => 'apple-sub-9'])->save();

                    return $existing->fresh();
                });
        });

        $this->postJson('/api/auth/apple', [
            'identity_token' => 'apple-token',
            'accept_terms' => true,
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $existing->id)
            ->assertJsonPath('user.auth_providers.apple', true);
    }

    public function test_social_account_can_be_deleted_without_password(): void
    {
        $user = $this->createPersonalUser([
            'email' => 'social@example.test',
            'google_id' => 'google-sub-delete',
            'wallet_balance' => 0,
        ]);

        $token = auth('api')->login($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/me/delete', [
                'confirm_delete' => true,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Contul a fost sters.');
    }

    public function test_find_or_create_user_links_by_email(): void
    {
        $existing = $this->createPersonalUser([
            'email' => 'merge@example.test',
        ]);

        $service = app(SocialAuthService::class);
        $user = $service->findOrCreateUser('google', [
            'provider_user_id' => 'google-merge-1',
            'email' => 'merge@example.test',
            'email_verified' => true,
            'name' => 'Merged',
            'first_name' => 'Merged',
            'last_name' => null,
        ]);

        $this->assertSame($existing->id, $user->id);
        $this->assertSame('google-merge-1', $user->google_id);
    }
}
