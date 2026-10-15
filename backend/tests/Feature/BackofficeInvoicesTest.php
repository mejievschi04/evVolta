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
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="evm-202604-1.pdf"');

        $this->withSession($session)
            ->postJson('/backoffice/invoices/' . $invoice->id . '/send')
            ->assertOk()
            ->assertJsonPath('message', 'Factura a fost trimisa pe email.');

        Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) {
            $built = $mail->build();
            $attachments = $built->rawAttachments ?? [];

            return count($attachments) === 1
                && ($attachments[0]['options']['mime'] ?? null) === 'application/pdf';
        });

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

    public function test_issued_session_invoice_cannot_be_deleted_or_reissued(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createAppUser();
        $station = \App\Models\Station::query()->create([
            'name' => 'VOLTA INV',
            'location' => 'Chisinau',
            'status' => 'available',
        ]);
        $session = \App\Models\ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'start_time' => now()->subHour(),
            'end_time' => now()->subMinutes(10),
            'kwh_consumed' => 4,
        ]);
        $invoice = app(\App\Services\InvoiceIssuanceService::class)->createSessionInvoice($session, 16.0);

        $this->withSession(['backoffice_user_id' => $admin->id])
            ->postJson('/backoffice/invoices/'.$invoice->id.'/delete')
            ->assertStatus(422);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertSame(1, Invoice::query()->where('source_session_id', $session->id)->count());
    }
}
