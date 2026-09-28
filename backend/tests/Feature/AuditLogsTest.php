<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuditLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_actions_are_written_to_audit_log_and_routes_are_registered(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
        ]);

        $session = [
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ];

        $this->withSession($session)
            ->post('/backoffice/stations', [
                'name' => 'Station Z1',
                'location' => 'Depot Alpha',
                'status' => 'available',
                'qr_code' => null,
            ])
            ->assertStatus(302);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backoffice.station.created',
            'actor_user_id' => $admin->id,
        ]);

        $auditLog = AuditLog::query()->firstOrFail();

        $this->assertNotNull(Route::getRoutes()->getByName('backoffice.audit_logs'));
        $this->assertNotNull(Route::getRoutes()->getByName('backoffice.audit_logs.show'));
        $this->assertSame(url('/backoffice/audit-logs'), route('backoffice.audit_logs'));
        $this->assertSame(url('/backoffice/audit-logs/' . $auditLog->id), route('backoffice.audit_logs.show', $auditLog));
    }

    public function test_backoffice_can_load_audit_log_detail_with_relations(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->post('/backoffice/stations', [
                'name' => 'Station Detail',
                'location' => 'Depot Beta',
                'status' => 'available',
            ])
            ->assertStatus(302);

        $auditLog = AuditLog::query()->firstOrFail();

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson('/backoffice/audit-logs/' . $auditLog->id)
            ->assertOk()
            ->assertJsonPath('data.action', 'backoffice.station.created')
            ->assertJsonPath('data.actor.email', 'admin@example.test')
            ->assertJsonPath('data.station.name', 'Station Detail')
            ->assertJsonPath('data.metadata.name', 'Station Detail');
    }

    public function test_backoffice_audit_list_only_includes_last_week(): void
    {
        config(['privacy.retention.audit_logs_days' => 7]);

        $admin = $this->createAdminUser(['email' => 'admin-audit@example.test']);

        $recent = AuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'action' => 'backoffice.station.created',
            'subject_type' => 'station',
            'subject_id' => 1,
            'metadata' => ['name' => 'Recent'],
        ]);
        $recent->forceFill(['created_at' => now()->subDays(2)])->save();

        $old = AuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'action' => 'backoffice.station.created',
            'subject_type' => 'station',
            'subject_id' => 2,
            'metadata' => ['name' => 'Old'],
        ]);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson('/backoffice/audit-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson('/backoffice/audit-logs/' . $old->id)
            ->assertNotFound();
    }

    public function test_privacy_purge_deletes_audit_logs_older_than_one_week(): void
    {
        config(['privacy.retention.audit_logs_days' => 7]);

        $keep = AuditLog::query()->create([
            'action' => 'auth.login',
            'metadata' => [],
        ]);
        $keep->forceFill(['created_at' => now()->subDays(3)])->save();

        $drop = AuditLog::query()->create([
            'action' => 'auth.login',
            'metadata' => [],
        ]);
        $drop->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->artisan('privacy:purge-expired')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['id' => $keep->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $drop->id]);
    }
}
