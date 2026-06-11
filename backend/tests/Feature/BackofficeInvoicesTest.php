<?php

namespace Tests\Feature;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BackofficeInvoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_invoice_can_be_downloaded_and_sent(): void
    {
        Mail::fake();

        $admin = $this->createAdminUser([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
        ]);

        $user = $this->createAppUser([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'currency' => 'MDL',
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'month' => '2026-04',
            'currency' => 'MDL',
            'invoice_type' => 'monthly',
            'invoice_number' => 'EVM-202604-1',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'total_kwh' => 12.5,
            'total_amount' => 56.25,
            'sessions_count' => 3,
            'status' => 'unpaid',
        ]);

        $session = [
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ];

        $this->withSession($session)
            ->get('/backoffice/invoices/' . $invoice->id . '/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="evm-202604-1.html"')
            ->assertSee('EVM-202604-1');

        $this->withSession($session)
            ->postJson('/backoffice/invoices/' . $invoice->id . '/send')
            ->assertOk()
            ->assertJsonPath('message', 'Factura a fost trimisa pe email.');

        Mail::assertSent(InvoiceMail::class);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backoffice.invoice.sent',
            'actor_user_id' => $admin->id,
            'subject_id' => $invoice->id,
        ]);
    }

    public function test_backoffice_invoice_can_be_deleted(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
        ]);

        $user = $this->createAppUser([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'month' => '2026-04',
            'currency' => 'MDL',
            'invoice_type' => 'monthly',
            'invoice_number' => 'EVM-202604-9',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'total_kwh' => 4.2,
            'total_amount' => 18.9,
            'sessions_count' => 1,
            'status' => 'unpaid',
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson('/backoffice/invoices/' . $invoice->id . '/delete')
            ->assertOk()
            ->assertJsonPath('message', 'Factura a fost stearsa.');

        $this->assertDatabaseMissing('invoices', [
            'id' => $invoice->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backoffice.invoice.deleted',
            'actor_user_id' => $admin->id,
            'subject_id' => $invoice->id,
        ]);
    }
}
