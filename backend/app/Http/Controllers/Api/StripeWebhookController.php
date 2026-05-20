<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        if (! $secret) {
            return response()->json([
                'message' => 'Stripe webhook secret lipseste.',
            ], 422);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $secret
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $invoiceId = (int) (data_get($session, 'metadata.invoice_id') ?? data_get($session, 'client_reference_id') ?? 0);

            if ($invoiceId > 0 && data_get($session, 'payment_status') === 'paid') {
                $invoice = Invoice::query()->find($invoiceId);

                if ($invoice && $invoice->status !== 'paid') {
                    $invoice->update([
                        'status' => 'paid',
                        'paid_at' => Carbon::now(),
                        'payment_provider' => 'stripe',
                        'payment_session_id' => $session->id,
                    ]);
                }
            }
        }

        return response()->json([
            'received' => true,
        ]);
    }
}
