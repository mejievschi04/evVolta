<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Models\WalletTopup;
use RuntimeException;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Stripe;

class StripePaymentService
{
    public function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    public function createCheckoutSession(Invoice $invoice, User $user): array
    {
        $secret = config('services.stripe.secret');

        if (! $secret) {
            throw new RuntimeException('Plata cu cardul nu este configurata. Contacteaza administratorul.');
        }

        $currency = strtolower($invoice->currency ?: ($user->currency ?: 'MDL'));
        $amountMinor = $this->toMinorUnits((float) $invoice->total_amount, $currency);

        Stripe::setApiKey($secret);

        $payload = [
            'mode' => 'payment',
            'client_reference_id' => (string) $invoice->id,
            'success_url' => route('payments.stripe.success', ['invoice_id' => $invoice->id]) . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('payments.stripe.cancel', [
                'invoice_id' => $invoice->id,
            ]),
            'metadata' => [
                'invoice_id' => (string) $invoice->id,
                'invoice_number' => (string) ($invoice->invoice_number ?? ''),
                'user_id' => (string) $invoice->user_id,
            ],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $amountMinor,
                    'product_data' => [
                        'name' => $this->buildDescription($invoice),
                    ],
                ],
            ]],
        ];

        if ($user->email) {
            $payload['customer_email'] = $user->email;
        }

        $session = CheckoutSession::create($payload);

        return $this->normalizeSession($session);
    }

    public function createWalletTopupSession(WalletTopup $topup, User $user): array
    {
        $secret = config('services.stripe.secret');

        if (! $secret) {
            throw new RuntimeException('Plata cu cardul nu este configurata. Contacteaza administratorul.');
        }

        $currency = strtolower($topup->currency ?: ($user->currency ?: 'MDL'));
        $amountMinor = $this->toMinorUnits((float) $topup->amount, $currency);

        Stripe::setApiKey($secret);

        $payload = [
            'mode' => 'payment',
            'client_reference_id' => 'wallet-topup-' . $topup->id,
            'success_url' => route('payments.stripe.success', ['wallet_topup_id' => $topup->id]) . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('payments.stripe.cancel', ['wallet_topup_id' => $topup->id]),
            'metadata' => [
                'wallet_topup_id' => (string) $topup->id,
                'user_id' => (string) $user->id,
                'kind' => 'wallet_topup',
            ],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $amountMinor,
                    'product_data' => [
                        'name' => 'Alimentare cont Volta EV',
                    ],
                ],
            ]],
        ];

        if ($user->email) {
            $payload['customer_email'] = $user->email;
        }

        $session = CheckoutSession::create($payload);

        return $this->normalizeSession($session);
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        $secret = config('services.stripe.secret');

        if (! $secret) {
            throw new RuntimeException('Configuratia Stripe lipseste.');
        }

        Stripe::setApiKey($secret);

        return $this->normalizeSession(CheckoutSession::retrieve($sessionId));
    }

    private function normalizeSession(CheckoutSession $session): array
    {
        $metadata = $session->metadata;

        if (is_object($metadata) && method_exists($metadata, 'toArray')) {
            $metadata = $metadata->toArray();
        }

        return [
            'id' => $session->id,
            'url' => $session->url,
            'status' => $session->status,
            'payment_status' => $session->payment_status,
            'client_reference_id' => $session->client_reference_id,
            'metadata' => is_array($metadata) ? $metadata : (array) $metadata,
        ];
    }

    private function buildDescription(Invoice $invoice): string
    {
        $label = $invoice->invoice_type === 'session' ? 'Factura de sesiune' : 'Factura lunara';

        if ($invoice->invoice_number) {
            return $label . ' ' . $invoice->invoice_number;
        }

        return $label . ' #' . $invoice->id;
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        $currency = strtoupper($currency);

        if ($this->isZeroDecimalCurrency($currency)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }

    private function isZeroDecimalCurrency(string $currency): bool
    {
        return in_array($currency, [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF',
            'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ], true);
    }
}
