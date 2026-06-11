<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Services\QrStationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrStationResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_hardware_serial_from_plain_qr_code(): void
    {
        $station = Station::query()->create([
            'name' => 'Statie reala',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => '419400481F59D7',
            'ocpp_identity' => '419400481F59D7',
        ]);

        $resolver = app(QrStationResolver::class);
        $result = $resolver->resolve('419400481F59D7');

        $this->assertNotNull($result);
        $this->assertSame($station->id, $result['station']->id);
        $this->assertSame(1, $result['connector_id']);
    }

    public function test_it_resolves_connector_b_suffix(): void
    {
        $station = Station::query()->create([
            'name' => 'Statie duala',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => '419400481F59D7',
            'ocpp_identity' => '419400481F59D7',
        ]);

        $resolver = app(QrStationResolver::class);
        $result = $resolver->resolve('419400481F59D7-B');

        $this->assertNotNull($result);
        $this->assertSame($station->id, $result['station']->id);
        $this->assertSame(2, $result['connector_id']);
    }

    public function test_it_resolves_vendor_style_url_with_serial(): void
    {
        $station = Station::query()->create([
            'name' => 'Statie reala',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => '419400481F59D7',
            'ocpp_identity' => '419400481F59D7',
        ]);

        $resolver = app(QrStationResolver::class);
        $result = $resolver->resolve('http://www.vendor-id.com/charge?cid=419400481F59D7&gunNo=2');

        $this->assertNotNull($result);
        $this->assertSame($station->id, $result['station']->id);
        $this->assertSame(2, $result['connector_id']);
    }

    public function test_api_resolve_qr_endpoint_returns_station(): void
    {
        $user = $this->createAppUser(['email' => 'driver@example.test']);

        $station = Station::query()->create([
            'name' => 'Statie reala',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => '419400481F59D7',
            'ocpp_identity' => '419400481F59D7',
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/stations/resolve-qr', ['code' => '419400481F59D7B'])
            ->assertOk()
            ->assertJsonPath('station_id', $station->id)
            ->assertJsonPath('connector_id', 2);
    }
}
