<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_checkout_session_is_created_for_the_authenticated_user(): void
    {
        $user = $this->createAppUser([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'currency' => 'MDL',
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'month' => '2026-04',
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => 'EVS-1',
            'source_session_id' => null,
            'total_amount' => 12.50,
            'total_kwh' => 25.00,
            'sessions_count' => 1,
            'status' => 'unpaid',
        ]);

        $this->mock(StripePaymentService::class, function ($mock) use ($invoice) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->andReturn([
                    'id' => 'cs_test_123',
                    'url' => 'https://stripe.test/checkout/cs_test_123',
                    'status' => 'open',
                    'payment_status' => 'unpaid',
                    'client_reference_id' => (string) $invoice->id,
                    'metadata' => [],
                ]);
        });

        $this->actingAs($user, 'api')
            ->postJson('/api/invoices/' . $invoice->id . '/checkout-session')
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://stripe.test/checkout/cs_test_123')
            ->assertJsonPath('session_id', 'cs_test_123');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'payment_provider' => 'stripe',
            'payment_session_id' => 'cs_test_123',
        ]);
    }

    public function test_invoice_can_be_marked_paid_after_verification(): void
    {
        $user = $this->createAppUser([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'currency' => 'MDL',
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'month' => '2026-04',
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => 'EVS-2',
            'source_session_id' => null,
            'payment_provider' => 'stripe',
            'payment_session_id' => 'cs_test_123',
            'total_amount' => 12.50,
            'total_kwh' => 25.00,
            'sessions_count' => 1,
            'status' => 'unpaid',
        ]);

        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('retrieveCheckoutSession')
                ->once()
                ->with('cs_test_123')
                ->andReturn([
                    'id' => 'cs_test_123',
                    'url' => 'https://stripe.test/checkout/cs_test_123',
                    'status' => 'complete',
                    'payment_status' => 'paid',
                    'client_reference_id' => '1',
                    'metadata' => [],
                ]);
        });

        $this->actingAs($user, 'api')
            ->postJson('/api/invoices/' . $invoice->id . '/verify-payment')
            ->assertOk()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('invoice.status', 'paid');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'paid',
            'payment_session_id' => 'cs_test_123',
        ]);

        $this->assertNotNull(Invoice::query()->findOrFail($invoice->id)->paid_at);
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
