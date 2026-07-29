<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Services\InvoiceDocumentService;
use App\Services\InvoiceFiscalCalculator;
use App\Support\MoneyToWordsRo;
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
            'invoice.series' => 'VE',
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
            'series' => 'VE',
            'invoice_number' => 'VE-20260725-0001',
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
        $this->assertStringContainsString('IDNO', $html);
        $this->assertStringContainsString('1002600000000', $html);
        $this->assertStringContainsString('Cod TVA', $html);
        $this->assertStringContainsString('Pret unitar fara TVA', $html);
        $this->assertStringContainsString('Cota TVA', $html);
        $this->assertStringContainsString('Total TVA', $html);
        $this->assertStringContainsString('Total de plata', $html);
        $this->assertStringContainsString('VE-20260725-0001', $html);
        $this->assertStringContainsString('Ion Popescu', $html);
        $this->assertStringContainsString('art. 117', $html);
        $this->assertStringContainsString(MoneyToWordsRo::convert(120.0), $html);
        $this->assertStringContainsString('Content-Security-Policy', $html);
    }

    public function test_download_endpoint_returns_fiscal_html(): void
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
            'invoice_number' => 'EVM-202604-9',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'total_amount' => 42.50,
            'total_kwh' => 85.00,
            'sessions_count' => 4,
            'status' => 'unpaid',
        ]);

        $this->actingAs($user, 'api')
            ->get('/api/invoices/'.$invoice->id.'/download')
            ->assertOk()
            ->assertSee('Furnizor', false)
            ->assertSee('Cumparator', false)
            ->assertSee('Total TVA', false)
            ->assertSee('EVM-202604-9', false);
    }
}
