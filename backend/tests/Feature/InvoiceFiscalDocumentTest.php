<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Services\InvoiceDocumentService;
use App\Services\InvoiceFiscalCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceFiscalDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiscal_calculator_splits_vat_inclusive_amount(): void
    {
        config([
            'invoice.vat_rate' => 20,
            'invoice.vat_included' => true,
        ]);

        $result = app(InvoiceFiscalCalculator::class)->breakdown(120.00, 10.0);

        $this->assertSame(20.0, $result['vat_rate']);
        $this->assertSame(100.0, $result['amount_net']);
        $this->assertSame(20.0, $result['amount_vat']);
        $this->assertSame(120.0, $result['amount_gross']);
        $this->assertSame(10.0, $result['unit_price']);
    }

    public function test_invoice_html_contains_mandatory_fiscal_elements(): void
    {
        config([
            'invoice.vat_rate' => 20,
            'invoice.vat_included' => true,
            'invoice.seller.name' => 'V CHARGE SRL',
            'invoice.seller.address' => 'str. Exemplu 1, Chisinau',
            'invoice.seller.idno' => '1002600000000',
            'invoice.seller.vat_code' => '0200000',
            'invoice.seller.iban' => 'MD24AG000000022251234567',
            'invoice.seller.bank' => 'MAIB',
            'invoice.seller.email' => 'support@volta.md',
            'invoice.seller.phone' => '+373 22 000 000',
        ]);

        $user = $this->createAppUser([
            'name' => 'Ion Popescu',
            'email' => 'ion@exemplu.com',
            'currency' => 'MDL',
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'month' => '2026-07',
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => '0000001',
            'period_start' => '2026-07-25',
            'period_end' => '2026-07-25',
            'total_kwh' => 10,
            'total_amount' => 120,
            'sessions_count' => 1,
            'line_description' => 'Servicii de incarcare vehicul electric · Statie Centru · 10.000 kWh',
            'unit' => 'kWh',
            'quantity' => 10,
            'unit_price' => 10,
            'vat_rate' => 20,
            'amount_net' => 100,
            'amount_vat' => 20,
            'buyer_name' => 'Ion Popescu',
            'buyer_email' => 'ion@exemplu.com',
            'seller_name' => 'V CHARGE SRL',
            'seller_idno' => '1002600000000',
            'seller_vat_code' => '0200000',
            'status' => 'paid',
            'issued_at' => now(),
            'paid_at' => now(),
        ]);

        $html = app(InvoiceDocumentService::class)->html($invoice);

        $this->assertStringContainsString('Furnizor', $html);
        $this->assertStringContainsString('Cumparator', $html);
        $this->assertStringContainsString('1002600000000', $html);
        $this->assertStringContainsString('Denumirea serviciului', $html);
        $this->assertStringContainsString('Pret unitar', $html);
        $this->assertStringContainsString('Cota', $html);
        $this->assertStringContainsString('Valoare', $html);
        $this->assertStringContainsString('0000001', $html);
        $this->assertStringContainsString('Ion Popescu', $html);
        $this->assertStringContainsString('Data:', $html);
        $this->assertStringContainsString('Content-Security-Policy', $html);
    }

    public function test_download_endpoint_returns_fiscal_pdf(): void
    {
        config([
            'invoice.seller.name' => 'V CHARGE SRL',
            'invoice.seller.idno' => '1002600000000',
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
            'invoice_number' => '0000009',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'total_amount' => 42.50,
            'total_kwh' => 85.00,
            'sessions_count' => 4,
            'status' => 'unpaid',
        ]);

        $response = $this->actingAs($user, 'api')
            ->get('/api/invoices/'.$invoice->id.'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="0000009.pdf"');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_invoice_numbers_are_seven_digits_sequential(): void
    {
        $user = $this->createAppUser(['email' => 'seq@example.test']);
        $station = \App\Models\Station::query()->create([
            'name' => 'VOLTA SEQ',
            'location' => 'Chisinau',
            'status' => \App\Models\Station::STATUS_AVAILABLE,
            'qr_code' => 'station:seq-1',
        ]);

        $sessionOne = \App\Models\ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHour(),
            'kwh_consumed' => 2,
        ]);
        $sessionTwo = \App\Models\ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now()->subHour(),
            'end_time' => now(),
            'kwh_consumed' => 3,
        ]);

        $first = app(\App\Services\InvoiceIssuanceService::class)->createSessionInvoice($sessionOne, 8.0);
        $second = app(\App\Services\InvoiceIssuanceService::class)->createSessionInvoice($sessionTwo, 12.0);

        $this->assertSame('0000001', $first?->invoice_number);
        $this->assertSame('0000002', $second?->invoice_number);
    }
}
