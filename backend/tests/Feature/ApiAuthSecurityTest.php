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

    public function test_profile_update_writes_audit_log(): void
    {
        $user = $this->createAppUser([
            'email' => 'profile@example.test',
        ]);

        $this->actingAs($user, 'api')
            ->patchJson('/api/me', [
                'email' => 'profile-updated@example.test',
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
}
