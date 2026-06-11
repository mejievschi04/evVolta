<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use Tests\TestCase;

class OcppGetConfigurationTest extends TestCase
{
    public function test_get_configuration_exposes_meter_value_sample_interval_from_config(): void
    {
        Config::set('services.ocpp.meter_value_sample_interval', 5);

        $command = app(OcppServe::class);
        $method = (new ReflectionClass($command))->getMethod('onGetConfiguration');
        $method->setAccessible(true);

        $response = $method->invoke($command, [
            'key' => ['MeterValueSampleInterval'],
        ]);

        $keys = collect($response['configurationKey'] ?? [])->pluck('value', 'key');

        $this->assertSame('5', $keys->get('MeterValueSampleInterval'));
    }

    public function test_meter_value_sample_interval_never_drops_below_five_seconds(): void
    {
        Config::set('services.ocpp.meter_value_sample_interval', 2);

        $command = app(OcppServe::class);
        $method = (new ReflectionClass($command))->getMethod('onGetConfiguration');
        $method->setAccessible(true);

        $response = $method->invoke($command, [
            'key' => ['MeterValueSampleInterval'],
        ]);

        $keys = collect($response['configurationKey'] ?? [])->pluck('value', 'key');

        $this->assertSame('5', $keys->get('MeterValueSampleInterval'));
    }
}
