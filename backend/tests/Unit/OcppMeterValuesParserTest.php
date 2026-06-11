<?php

namespace Tests\Unit;

use App\Services\OcppMeterValuesParser;
use PHPUnit\Framework\TestCase;

class OcppMeterValuesParserTest extends TestCase
{
    public function test_it_parses_ocpp_16j_meter_values(): void
    {
        $parser = new OcppMeterValuesParser();

        $metrics = $parser->parse([
            [
                'timestamp' => '2026-06-09T10:00:00Z',
                'sampledValue' => [
                    [
                        'value' => '15420',
                        'unit' => 'Wh',
                        'measurand' => 'Energy.Active.Import.Register',
                    ],
                    [
                        'value' => '7200',
                        'unit' => 'W',
                        'measurand' => 'Power.Active.Import',
                    ],
                    [
                        'value' => '31.2',
                        'unit' => 'A',
                        'measurand' => 'Current.Import',
                    ],
                    [
                        'value' => '230',
                        'unit' => 'V',
                        'measurand' => 'Voltage',
                    ],
                    [
                        'value' => '68',
                        'unit' => 'Percent',
                        'measurand' => 'SoC',
                    ],
                ],
            ],
        ]);

        $this->assertSame(15.42, $metrics['energy_kwh']);
        $this->assertSame(7.2, $metrics['power_kw']);
        $this->assertSame(31.2, $metrics['current_a']);
        $this->assertSame(230.0, $metrics['voltage_v']);
        $this->assertSame(68.0, $metrics['soc_percent']);
        $this->assertSame('2026-06-09T10:00:00Z', $metrics['sampled_at']);
    }

    public function test_it_sums_current_across_phases(): void
    {
        $parser = new OcppMeterValuesParser();

        $metrics = $parser->parse([
            [
                'sampledValue' => [
                    ['value' => '16', 'unit' => 'A', 'measurand' => 'Current.Import', 'phase' => 'L1'],
                    ['value' => '16', 'unit' => 'A', 'measurand' => 'Current.Import', 'phase' => 'L2'],
                    ['value' => '7200', 'unit' => 'W', 'measurand' => 'Power.Active.Import'],
                ],
            ],
        ]);

        $this->assertSame(32.0, $metrics['current_a']);
        $this->assertSame(7.2, $metrics['power_kw']);
    }
}
