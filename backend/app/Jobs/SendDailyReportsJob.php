<?php

namespace App\Jobs;

use App\Mail\DailyReportsMail;
use App\Services\ReportDocumentService;
use App\Support\LocalTime;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SendDailyReportsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 110;
    public int $uniqueFor = 86400;

    public function __construct(public readonly string $date, public readonly string $recipient)
    {
    }

    public function uniqueId(): string
    {
        return $this->date.':'.hash('sha256', strtolower($this->recipient));
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ReportDocumentService $reports): void
    {
        $key = 'daily-reports:sent:'.$this->uniqueId();
        if (Cache::has($key)) {
            return;
        }

        $day = LocalTime::day($this->date);
        Mail::to($this->recipient)->send(new DailyReportsMail($day->format('d.m.Y'), [
            $reports->chargingSessions($day->copy(), $day->copy()),
            $reports->walletTopups($day->copy(), $day->copy()),
        ]));
        Cache::put($key, true, now()->addDays(35));
    }
}
