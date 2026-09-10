<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\WalletTopup;
use Illuminate\Mail\Mailable;

class WalletTopupConfirmationMail extends Mailable
{
    public function __construct(
        public readonly WalletTopup $topup,
        public readonly ?Invoice $invoice,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Confirmare plata wallet-topup-'.$this->topup->id.' - V CHARGE')
            ->view('emails.wallet-topup-confirmation');
    }
}
