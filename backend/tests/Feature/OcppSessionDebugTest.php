<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcppMessage;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OcppSessionDebugTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_session_ocpp_debug_returns_timeline_and_hypothesis(): void
    {
        $admin = $this->createAdminUser();
        $user = User::query()->create([
            'name' => 'Driver',
            'email' => 'debug-driver@example.test',
            'password' => bcrypt('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010099',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'ocpp_configuration' => [
                'NumberOfConnectors' => 2,
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available', 'errorCode' => 'NoError'],
                    2 => ['connectorId' => 2, 'status' => 'Available', 'errorCode' => 'NoError'],
                ],
            ],
        ]);

        $start = now()->subMinutes(15);
        $end = now()->subMinutes(2);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => '88',
            'start_time' => $start,
            'end_time' => $end,
            'kwh_consumed' => 3.4,
            'stop_source' => 'ocpp',
            'ocpp_stop_reason' => 'Other',
            'ocpp_stop_context' => [
                'trigger' => 'StopTransaction',
                'session_connector_id' => 2,
            ],
        ]);

        OcppMessage::query()->create([
            'station_id' => $station->id,
            'direction' => 'inbound',
            'action' => 'StatusNotification',
            'status' => 'received',
            'payload' => [
                'connectorId' => 1,
                'status' => 'Preparing',
                'errorCode' => 'NoError',
            ],
            'received_at' => $end->copy()->subSeconds(20),
            'created_at' => $end->copy()->subSeconds(20),
        ]);

        OcppMessage::query()->create([
            'station_id' => $station->id,
            'direction' => 'inbound',
            'action' => 'StatusNotification',
            'status' => 'received',
            'payload' => [
                'connectorId' => 1,
                'status' => 'Available',
                'errorCode' => 'NoError',
            ],
            'received_at' => $end->copy()->subSeconds(8),
            'created_at' => $end->copy()->subSeconds(8),
        ]);

        OcppMessage::query()->create([
            'station_id' => $station->id,
            'direction' => 'inbound',
            'action' => 'StopTransaction',
            'status' => 'received',
            'payload' => [
                'connectorId' => 2,
                'transactionId' => 88,
                'reason' => 'Other',
                'meterStop' => 3400,
            ],
            'received_at' => $end,
            'created_at' => $end,
        ]);

        $response = $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson("/backoffice/sessions/{$session->id}/ocpp-debug")
            ->assertOk()
            ->assertJsonPath('data.session.id', $session->id)
            ->assertJsonPath('data.session.ocpp_stop_reason', 'Other');

        $timeline = $response->json('data.timeline');
        $this->assertIsArray($timeline);
        $this->assertGreaterThanOrEqual(3, count($timeline));

        $hypothesis = (string) $response->json('data.analysis.hypothesis');
        $this->assertStringContainsString('reason=Other', $hypothesis);
        $this->assertStringContainsString('alt conector', $hypothesis);
    }
}
