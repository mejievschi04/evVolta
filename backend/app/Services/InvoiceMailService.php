<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class InvoiceMailService
{
    public function __construct(
        private readonly InvoiceDocumentService $invoiceDocumentService,
    ) {
    }

    public function send(Invoice $invoice): bool
    {
        $invoice->loadMissing('user:id,name,email,currency');

        $email = filled($invoice->buyer_email)
            ? (string) $invoice->buyer_email
            : (string) ($invoice->user?->email ?? '');
        $name = filled($invoice->buyer_name)
            ? (string) $invoice->buyer_name
            : (string) ($invoice->user?->name ?? 'Client V CHARGE');

        if ($email === '') {
            return false;
        }

        $pdf = $this->invoiceDocumentService->pdf($invoice);
        $filename = $this->invoiceDocumentService->filename($invoice);
        $subject = 'Factura ' . ($invoice->invoice_number ?: '#' . $invoice->id) . ' - V CHARGE';
        $body = $this->invoiceDocumentService->emailBody($invoice);

        Mail::to($email, $name)
            ->send(new InvoiceMail($subject, $body, $pdf, $filename));

        return true;
    }

    public function sendSafely(Invoice $invoice): void
    {
        try {
            $this->send($invoice);
        } catch (Throwable $exception) {
            Log::warning('invoice.email.failed', [
                'invoice_id' => $invoice->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
