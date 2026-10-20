<?php

namespace Tests\Feature;

use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BackofficeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_recipients_are_saved_validated_and_used_by_daily_command(): void
    {
        $admin = $this->createAdminUser();
        $this->withSession(['backoffice_user_id' => $admin->id]);
        $settings = [
            'price_per_kwh' => 4, 'personal_price_per_kwh' => 2,
            'daily_reports_enabled' => true,
            'daily_report_recipients' => ['one@example.test', 'two@example.test', 'three@example.test', 'four@example.test'],
        ];
        $this->postJson('/backoffice/settings', $settings)->assertOk();
        $this->assertSame($settings['daily_report_recipients'], \App\Models\ReportSetting::current()['recipients']);
        $this->getJson('/backoffice/dashboard')->assertOk()->assertJsonPath('dailyReports.enabled', true)
            ->assertJsonCount(4, 'dailyReports.recipients');
        \Illuminate\Support\Facades\Queue::fake();
        config(['reports.daily_enabled' => false, 'reports.recipients' => []]);
        $this->artisan('reports:send-daily')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendDailyReportsJob::class, 4);
        $this->postJson('/backoffice/settings', array_replace($settings, ['daily_report_recipients' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('daily_report_recipients');
        $this->postJson('/backoffice/settings', array_replace($settings, ['daily_report_recipients' => ['invalid']]))
            ->assertUnprocessable()->assertJsonValidationErrors('daily_report_recipients.0');
        $this->postJson('/backoffice/settings', array_replace($settings, ['daily_report_recipients' => ['one@example.test', 'ONE@example.test']]))
            ->assertUnprocessable()->assertJsonValidationErrors('daily_report_recipients.0');
        $this->assertCount(4, \App\Models\ReportSetting::current()['recipients']);
        $this->postJson('/backoffice/settings', array_replace($settings, ['daily_reports_enabled' => false, 'daily_report_recipients' => []]))->assertOk();
        $this->assertFalse(\App\Models\ReportSetting::current()['enabled']);
        $this->assertSame([], \App\Models\ReportSetting::current()['recipients']);
    }

    public function test_backoffice_settings_update_profile_and_tariff_keeps_mdl(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
            'currency' => 'MDL',
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->post('/backoffice/settings', [
                'first_name' => 'Ana',
                'last_name' => 'Popescu',
                'currency' => 'EUR',
                'price_per_kwh' => 0.4300,
                'personal_price_per_kwh' => 0.1500,
            ])
            ->assertStatus(302)
            ->assertSessionHas('backoffice_user_name', 'Ana Popescu');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'first_name' => 'Ana',
            'last_name' => 'Popescu',
            'currency' => 'MDL',
            'name' => 'Ana Popescu',
        ]);

        $this->assertDatabaseHas('tariffs', [
            'price_per_kwh' => 0.43,
            'personal_price_per_kwh' => 0.15,
        ]);

        $this->assertSame(1, Tariff::query()->count());
        $this->assertNotNull(Route::getRoutes()->getByName('backoffice.settings.update'));
        $this->assertSame(url('/backoffice/settings'), route('backoffice.settings.update'));
    }
}
