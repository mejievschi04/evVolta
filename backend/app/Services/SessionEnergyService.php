<?php

namespace App\Services;

use App\Models\ChargingSession;
use Carbon\Carbon;

class SessionEnergyService
{
    private const METER_STUCK_EPSILON_KWH = 0.001;

    /** EU1060 and similar chargers often send meterStart=0 meaning “unknown”, not a zero baseline. */
    public static function effectiveMeterStart(?float $meterStart): ?float
    {
        if ($meterStart === null || abs($meterStart) < 0.0005) {
            return null;
        }

        return $meterStart;
    }

    /**
     * When StartTransaction has no usable meterStart, Energy.Active.Import.Register
     * is session-delivered energy (what the charger display shows), not a cumulative total.
     */
    public static function usesSessionRelativeRegister(ChargingSession $session): bool
    {
        return self::effectiveMeterStart($session->meter_start_kwh) === null;
    }

    public function integrateFromPower(ChargingSession $session, array $metrics): float
    {
        $live = is_array($session->live_metrics) ? $session->live_metrics : [];
        $integrated = (float) ($live['energy_integrated_kwh'] ?? 0);
        $powerKw = isset($metrics['power_kw']) ? (float) $metrics['power_kw'] : null;

        if ($powerKw === null || $powerKw <= 0.01) {
            return $integrated;
        }

        $sampledAt = ! empty($metrics['sampled_at'])
            ? Carbon::parse($metrics['sampled_at'])
            : now();
        $previousAt = ! empty($live['sampled_at'])
            ? Carbon::parse($live['sampled_at'])
            : ($session->start_time ?? $sampledAt);
        $previousPower = isset($live['power_kw']) ? (float) $live['power_kw'] : $powerKw;

        $elapsedSeconds = max(0, min($sampledAt->getTimestamp() - $previousAt->getTimestamp(), 300));
        if ($elapsedSeconds <= 0) {
            return $integrated;
        }

        $hours = $elapsedSeconds / 3600;
        $integrated += (($previousPower + $powerKw) / 2) * $hours;

        return round($integrated, 3);
    }

    /**
     * @return array{kwh_consumed: float, energy_integrated_kwh: float, meter_start_kwh?: float}
     */
    public function resolveDeliveredKwh(ChargingSession $session, ?float $meterKwh, array $metrics): array
    {
        $integrated = $this->integrateFromPower($session, $metrics);

        if ($meterKwh !== null && self::usesSessionRelativeRegister($session)) {
            return [
                'kwh_consumed' => round($meterKwh, 3),
                'energy_integrated_kwh' => $integrated,
            ];
        }

        $meterStart = self::effectiveMeterStart($session->meter_start_kwh);
        $meterDelta = $this->meterRegisterDelta($meterKwh, $meterStart);
        $powerKw = (float) ($metrics['power_kw'] ?? 0);
        $currentA = (float) ($metrics['current_a'] ?? 0);
        $energyFlowing = $powerKw > 0.05 || $currentA > 0.5;
        $meterStuck = $this->isMeterRegisterStuck($session, $meterKwh, $energyFlowing);

        $kwhConsumed = $this->chooseDeliveredKwh(
            $meterDelta,
            $integrated,
            (float) ($session->kwh_consumed ?? 0),
            $meterStuck
        );

        $updates = [
            'kwh_consumed' => $kwhConsumed,
            'energy_integrated_kwh' => $integrated,
        ];

        if ($meterKwh !== null && $meterStart === null && $energyFlowing) {
            $updates['meter_start_kwh'] = $meterKwh;
        }

        return $updates;
    }

    public function telemetryKwhDelivered(ChargingSession $session): float
    {
        $live = is_array($session->live_metrics) ? $session->live_metrics : [];
        $meterTotal = isset($live['energy_kwh']) ? (float) $live['energy_kwh'] : null;

        if ($meterTotal !== null && self::usesSessionRelativeRegister($session)) {
            return round($meterTotal, 3);
        }

        $stored = (float) ($session->kwh_consumed ?? 0);
        $integrated = (float) ($live['energy_integrated_kwh'] ?? 0);

        $meterDelta = $this->meterRegisterDelta(
            $meterTotal,
            self::effectiveMeterStart($session->meter_start_kwh)
        );

        $powerKw = (float) ($live['power_kw'] ?? 0);
        $currentA = (float) ($live['current_a'] ?? 0);
        $energyFlowing = $powerKw > 0.05 || $currentA > 0.5;
        $meterStuck = $this->isMeterRegisterStuck($session, $meterTotal, $energyFlowing);

        return $this->chooseDeliveredKwh($meterDelta, $integrated, $stored, $meterStuck);
    }

    private function chooseDeliveredKwh(
        float $meterDelta,
        float $integrated,
        float $stored,
        bool $meterStuck,
    ): float {
        if (! $meterStuck && $meterDelta > 0) {
            return round($meterDelta, 3);
        }

        return round(max($stored, $integrated, $meterDelta), 3);
    }

    private function meterRegisterDelta(?float $meterKwh, mixed $meterStart): float
    {
        if ($meterKwh === null || $meterStart === null) {
            return 0.0;
        }

        return round(max(0, $meterKwh - (float) $meterStart), 3);
    }

    private function isMeterRegisterStuck(
        ChargingSession $session,
        ?float $meterKwh,
        bool $energyFlowing,
    ): bool {
        if ($meterKwh === null || ! $energyFlowing) {
            return false;
        }

        $live = is_array($session->live_metrics) ? $session->live_metrics : [];
        $previousRegister = isset($live['previous_energy_kwh'])
            ? (float) $live['previous_energy_kwh']
            : (isset($live['energy_kwh']) ? (float) $live['energy_kwh'] : null);

        if ($previousRegister === null) {
            return false;
        }

        return abs($meterKwh - $previousRegister) < self::METER_STUCK_EPSILON_KWH;
    }
}
