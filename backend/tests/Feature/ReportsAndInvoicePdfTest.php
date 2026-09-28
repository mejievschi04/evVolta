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

    public function test_station_daily_and_monthly_reports_return_pdf(): void
    {
        $admin = $this->createAdminUser(['email' => 'admin.reports@example.test']);
        $user = $this->createAppUser(['email' => 'driver.reports@example.test']);
        $station = Station::query()->create([
            'name' => 'VOLTA Report',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:report-1',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now()->subHour(),
            'end_time' => now(),
            'kwh_consumed' => 3.5,
        ]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'source_session_id' => $session->id,
            'month' => now()->format('Y-m'),
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => 'VE-REP-1',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'total_kwh' => 3.5,
            'total_amount' => 14,
            'sessions_count' => 1,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $sessionPayload = [
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ];

        $this->withSession($sessionPayload)
            ->get('/backoffice/reports/stations/daily?date=' . now()->format('Y-m-d'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->withSession($sessionPayload)
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
