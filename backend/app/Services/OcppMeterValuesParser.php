<?php

namespace App\Services;

class OcppMeterValuesParser
{
    /**
     * @return array{
     *     energy_kwh: ?float,
     *     power_kw: ?float,
     *     current_a: ?float,
     *     voltage_v: ?float,
     *     soc_percent: ?float,
     *     temperature_c: ?float,
     *     sampled_at: ?string,
     *     measurands: array<string, float>
     * }
     */
    public function parse(array $meterValues): array
    {
        $result = [
            'energy_kwh' => null,
            'power_kw' => null,
            'current_a' => null,
            'voltage_v' => null,
            'soc_percent' => null,
            'temperature_c' => null,
            'sampled_at' => null,
            'measurands' => [],
        ];

        $currentSum = 0.0;
        $hasCurrent = false;

        foreach ($meterValues as $meterValue) {
            if (! is_array($meterValue)) {
                continue;
            }

            if (! empty($meterValue['timestamp'])) {
                $result['sampled_at'] = (string) $meterValue['timestamp'];
            }

            foreach ($meterValue['sampledValue'] ?? [] as $sample) {
                if (! is_array($sample)) {
                    continue;
                }

                $parsed = $this->parseSample($sample);
                if ($parsed === null) {
                    continue;
                }

                $result['measurands'][$parsed['measurand']] = $parsed['value'];

                match ($parsed['kind']) {
                    'energy_register' => $result['energy_kwh'] = $this->maxNullable(
                        $result['energy_kwh'],
                        $parsed['value']
                    ),
                    'energy_interval' => $result['energy_kwh'] = $this->maxNullable(
                        $result['energy_kwh'],
                        $parsed['value']
                    ),
                    'power' => $result['power_kw'] = $this->maxNullable(
                        $result['power_kw'],
                        $parsed['value']
                    ),
                    'current' => (function () use (&$currentSum, &$hasCurrent, $parsed): void {
                        $currentSum += $parsed['value'];
                        $hasCurrent = true;
                    })(),
                    'voltage' => $result['voltage_v'] = $this->maxNullable(
                        $result['voltage_v'],
                        $parsed['value']
                    ),
                    'soc' => $result['soc_percent'] = $this->maxNullable(
                        $result['soc_percent'],
                        $parsed['value']
                    ),
                    'temperature' => $result['temperature_c'] = $this->maxNullable(
                        $result['temperature_c'],
                        $parsed['value']
                    ),
                    default => null,
                };
            }
        }

        if ($hasCurrent) {
            $result['current_a'] = round($currentSum, 3);
        }

        return $result;
    }

    /**
     * @return array{measurand: string, kind: string, value: float}|null
     */
    private function parseSample(array $sample): ?array
    {
        if (! isset($sample['value']) || ! is_numeric($sample['value'])) {
            return null;
        }

        $measurand = $this->normalizeMeasurand((string) ($sample['measurand'] ?? 'Energy.Active.Import.Register'));
        $unit = strtolower((string) ($sample['unit'] ?? ''));
        $value = (float) $sample['value'];
        $kind = $this->measurandKind($measurand);

        if ($kind === null) {
            return null;
        }

        $normalized = match ($kind) {
            'energy_register', 'energy_interval' => $this->toKwh($value, $unit),
            'power' => $this->toKw($value, $unit),
            'current' => $this->toAmps($value, $unit),
            'voltage' => $this->toVolts($value, $unit),
            'soc' => $this->toPercent($value, $unit),
            'temperature' => $this->toCelsius($value, $unit),
            default => null,
        };

        if ($normalized === null) {
            return null;
        }

        return [
            'measurand' => $measurand,
            'kind' => $kind,
            'value' => round($normalized, 3),
        ];
    }

    private function normalizeMeasurand(string $measurand): string
    {
        return trim($measurand) === '' ? 'Energy.Active.Import.Register' : $measurand;
    }

    private function measurandKind(string $measurand): ?string
    {
        $normalized = strtolower(str_replace(' ', '', $measurand));

        return match ($normalized) {
            'energy.active.import.register' => 'energy_register',
            'energy.active.import.interval' => 'energy_interval',
            'power.active.import', 'power.offered', 'power.active.export' => 'power',
            'current.import', 'current.offered', 'current.export' => 'current',
            'voltage' => 'voltage',
            'soc' => 'soc',
            'temperature' => 'temperature',
            default => null,
        };
    }

    private function toKwh(float $value, string $unit): float
    {
        return match ($unit) {
            'kwh' => $value,
            'wh', '' => $value / 1000,
            default => $value / 1000,
        };
    }

    private function toKw(float $value, string $unit): float
    {
        return match ($unit) {
            'kw' => $value,
            'w', '' => $value / 1000,
            default => $value / 1000,
        };
    }

    private function toAmps(float $value, string $unit): float
    {
        return match ($unit) {
            'a', '' => $value,
            default => $value,
        };
    }

    private function toVolts(float $value, string $unit): float
    {
        return match ($unit) {
            'v', '' => $value,
            default => $value,
        };
    }

    private function toPercent(float $value, string $unit): float
    {
        return match ($unit) {
            'percent', '%', '' => $value,
            default => $value,
        };
    }

    private function toCelsius(float $value, string $unit): float
    {
        return match ($unit) {
            'celsius', 'c', '' => $value,
            'fahrenheit', 'f' => ($value - 32) * 5 / 9,
            default => $value,
        };
    }

    private function maxNullable(?float $current, float $next): float
    {
        if ($current === null) {
            return $next;
        }

        return max($current, $next);
    }
}
