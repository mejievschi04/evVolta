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
        $invoices = Invoice::query()
            ->with('user:id,name')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

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
}
