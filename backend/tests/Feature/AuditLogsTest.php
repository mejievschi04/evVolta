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
}
