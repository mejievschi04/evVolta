<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceDocumentService;
use App\Services\StripePaymentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Invoice::query()->where('user_id', $user->id);

        if ($user->usesCardPayment()) {
            $query->where('invoice_type', 'session');
        } elseif ($user->usesMonthlyBilling()) {
            $query->where('invoice_type', 'monthly');
        }

        $invoices = $query
            ->orderByDesc('id')
            ->get()
            ->map(function (Invoice $invoice) use ($user) {
                $invoice->setAttribute('can_pay_online', $user->usesCardPayment() && $invoice->invoice_type === 'session');

                return $invoice;
            });

        return response()->json($invoices);
    }

    public function download(Request $request, Invoice $invoice, InvoiceDocumentService $invoiceDocumentService): Response
    {
        $this->authorizeInvoice($request, $invoice);

        return response($invoiceDocumentService->html($invoice), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $invoiceDocumentService->filename($invoice) . '"',
        ]);
    }

    public function createCheckoutSession(Request $request, Invoice $invoice, StripePaymentService $stripePaymentService): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $this->assertOnlinePaymentAllowed($request->user(), $invoice);

        if ($invoice->status === 'paid') {
            return response()->json([
                'message' => 'Factura este deja platita.',
                'invoice' => $invoice,
            ]);
        }

        try {
            $session = $stripePaymentService->createCheckoutSession($invoice, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $invoice->update([
            'payment_provider' => 'stripe',
            'payment_session_id' => $session['id'],
        ]);

        return response()->json([
            'message' => 'Checkout-ul de plata a fost creat.',
            'checkout_url' => $session['url'],
            'session_id' => $session['id'],
            'invoice' => $invoice->fresh(),
        ]);
    }

    public function verifyPayment(Request $request, Invoice $invoice, StripePaymentService $stripePaymentService): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $this->assertOnlinePaymentAllowed($request->user(), $invoice);

        if (! $invoice->payment_session_id) {
            return response()->json([
                'message' => 'Factura nu are o sesiune de plata Stripe asociata.',
                'invoice' => $invoice,
            ], 422);
        }

        try {
            $session = $stripePaymentService->retrieveCheckoutSession($invoice->payment_session_id);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if (($session['payment_status'] ?? null) === 'paid') {
            $invoice->update([
                'status' => 'paid',
                'paid_at' => Carbon::now(),
                'payment_provider' => 'stripe',
            ]);
        }

        return response()->json([
            'message' => $invoice->status === 'paid' ? 'Plata a fost confirmata.' : 'Plata este inca in curs de procesare.',
            'payment_status' => $session['payment_status'] ?? 'unpaid',
            'session_status' => $session['status'] ?? 'open',
            'invoice' => $invoice->fresh(),
        ]);
    }

    private function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        if ((int) $invoice->user_id !== (int) $request->user()->id) {
            abort(Response::HTTP_FORBIDDEN, 'Nu ai acces la aceasta factura.');
        }
    }

    private function assertOnlinePaymentAllowed(\App\Models\User $user, Invoice $invoice): void
    {
        if (! $user->usesCardPayment() || $invoice->invoice_type !== 'session') {
            abort(Response::HTTP_FORBIDDEN, 'Plata online cu cardul este disponibila doar pentru facturile de sesiune ale clientilor.');
        }
    }
}
