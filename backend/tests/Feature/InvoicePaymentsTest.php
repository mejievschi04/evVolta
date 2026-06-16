<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_checkout_session_is_not_available_in_app(): void
    {
        $user = $this->createPersonalUser([
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
            'total_amount' => 12.50,
            'total_kwh' => 25.00,
            'sessions_count' => 3,
            'status' => 'unpaid',
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/invoices/' . $invoice->id . '/checkout-session')
            ->assertForbidden();
    }

    public function test_invoice_verify_payment_is_not_available_in_app(): void
    {
        $user = $this->createPersonalUser([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'currency' => 'MDL',
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'month' => '2026-04',
            'currency' => 'MDL',
            'invoice_type' => 'monthly',
            'invoice_number' => 'EVM-202604-2',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'payment_provider' => 'stripe',
            'payment_session_id' => 'cs_test_123',
            'total_amount' => 12.50,
            'total_kwh' => 25.00,
            'sessions_count' => 3,
            'status' => 'unpaid',
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/invoices/' . $invoice->id . '/verify-payment')
            ->assertForbidden();
    }

    public function test_authenticated_user_can_download_own_invoice_document(): void
    {
        $user = $this->createAppUser([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'currency' => 'MDL',
        ]);

        $otherUser = $this->createAppUser([
            'name' => 'Driver Two',
            'email' => 'other@example.test',
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
            ->get('/api/invoices/' . $invoice->id . '/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="evm-202604-9.html"')
            ->assertSee('EVM-202604-9');

        $this->actingAs($otherUser, 'api')
            ->get('/api/invoices/' . $invoice->id . '/download')
            ->assertForbidden();
    }
}
