<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackofficeUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_be_filtered_by_account_type(): void
    {
        $admin = $this->createAdminUser(['email' => 'admin@example.test']);

        $customer = $this->createAppUser(['email' => 'customer@example.test', 'name' => 'Client Card']);
        $personal = $this->createPersonalUser(['email' => 'personal@example.test', 'name' => 'Angajat VOLTA']);

        $session = [
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ];

        $this->withSession($session)
            ->getJson('/backoffice/users?account_type=customer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', $customer->email)
            ->assertJsonPath('data.0.account_type', User::ACCOUNT_TYPE_CUSTOMER);

        $this->withSession($session)
            ->getJson('/backoffice/users?account_type=personal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', $personal->email)
            ->assertJsonPath('data.0.account_type', User::ACCOUNT_TYPE_PERSONAL);
    }

    public function test_personal_user_detail_includes_invoices_and_outstanding_balance(): void
    {
        $admin = $this->createAdminUser(['email' => 'admin@example.test']);
        $personal = $this->createPersonalUser(['email' => 'personal@example.test', 'name' => 'Angajat VOLTA']);

        Invoice::query()->create([
            'user_id' => $personal->id,
            'month' => '2026-04',
            'currency' => 'MDL',
            'invoice_type' => 'monthly',
            'invoice_number' => 'EVM-202604-2',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'total_kwh' => 18.4,
            'total_amount' => 82.8,
            'sessions_count' => 4,
            'status' => 'unpaid',
        ]);

        Invoice::query()->create([
            'user_id' => $personal->id,
            'month' => '2026-03',
            'currency' => 'MDL',
            'invoice_type' => 'monthly',
            'invoice_number' => 'EVM-202603-2',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'total_kwh' => 10,
            'total_amount' => 45,
            'sessions_count' => 2,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $session = [
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ];

        $this->withSession($session)
            ->getJson('/backoffice/users/' . $personal->id)
            ->assertOk()
            ->assertJsonPath('data.user.email', $personal->email)
            ->assertJsonPath('data.billing.outstanding_balance', 82.8)
            ->assertJsonPath('data.billing.unpaid_invoices_count', 1)
            ->assertJsonPath('data.billing.paid_invoices_count', 1)
            ->assertJsonCount(2, 'data.invoices');
    }

    public function test_admin_user_detail_is_not_available(): void
    {
        $admin = $this->createAdminUser(['email' => 'admin@example.test']);

        $session = [
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ];

        $this->withSession($session)
            ->getJson('/backoffice/users/' . $admin->id)
            ->assertNotFound();
    }
}
