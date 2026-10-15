<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ApiAuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_login_writes_audit_log(): void
    {
        $this->postJson('/api/login', [
            'email' => 'missing@example.test',
            'password' => 'wrong-password',
            'accept_terms' => true,
        ])->assertUnauthorized();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login_failed',
        ]);
    }

    public function test_client_cannot_spoof_ip_via_forwarded_header(): void
    {
        // VPS: PHP-FPM sees the client directly, so its X-Forwarded-For must be ignored.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Forwarded-For' => '1.2.3.4'])
            ->postJson('/api/login', [
                'email' => 'spoof@example.test',
                'password' => 'wrong-password',
                'accept_terms' => true,
            ])->assertUnauthorized();

        $this->assertSame('203.0.113.9', AuditLog::query()->latest('id')->first()->metadata['ip'] ?? null);

        // Docker: our private-network nginx appends the real client after any spoofed entry.
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders(['X-Forwarded-For' => '1.2.3.4, 198.51.100.7'])
            ->postJson('/api/login', [
                'email' => 'spoof@example.test',
                'password' => 'wrong-password',
                'accept_terms' => true,
            ])->assertUnauthorized();

        $this->assertSame('198.51.100.7', AuditLog::query()->latest('id')->first()->metadata['ip'] ?? null);
    }

    public function test_successful_login_writes_audit_log(): void
    {
        $user = $this->createAppUser([
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'driver@example.test',
            'password' => 'password123',
            'accept_terms' => true,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_logout_invalidates_token(): void
    {
        $user = $this->createAppUser();
        $token = JWTAuth::fromUser($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_refresh_returns_new_access_token(): void
    {
        $user = $this->createAppUser();
        $token = JWTAuth::fromUser($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/refresh')
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type']);

        $this->assertNotSame($token, $response->json('access_token'));
    }

    public function test_register_rejects_short_password(): void
    {
        $this->postJson('/api/register', [
            'email' => 'weak@example.test',
            'password' => 'short1',
            'name' => 'Weak User',
            'accept_terms' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_jwt_ttl_defaults_are_thirty_days(): void
    {
        $this->assertSame(43200, (int) config('jwt.ttl'));
        $this->assertSame(720, (int) config('jwt.ttl_session'));
        $this->assertGreaterThanOrEqual(
            (int) config('jwt.ttl'),
            (int) config('jwt.refresh_ttl'),
            'refresh_ttl must be at least as long as access ttl'
        );
    }

    public function test_remember_me_false_issues_shorter_token(): void
    {
        $user = $this->createAppUser([
            'email' => 'session@example.test',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'session@example.test',
            'password' => 'password123',
            'accept_terms' => true,
            'remember_me' => false,
        ])->assertOk();

        $token = $response->json('access_token');
        $payload = JWTAuth::setToken($token)->getPayload();
        $ttlSeconds = (int) $payload->get('exp') - (int) $payload->get('iat');

        $this->assertSame(false, $response->json('remember_me'));
        $this->assertEqualsWithDelta(720 * 60, $ttlSeconds, 5);
    }

    public function test_profile_update_writes_audit_log(): void
    {
        $user = $this->createAppUser([
            'email' => 'profile@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user, 'api')
            ->patchJson('/api/me', [
                'email' => 'profile-updated@example.test',
                'current_password' => 'password123',
                'name' => 'Updated Name',
            ])
            ->assertOk();

        $auditLog = AuditLog::query()->where('action', 'auth.profile_updated')->first();

        $this->assertNotNull($auditLog);
        $this->assertTrue((bool) ($auditLog->metadata['email_changed'] ?? false));
    }

    public function test_admin_cannot_login_via_api(): void
    {
        $admin = $this->createAdminUser([
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password123',
            'accept_terms' => true,
        ])->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login_blocked_admin',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_cors_blocks_unknown_origin_on_api(): void
    {
        $this->get('/api/tariff/current', [
            'Origin' => 'https://evil.example',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_refresh_keeps_the_token_lifetime_chosen_at_login(): void
    {
        $this->createAppUser([
            'email' => 'ttl@example.test',
            'password' => Hash::make('password123'),
        ]);

        $minutesLeft = function (string $token): int {
            $exp = (int) JWTAuth::setToken($token)->getPayload()->get('exp');

            return (int) round(($exp - time()) / 60);
        };

        foreach ([false => 720, true => 43200] as $remember => $expectedMinutes) {
            $token = $this->postJson('/api/login', [
                'email' => 'ttl@example.test',
                'password' => 'password123',
                'accept_terms' => true,
                'remember_me' => (bool) $remember,
            ])->assertOk()->json('access_token');

            $this->assertEqualsWithDelta($expectedMinutes, $minutesLeft($token), 1);

            $refreshed = $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/refresh')
                ->assertOk()
                ->json('access_token');

            $this->assertEqualsWithDelta($expectedMinutes, $minutesLeft($refreshed), 1);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_refresh_of_token_without_remember_claim_keeps_long_lifetime(): void
    {
        $user = $this->createAppUser();
        $legacyToken = JWTAuth::fromUser($user);

        $refreshed = $this->withHeader('Authorization', 'Bearer '.$legacyToken)
            ->postJson('/api/refresh')
            ->assertOk()
            ->json('access_token');

        $exp = (int) JWTAuth::setToken($refreshed)->getPayload()->get('exp');
        $this->assertEqualsWithDelta(43200, ($exp - time()) / 60, 1);
    }
}
