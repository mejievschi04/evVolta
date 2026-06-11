<?php

namespace Tests\Feature;

use App\Models\RegistrationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackofficeRegistrationRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_lists_pending_and_approved_registration_requests(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
        ]);

        $pending = RegistrationRequest::query()->create([
            'name' => 'Ion Popescu',
            'email' => 'ion@example.test',
            'status' => RegistrationRequest::STATUS_PENDING,
        ]);

        $approved = RegistrationRequest::query()->create([
            'name' => 'Maria Ionescu',
            'email' => 'maria@example.test',
            'status' => RegistrationRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]);

        $response = $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])->getJson('/backoffice/registration-requests');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.status', RegistrationRequest::STATUS_PENDING)
            ->assertJsonPath('data.1.id', $approved->id)
            ->assertJsonPath('data.1.status', RegistrationRequest::STATUS_APPROVED);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson('/backoffice/registration-requests?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id);
    }
}
