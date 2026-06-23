<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackofficeAuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_backoffice_login_writes_audit_log(): void
    {
        $this->postJson('/backoffice/login', [
            'email' => 'missing@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backoffice.auth.login_failed',
        ]);
    }

    public function test_successful_backoffice_login_writes_audit_log(): void
    {
        $admin = $this->createAdminUser([
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/backoffice/login', [
            'email' => 'admin@example.test',
            'password' => 'password123',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backoffice.auth.login',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_backoffice_logout_writes_audit_log(): void
    {
        $admin = $this->createAdminUser();

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/logout')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backoffice.auth.logout',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_non_admin_backoffice_login_is_audited(): void
    {
        $user = $this->createAppUser([
            'email' => 'user@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/backoffice/login', [
            'email' => 'user@example.test',
            'password' => 'password123',
        ])->assertForbidden();

        $auditLog = AuditLog::query()->where('action', 'backoffice.auth.login_denied_non_admin')->first();

        $this->assertNotNull($auditLog);
        $this->assertSame($user->id, $auditLog->actor_user_id);
    }
}
