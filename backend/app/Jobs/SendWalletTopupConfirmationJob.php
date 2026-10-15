<?php

namespace App\Jobs;

use App\Mail\WalletTopupConfirmationMail;
use App\Models\WalletTopup;
use App\Services\TopupConfirmationDocumentService;
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

    // Declared with a default (not promoted) so jobs queued before this field existed still unserialize.
    public ?float $balanceAfter = null;

    public function __construct(
        public readonly int $topupId,
        ?float $balanceAfter = null,
    ) {
        $this->balanceAfter = $balanceAfter;
    }

    public function handle(TopupConfirmationDocumentService $documents): void
    {
        $topup = WalletTopup::query()->with('user')->find($this->topupId);

        if (! $topup?->user?->email) {
            return;
        }

        $details = $documents->data($topup, $this->balanceAfter);
        $pdf = $documents->pdf($topup, $this->balanceAfter);

        Mail::to($topup->user->email, $topup->user->name)
            ->send(new WalletTopupConfirmationMail($topup, $details, $pdf, $documents->filename($topup)));
    }
}
