<?php

namespace Tests\Feature;

use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationDualConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_expected_connector_count_defaults_to_two_for_eu1060(): void
    {
        $station = Station::query()->create([
            'name' => 'VOLTA dual',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_configuration' => [
                'chargePointModel' => 'EU1060_TYPE_II',
                'connectors' => [
                    2 => ['connectorId' => 2, 'status' => 'Charging'],
                ],
            ],
        ]);

        $this->assertSame(2, $station->expectedConnectorCount());
        $this->assertSame([1, 2], $station->expectedConnectorIds());

        $live = $station->liveStatus();
        $this->assertCount(2, $live['connectors']);
        $this->assertSame(1, $live['connectors'][0]['id']);
        $this->assertSame(2, $live['connectors'][1]['id']);
        $this->assertSame('Charging', $live['connectors'][1]['status']);
    }

    public function test_live_status_includes_per_connector_telemetry(): void
    {
        $station = Station::query()->create([
            'name' => 'VOLTA dual',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_configuration' => [
                'NumberOfConnectors' => 2,
                'connectors' => [
                    1 => [
                        'connectorId' => 1,
                        'status' => 'Available',
                        'live_meter' => ['power_kw' => 0, 'energy_kwh' => 0],
                    ],
                    2 => [
                        'connectorId' => 2,
                        'status' => 'Charging',
                        'live_meter' => ['power_kw' => 3.52, 'energy_kwh' => 0.101],
                    ],
                ],
            ],
        ]);

        $byId = collect($station->liveStatus()['connectors'])->keyBy('id');

        $this->assertSame(0.0, $byId[1]['power_kw']);
        $this->assertSame(3.52, $byId[2]['power_kw']);
        $this->assertSame(0.101, $byId[2]['energy_kwh']);
    }
}
