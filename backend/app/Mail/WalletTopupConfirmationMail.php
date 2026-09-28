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
        private readonly ?string $attachmentPdf = null,
        private readonly ?string $attachmentName = null,
    ) {
    }

    public function build(): self
    {
        $mail = $this
            ->subject(
                $this->invoice?->invoice_number
                    ? 'Factura '.$this->invoice->invoice_number.' - alimentare V CHARGE'
                    : 'Confirmare alimentare wallet-topup-'.$this->topup->id.' - V CHARGE'
            )
            ->view('emails.wallet-topup-confirmation');

        if ($this->attachmentPdf !== null && $this->attachmentName !== null) {
            $mail->attachData($this->attachmentPdf, $this->attachmentName, [
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }
}
