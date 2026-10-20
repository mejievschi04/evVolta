<?php

namespace Tests\Feature;

use App\Jobs\SendDailyReportsJob;
use App\Mail\DailyReportsMail;
use App\Services\ReportDocumentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DailyReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_or_invalid_configuration_does_not_dispatch(): void
    {
        Queue::fake();
        config(['reports.daily_enabled' => false]);
        $this->artisan('reports:send-daily')->assertSuccessful();
        config(['reports.daily_enabled' => true, 'reports.recipients' => []]);
        $this->artisan('reports:send-daily')->assertFailed();
        config(['reports.recipients' => ['invalid']]);
        $this->artisan('reports:send-daily')->assertFailed();
        config(['reports.recipients' => ['reports@example.test']]);
        $this->artisan('reports:send-daily', ['--date' => '2026-02-30'])->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_reports_use_previous_local_day_and_deduplicate_recipients(): void
    {
        Queue::fake();
        config(['reports.daily_enabled' => true, 'reports.recipients' => ['reports@example.test', 'REPORTS@example.test']]);
        $this->travelTo(Carbon::parse('2026-10-20 04:00:00', 'UTC'));
        $this->artisan('reports:send-daily')->assertSuccessful();
        Queue::assertPushed(SendDailyReportsJob::class, fn ($job) => $job->date === '2026-10-19' && $job->recipient === 'reports@example.test');
        Queue::assertPushed(SendDailyReportsJob::class, 1);
    }

    public function test_both_excel_files_are_attached_and_delivery_is_not_repeated(): void
    {
        Mail::fake();
        Cache::flush();
        $job = new SendDailyReportsJob('2026-10-19', 'reports@example.test');
        $job->handle(app(ReportDocumentService::class));
        $job->handle(app(ReportDocumentService::class));
        Mail::assertSent(DailyReportsMail::class, function ($mail) {
            $mail->build();
            $this->assertSame('19.10.2026', $mail->reportDate);
            $this->assertCount(2, $mail->rawAttachments);
            $this->assertSame('raport-alimentari-masini-20261019-20261019.xlsx', $mail->rawAttachments[0]['name']);
            $this->assertSame('raport-alimentari-20261019-20261019.xlsx', $mail->rawAttachments[1]['name']);
            foreach ($mail->rawAttachments as $attachment) {
                $this->assertStringStartsWith('PK', $attachment['data']);
            }
            $this->assertStringContainsString('19.10.2026', $mail->render());
            return $mail->hasTo('reports@example.test');
        });
        Mail::assertSentCount(1);
    }

    public function test_failed_delivery_can_be_retried(): void
    {
        Cache::flush();
        $manager = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $job = new SendDailyReportsJob('2026-10-18', 'retry@example.test');
        try {
            $job->handle(app(ReportDocumentService::class));
            $this->fail('Delivery should fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP unavailable', $exception->getMessage());
        }
        Mail::swap($manager);
        Mail::fake();
        $job->handle(app(ReportDocumentService::class));
        Mail::assertSentCount(1);
    }
}
