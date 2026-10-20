<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class DailyReportsMail extends Mailable
{
    public function __construct(public readonly string $reportDate, private readonly array $reports)
    {
    }

    public function build(): self
    {
        $this->subject('V Charge — rapoarte zilnice — '.$this->reportDate)
            ->view('emails.daily-reports');

        foreach ($this->reports as $report) {
            $this->attachData($report['content'], $report['filename'], [
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return $this;
    }
}
