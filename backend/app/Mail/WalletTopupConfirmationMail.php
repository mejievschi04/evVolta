<?php

namespace App\Mail;

use App\Models\WalletTopup;
use Illuminate\Mail\Mailable;

class WalletTopupConfirmationMail extends Mailable
{
    /**
     * @param  array{amount: string, balance: string, name: string, email: string, paid_at: string}  $details
     */
    public function __construct(
        public readonly WalletTopup $topup,
        public readonly array $details,
        private readonly ?string $attachmentPdf = null,
        private readonly ?string $attachmentName = null,
    ) {
    }

    public function build(): self
    {
        $mail = $this
            ->subject('Confirmare suplinire cont - V CHARGE')
            ->view('emails.wallet-topup-confirmation');

        if ($this->attachmentPdf !== null && $this->attachmentName !== null) {
            $mail->attachData($this->attachmentPdf, $this->attachmentName, [
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }
}
