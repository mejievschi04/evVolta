<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackofficeStationDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_can_load_station_detail_with_connectors_and_active_session(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now()->subSeconds(12),
            'last_ocpp_message_at' => now()->subSeconds(5),
            'meter_value_kwh' => 0.101,
            'ocpp_configuration' => [
                'chargePointVendor' => 'EU',
                'chargePointModel' => 'EU1060',
                'firmwareVersion' => '1.0.3',
                'chargePointSerialNumber' => '5D419400481F59D750010067',
                'local_id_tags' => [2 => 'A5CD0CBD'],
                'connectors' => [
                    2 => [
                        'connectorId' => 2,
                        'status' => 'Charging',
                        'live_meter' => [
                            'energy_kwh' => 0.101,
                            'power_kw' => 3.52,
                            'current_a' => 14.9,
                            'voltage_v' => 236.1,
                            'sampled_at' => now()->toIso8601String(),
                        ],
                    ],
                ],
            ],
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => '44',
            'start_time' => now()->subMinutes(3),
            'kwh_consumed' => 0.101,
            'live_metrics' => [
                'energy_kwh' => 0.101,
                'power_kw' => 3.52,
            ],
        ]);

        $response = $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson("/backoffice/stations/{$station->id}")
            ->assertOk()
            ->assertJsonPath('data.station.name', 'VOLTA 1')
            ->assertJsonPath('data.hardware.firmware', '1.0.3')
            ->assertJsonPath('data.connectors.0.id', 2)
            ->assertJsonPath('data.connectors.0.local_id_tag', 'A5CD0CBD')
            ->assertJsonPath('data.connectors.0.telemetry.power_kw', 3.52)
            ->assertJsonPath('data.active_sessions.0.id', $session->id)
            ->assertJsonPath('data.live_status.connection_status', Station::OCPP_CONNECTION_CONNECTED);

        $this->assertNotEmpty($response->json('data.station.ocpp_connection_url'));
    }

    public function test_station_detail_includes_diagnostics_commands(): void
    {
        $admin = $this->createAdminUser();

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'message_uid' => 'diag-uid-2',
            'action' => 'GetDiagnostics',
            'status' => OcppCommand::STATUS_ACCEPTED,
            'payload' => [
                'location' => 'ftp://diagnostics.local/evolta/',
            ],
            'response_payload' => [
                'fileName' => 'charger-log.tar.gz',
                'upload_status' => 'Uploaded',
            ],
            'acknowledged_at' => now(),
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->getJson("/backoffice/stations/{$station->id}")
            ->assertOk()
            ->assertJsonPath('data.diagnostics_commands.0.file_name', 'charger-log.tar.gz')
            ->assertJsonPath('data.diagnostics_commands.0.upload_status', 'Uploaded')
            ->assertJsonPath('data.diagnostics_commands.0.download_url', 'ftp://diagnostics.local/evolta/charger-log.tar.gz');
    }
}
