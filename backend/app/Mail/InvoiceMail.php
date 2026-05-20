<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class InvoiceMail extends Mailable
{
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $bodyHtml,
        private readonly string $attachmentHtml,
        private readonly string $attachmentName,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject($this->subjectLine)
            ->html($this->bodyHtml)
            ->attachData($this->attachmentHtml, $this->attachmentName, [
                'mime' => 'text/html',
            ]);
    }
}
