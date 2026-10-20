<?php

namespace App\Console\Commands;

use App\Jobs\SendDailyReportsJob;
use App\Support\LocalTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class SendDailyReports extends Command
{
    protected $signature = 'reports:send-daily {--date= : Ziua raportului, YYYY-MM-DD; implicit ieri}';
    protected $description = 'Queue the two daily V Charge Excel reports for configured recipients';

    public function handle(): int
    {
        $settings = \App\Models\ReportSetting::current();
        if (! $settings['enabled']) {
            $this->info('Trimiterea rapoartelor zilnice este dezactivata.');
            return self::SUCCESS;
        }

        $date = $this->option('date') ?? LocalTime::now()->subDay()->toDateString();
        $recipients = array_values(array_unique(array_map('strtolower', $settings['recipients'])));
        $validator = Validator::make(compact('date', 'recipients'), [
            'date' => 'required|date_format:Y-m-d',
            'recipients' => 'required|array|min:1',
            'recipients.*' => 'required|email:rfc',
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());
            return self::FAILURE;
        }

        foreach ($recipients as $recipient) {
            SendDailyReportsJob::dispatch($date, $recipient);
        }
        $this->info('Rapoarte programate pentru '.$date.': '.count($recipients).' destinatari.');
        return self::SUCCESS;
    }
}
