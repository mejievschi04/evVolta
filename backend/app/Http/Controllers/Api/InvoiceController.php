<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceDocumentService;
use App\Services\StripePaymentService;
use App\Services\UsageStatisticsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class InvoiceController extends Controller
{
    public function index(Request $request, UsageStatisticsService $usageStatisticsService): JsonResponse
    {
        $user = $request->user();

        if ($user->usesCardPayment()) {
            $statistics = $usageStatisticsService->forUser($user);

            return response()->json([
                'invoices' => [],
                'summary' => [
                    'billing_model' => 'prepay',
                    'currency' => $statistics['currency'],
                    'issue_schedule' => 'Platile se fac din wallet la fiecare sesiune. Nu se emit facturi.',
                ],
                'statistics' => $statistics,
            ]);
        }

        $invoices = Invoice::query()
            ->where('user_id', $user->id)
            ->where('invoice_type', 'monthly')
            ->orderByDesc('month')
            ->orderByDesc('id')
            ->get()
            ->map(function (Invoice $invoice) use ($user) {
                $invoice->setAttribute('can_pay_online', $this->canPayOnline($user, $invoice));

                return $invoice;
            });

        $currency = (string) ($invoices->first()?->currency ?? $user->currency ?? 'MDL');
        $unpaid = $invoices->where('status', '!=', 'paid');
        $outstandingAmount = round((float) $unpaid->sum('total_amount'), 2);

        return response()->json([
            'invoices' => $invoices->values(),
            'summary' => [
                'billing_model' => 'monthly',
                'outstanding_amount' => $outstandingAmount,
                'unpaid_count' => $unpaid->count(),
                'currency' => $currency,
                'issue_day' => 1,
                'issue_schedule' => $user->usesMonthlyBilling()
                    ? 'Factura pentru luna trecuta se emite automat in data de 1 a fiecarei luni.'
                    : 'Contul prepay foloseste wallet. Facturile lunare apar doar pentru conturile personal.',
            ],
        ]);
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

    private function canPayOnline(\App\Models\User $user, Invoice $invoice): bool
    {
        if ($invoice->status === 'paid') {
            return false;
        }

        return $user->usesMonthlyBilling() && $invoice->invoice_type === 'monthly';
    }

    private function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        if ((int) $invoice->user_id !== (int) $request->user()->id) {
            abort(Response::HTTP_FORBIDDEN, 'Nu ai acces la aceasta factura.');
        }
    }

    private function assertOnlinePaymentAllowed(\App\Models\User $user, Invoice $invoice): void
    {
        if (! $user->usesMonthlyBilling() || $invoice->invoice_type !== 'monthly') {
            abort(Response::HTTP_FORBIDDEN, 'Plata online este disponibila doar pentru facturile lunare.');
        }
    }
}
