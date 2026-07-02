<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class OcppStopTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stop_transaction_finalizes_session_with_meter_values(): void
    {
        $user = User::query()->create([
            'name' => 'Driver',
            'email' => 'driver@example.test',
            'password' => bcrypt('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'meter_value_kwh' => 1.5,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => '21',
            'start_time' => now()->subMinutes(8),
            'meter_start_kwh' => 1.0,
            'kwh_consumed' => 0.4,
        ]);

        $command = app(OcppServe::class);
        $method = (new ReflectionClass($command))->getMethod('onStopTransaction');
        $method->setAccessible(true);

        $response = $method->invoke(
            $command,
            $station,
            [
                'transactionId' => 0,
                'connectorId' => 2,
                'meterStop' => 1450,
                'timestamp' => now()->toIso8601String(),
                'reason' => 'SoftReset',
                'idTag' => 'A5CD0CBD',
            ],
            app(\App\Services\BillingService::class)
        );

        $session->refresh();

        $this->assertSame(['idTagInfo' => ['status' => 'Accepted']], $response);
        $this->assertNotNull($session->end_time);
        $this->assertSame(0.45, (float) $session->kwh_consumed);
        $this->assertSame(1.45, (float) $session->meter_stop_kwh);
        $this->assertSame('app', $session->stop_source);
        $this->assertSame('SoftReset', $session->ocpp_stop_reason);
        $this->assertIsArray($session->ocpp_stop_context);
        $this->assertSame('StopTransaction', $session->ocpp_stop_context['trigger'] ?? null);
    }

    public function test_stop_transaction_other_reason_sets_ocpp_stop_source(): void
    {
        $user = User::query()->create([
            'name' => 'Driver',
            'email' => 'other-reason@example.test',
            'password' => bcrypt('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA dual',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010068',
            'meter_value_kwh' => 2.0,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => '42',
            'start_time' => now()->subMinutes(12),
            'meter_start_kwh' => 1.0,
            'kwh_consumed' => 0.8,
        ]);

        $command = app(OcppServe::class);
        $method = (new ReflectionClass($command))->getMethod('onStopTransaction');
        $method->setAccessible(true);

        $method->invoke(
            $command,
            $station,
            [
                'transactionId' => 42,
                'connectorId' => 2,
                'meterStop' => 1800,
                'timestamp' => now()->toIso8601String(),
                'reason' => 'Other',
            ],
            app(\App\Services\BillingService::class)
        );

        $session->refresh();

        $this->assertNotNull($session->end_time);
        $this->assertSame('ocpp', $session->stop_source);
        $this->assertSame('Other', $session->ocpp_stop_reason);
    }

    public function test_ghost_stop_transaction_on_pending_session_is_ignored(): void
    {
        $user = User::query()->create([
            'name' => 'Driver',
            'email' => 'pending@example.test',
            'password' => bcrypt('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_id_tag' => 'A5CD0CBD',
            'start_time' => now()->subMinute(),
            'kwh_consumed' => 0,
        ]);

        $command = app(OcppServe::class);
        $method = (new ReflectionClass($command))->getMethod('onStopTransaction');
        $method->setAccessible(true);

        $response = $method->invoke(
            $command,
            $station,
            [
                'transactionId' => 0,
                'connectorId' => 2,
                'meterStop' => 1008,
                'timestamp' => now()->toIso8601String(),
                'reason' => 'SoftReset',
                'idTag' => 'A5CD0CBD',
            ],
            app(\App\Services\BillingService::class)
        );

        $session->refresh();

        $this->assertSame(['idTagInfo' => ['status' => 'Accepted']], $response);
        $this->assertNull($session->end_time);
        $this->assertNull($session->ocpp_transaction_id);
    }
}
