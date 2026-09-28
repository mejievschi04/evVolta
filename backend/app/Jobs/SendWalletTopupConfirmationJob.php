<?php

namespace App\Jobs;

use App\Mail\WalletTopupConfirmationMail;
use App\Models\Invoice;
use App\Models\WalletTopup;
use App\Services\InvoiceDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendWalletTopupConfirmationJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $topupId,
        public readonly ?int $invoiceId = null,
    ) {
    }

    public function handle(InvoiceDocumentService $invoiceDocumentService): void
    {
        $topup = WalletTopup::query()->with('user')->find($this->topupId);

        if (! $topup?->user?->email) {
            return;
        }

        $invoice = $this->invoiceId
            ? Invoice::query()->find($this->invoiceId)
            : Invoice::query()->where('wallet_topup_id', $topup->id)->first();

        $pdf = null;
        $filename = null;
        if ($invoice) {
            $pdf = $invoiceDocumentService->pdf($invoice);
            $filename = $invoiceDocumentService->filename($invoice);
        }

        Mail::to($topup->user->email, $topup->user->name)
            ->send(new WalletTopupConfirmationMail($topup, $invoice, $pdf, $filename));
    }
}
