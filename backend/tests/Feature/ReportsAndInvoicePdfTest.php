<?php

namespace Tests\Feature;

use App\Jobs\SendInvoiceEmailJob;
use App\Mail\InvoiceMail;
use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Station;
use App\Models\WalletTopup;
use App\Services\InvoiceIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReportsAndInvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_invoice_download_returns_pdf(): void
    {
        $user = $this->createAppUser(['email' => 'driver.pdf@example.test']);
        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'month' => '2026-04',
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => 'VE-20260428-0001',
            'period_start' => '2026-04-28',
            'period_end' => '2026-04-28',
            'total_kwh' => 5,
            'total_amount' => 20,
            'sessions_count' => 1,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->get('/api/invoices/' . $invoice->id . '/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="ve-20260428-0001.pdf"');
    }

    public function test_creating_session_invoice_queues_email_job(): void
    {
        Queue::fake();

        $user = $this->createAppUser(['email' => 'driver.auto@example.test']);
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:pdf-1',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now()->subHour(),
            'end_time' => now(),
            'kwh_consumed' => 4,
        ]);

        $invoice = app(InvoiceIssuanceService::class)->createSessionInvoice($session, 16.0);

        $this->assertNotNull($invoice);
        Queue::assertPushed(SendInvoiceEmailJob::class, function (SendInvoiceEmailJob $job) use ($invoice) {
            return $job->invoiceId === $invoice->id;
        });
    }

    public function test_send_invoice_email_job_sends_pdf_mail(): void
    {
        Mail::fake();

        $user = $this->createAppUser(['email' => 'driver.job@example.test']);
        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'month' => '2026-04',
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => 'VE-JOB-1',
            'period_start' => '2026-04-28',
            'period_end' => '2026-04-28',
            'total_kwh' => 2,
            'total_amount' => 8,
            'sessions_count' => 1,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        (new SendInvoiceEmailJob($invoice->id))->handle(app(\App\Services\InvoiceMailService::class));

        Mail::assertSent(InvoiceMail::class);
    }

    public function test_wallet_topup_confirmation_email_attaches_fiscal_pdf(): void
    {
        Mail::fake();
        Queue::fake();

        $user = $this->createAppUser(['email' => 'topup.pdf@example.test']);
        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'maib',
            'paid_at' => now(),
        ]);

        $invoice = app(InvoiceIssuanceService::class)->createWalletTopupInvoice($topup);

        $this->assertNotNull($invoice);
        Queue::assertNotPushed(SendInvoiceEmailJob::class);

        (new \App\Jobs\SendWalletTopupConfirmationJob($topup->id, $invoice->id))
            ->handle(app(\App\Services\InvoiceDocumentService::class));

        Mail::assertSent(\App\Mail\WalletTopupConfirmationMail::class, function ($mail) use ($invoice) {
            $built = $mail->build();
            $attachments = $built->rawAttachments ?? [];

            return $mail->invoice?->id === $invoice->id
                && count($attachments) === 1
                && ($attachments[0]['options']['mime'] ?? null) === 'application/pdf';
        });
    }

    public function test_station_monthly_report_returns_pdf_with_day_station_rows(): void
    {
        $admin = $this->createAdminUser(['email' => 'admin.reports@example.test']);
        $user = $this->createAppUser(['email' => 'driver.reports@example.test']);
        $stationA = Station::query()->create([
            'name' => 'VOLTA Report A',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:report-a',
        ]);
        $stationB = Station::query()->create([
            'name' => 'VOLTA Report B',
            'location' => 'Balti',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:report-b',
        ]);

        $dayOne = now()->startOfMonth()->addDays(2)->setTime(14, 0);
        $dayTwo = now()->startOfMonth()->addDays(5)->setTime(11, 0);

        $sessionA = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $stationA->id,
            'start_time' => $dayOne->copy()->subHour(),
            'end_time' => $dayOne,
            'kwh_consumed' => 3.5,
        ]);
        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $stationB->id,
            'start_time' => $dayOne->copy()->subMinutes(40),
            'end_time' => $dayOne->copy()->addMinutes(10),
            'kwh_consumed' => 1.2,
        ]);
        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $stationA->id,
            'start_time' => $dayTwo->copy()->subHour(),
            'end_time' => $dayTwo,
            'kwh_consumed' => 2.0,
        ]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'source_session_id' => $sessionA->id,
            'month' => now()->format('Y-m'),
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => 'VE-REP-1',
            'period_start' => $dayOne->toDateString(),
            'period_end' => $dayOne->toDateString(),
            'total_kwh' => 3.5,
            'total_amount' => 14,
            'sessions_count' => 1,
            'status' => 'paid',
            'paid_at' => $dayOne,
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->get('/backoffice/reports/stations/monthly?month=' . now()->format('Y-m'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_wallet_topups_report_returns_pdf(): void
    {
        $admin = $this->createAdminUser(['email' => 'admin.topups@example.test']);
        $user = $this->createAppUser(['email' => 'driver.topups@example.test']);

        WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'amount_refunded' => 10,
            'currency' => 'MDL',
            'status' => 'paid',
            'payment_provider' => 'maib',
            'paid_at' => now(),
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->get('/backoffice/reports/wallet-topups?from=' . now()->format('Y-m-d') . '&to=' . now()->format('Y-m-d'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
