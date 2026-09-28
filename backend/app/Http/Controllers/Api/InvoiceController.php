<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceDocumentService;
use App\Services\UsageStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    public function index(Request $request, UsageStatisticsService $usageStatisticsService): JsonResponse
    {
        $user = $request->user();
        $statistics = $usageStatisticsService->forUser($user);

        $invoices = Invoice::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(100)
            ->get([
                'id',
                'invoice_type',
                'invoice_number',
                'source_session_id',
                'month',
                'currency',
                'period_start',
                'period_end',
                'total_kwh',
                'total_amount',
                'sessions_count',
                'status',
                'payment_provider',
                'payment_session_id',
                'paid_at',
                'created_at',
            ]);

        $unpaid = $invoices->where('status', '!=', 'paid');

        return response()->json([
            'invoices' => $invoices,
            'summary' => [
                'billing_model' => 'prepay',
                'currency' => $statistics['currency'],
                'issue_schedule' => 'Primesti factura dupa fiecare plata: alimentare wallet sau sesiune incheiata.',
                'paid_count' => $invoices->where('status', 'paid')->count(),
                'unpaid_count' => $unpaid->count(),
                'outstanding_amount' => round((float) $unpaid->sum('total_amount'), 2),
            ],
            'statistics' => $statistics,
        ]);
    }

    public function download(Request $request, Invoice $invoice, InvoiceDocumentService $invoiceDocumentService): Response
    {
        $this->authorizeInvoice($request, $invoice);

        return response($invoiceDocumentService->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $invoiceDocumentService->filename($invoice) . '"',
        ]);
    }

    private function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        if ((int) $invoice->user_id !== (int) $request->user()->id) {
            abort(Response::HTTP_FORBIDDEN, 'Nu ai acces la aceasta factura.');
        }
    }
}
