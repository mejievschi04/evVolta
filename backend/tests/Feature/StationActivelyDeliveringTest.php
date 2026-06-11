<?php

namespace Tests\Feature;

use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationActivelyDeliveringTest extends TestCase
{
    use RefreshDatabase;

    public function test_actively_delivering_connector_ids_follow_ocpp_status(): void
    {
        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Charging'],
                ],
            ],
        ]);

        $this->assertSame([2], $station->activelyDeliveringConnectorIds());
    }
}
