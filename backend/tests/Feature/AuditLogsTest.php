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
        $admin = User::query()->create([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
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
}
