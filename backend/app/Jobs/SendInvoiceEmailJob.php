<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\InvoiceMailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendInvoiceEmailJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $invoiceId,
    ) {
    }

    public function handle(InvoiceMailService $invoiceMailService): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if (! $invoice) {
            return;
        }

        $invoiceMailService->send($invoice);
    }
}
