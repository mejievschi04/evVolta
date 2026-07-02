<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChargingStopService
{
    public function __construct(
        private readonly OcppService $ocppService,
        private readonly BillingService $billingService,
    ) {
    }

    public function isGatewayMode(): bool
    {
        return ! $this->ocppService->isSimulatorMode();
    }

    /**
     * @return array{
     *     status: 'stopping'|'completed',
     *     message: string,
     *     session: ChargingSession,
     *     station: Station,
     *     duration_minutes?: int,
     *     invoice?: Invoice,
     *     ocpp?: array<string, mixed>
     * }
     */
    public function requestStop(
        ChargingSession $session,
        Station $station,
        string $stopSource = 'app',
    ): array {
        $session = $session->fresh();
        $station = $station->fresh();

        if ($this->isGatewayMode()) {
            if ($this->connectorAlreadyReleased($station, $session)) {
                return $this->finalizeStop($session, $station, $stopSource);
            }

            $pendingStop = $this->pendingRemoteStop($session);

            if ($pendingStop) {
                return [
                    'status' => 'stopping',
                    'message' => 'Comanda de oprire este deja in curs.',
                    'session' => $session->fresh(),
                    'station' => $station->fresh(),
                ];
            }

            if ($this->shouldFinalizeLocally($session)) {
                return $this->finalizeStop($session, $station, $stopSource);
            }

            $ocppResponse = $this->ocppService->stopTransaction($station, $session);

            return [
                'status' => 'stopping',
                'message' => 'Comanda de oprire a fost trimisa catre statie.',
                'session' => $session->fresh(),
                'station' => $station->fresh(),
                'ocpp' => $ocppResponse,
            ];
        }

        return $this->finalizeStop($session, $station, $stopSource);
    }

    /**
     * @return array{
     *     status: 'completed',
     *     message: string,
     *     session: ChargingSession,
     *     station: Station,
     *     duration_minutes: int,
     *     invoice: Invoice
     * }
     */
    public function finalizeStop(
        ChargingSession $session,
        Station $station,
        string $stopSource = 'app',
        ?Carbon $endTime = null,
        ?float $meterStopKwh = null,
        ?string $ocppStopReason = null,
        ?array $ocppStopContext = null,
    ): array {
        return DB::transaction(function () use ($session, $station, $stopSource, $endTime, $meterStopKwh, $ocppStopReason, $ocppStopContext): array {
            $station = Station::query()
                ->whereKey($station->id)
                ->lockForUpdate()
                ->firstOrFail();
            $session = ChargingSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

        if ($session->end_time) {
            $invoice = Invoice::query()
                ->where('source_session_id', $session->id)
                ->first();

            return [
                'status' => 'completed',
                'message' => 'Sesiunea este deja inchisa.',
                'session' => $session,
                'station' => $station,
                'duration_minutes' => max(1, (int) $session->start_time->diffInMinutes($session->end_time)),
                'invoice' => $invoice ?? $this->billingService->finalizeBillingForSession($session),
            ];
        }

        $endTime ??= Carbon::now();
        $minutes = max(1, (int) $session->start_time->diffInMinutes($endTime));

        if ($meterStopKwh !== null) {
            $station = $station->fresh();
        }

        $liveMetrics = is_array($session->live_metrics) ? $session->live_metrics : [];
        $connectorId = (int) ($session->ocpp_connector_id ?: 1);
        $meterStop = $meterStopKwh
            ?? ($liveMetrics['energy_kwh'] ?? null)
            ?? $station->connectorLiveMeterEnergy($connectorId);
        $meterStart = SessionEnergyService::effectiveMeterStart($session->meter_start_kwh);

        if ($meterStop !== null && SessionEnergyService::usesSessionRelativeRegister($session)) {
            $kwhConsumed = round((float) $meterStop, 3);
        } elseif ($meterStop !== null && $meterStart !== null) {
            $kwhConsumed = round(max(0, (float) $meterStop - $meterStart), 3);
        } else {
            $kwhConsumed = app(SessionEnergyService::class)->telemetryKwhDelivered($session);
            if (
                $kwhConsumed <= 0
                && ! $this->isGatewayMode()
                && SessionEnergyService::effectiveMeterStart($session->meter_start_kwh) === null
            ) {
                $kwhConsumed = $this->resolveKwhConsumed($session, $station);
            }
        }

        $update = [
            'end_time' => $endTime,
            'kwh_consumed' => $kwhConsumed,
            'meter_stop_kwh' => $meterStop,
            'stop_source' => $stopSource,
        ];

        if ($ocppStopReason !== null && $ocppStopReason !== '') {
            $update['ocpp_stop_reason'] = $ocppStopReason;
        }

        if ($ocppStopContext !== null && $ocppStopContext !== []) {
            $update['ocpp_stop_context'] = $ocppStopContext;
        }

        $session->update($update);

        $invoice = $this->billingService->finalizeBillingForSession($session->fresh());
        $station->markConnectorAvailable((int) ($session->ocpp_connector_id ?: 1));
        app(ReservationService::class)->completeForSession($session->id);

        $session = $session->fresh(['user', 'station']);

        return [
            'status' => 'completed',
            'message' => 'Incarcarea a fost oprita.',
            'session' => $session->fresh(),
            'station' => $station->fresh(),
            'duration_minutes' => $minutes,
            'invoice' => $invoice?->fresh(),
        ];
        });
    }

    public function resolveKwhConsumed(ChargingSession $session, Station $station): float
    {
        if ($this->isGatewayMode()) {
            return app(SessionEnergyService::class)->telemetryKwhDelivered($session);
        }

        $minutes = max(1, (int) $session->start_time->diffInMinutes(now()));

        return round($minutes * 0.12, 2);
    }

    public function shouldAutoFinalizeOnConnectorRelease(
        ChargingSession $session,
        Station $station,
        ?string $connectorStatus = null
    ): bool {
        if ($session->end_time) {
            return false;
        }

        $connectorId = (int) ($session->ocpp_connector_id ?: 1);
        $status = $connectorStatus ?? $station->connectorOcppStatus($connectorId);

        if (! in_array($status, ['Available', 'Finishing'], true)) {
            return false;
        }

        if ($this->pendingRemoteStop($session)) {
            return false;
        }

        if ($status === 'Finishing' && ! $this->sessionHadChargingActivity($session)) {
            return false;
        }

        if ($session->ocpp_transaction_id) {
            return true;
        }

        if ($this->sessionHadChargingActivity($session)) {
            return true;
        }

        return $status === 'Available'
            && $session->start_time
            && $session->start_time->diffInMinutes(now()) >= 2;
    }

    public function maybeAutoFinalizeOnStationStatus(
        Station $station,
        int $connectorId,
        string $connectorStatus,
        string $stopSource = 'ocpp'
    ): ?ChargingSession {
        $session = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->where('ocpp_connector_id', $connectorId)
            ->latest('id')
            ->first();

        if (! $session || ! $this->shouldAutoFinalizeOnConnectorRelease($session, $station->fresh(), $connectorStatus)) {
            return null;
        }

        $stopContext = app(OcppSessionDebugService::class)->buildStopContext(
            $station,
            $session,
            'StatusNotification:' . $connectorStatus,
            ['status_notification_connector_id' => $connectorId]
        );

        return $this->finalizeStop(
            $session,
            $station->fresh(),
            $stopSource,
            null,
            null,
            null,
            $stopContext
        )['session'];
    }

    /**
     * Keep at most one open session per user + station + connector.
     * Closes duplicate rows and abandoned zombies before a new start.
     */
    public function reconcileOpenSessionsBeforeStart(
        Station $station,
        int $userId,
        int $connectorId
    ): ?ChargingSession {
        $station = $station->fresh();

        $open = ChargingSession::query()
            ->where('station_id', $station->id)
            ->where('user_id', $userId)
            ->where('ocpp_connector_id', $connectorId)
            ->whereNull('end_time')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        if ($open->isEmpty()) {
            return null;
        }

        $keep = $open
            ->filter(fn (ChargingSession $session) => $this->sessionIsCurrentlyCharging($session, $station))
            ->sortByDesc('id')
            ->first() ?? $open->first();

        foreach ($open as $session) {
            if ((int) $session->id === (int) $keep->id) {
                continue;
            }

            $this->finalizeStop(
                $session,
                $station,
                'system',
                null,
                null,
                'StaleDuplicate',
                ['trigger' => 'reconcile_duplicate']
            );
        }

        if ($keep && $this->shouldCloseStaleAbandonedSession($keep, $station)) {
            $this->finalizeStop(
                $keep,
                $station,
                'system',
                null,
                null,
                'StaleAbandoned',
                ['trigger' => 'reconcile_abandoned']
            );

            return null;
        }

        return $keep?->fresh();
    }

    public function reconcileAllOpenSessionsForUser(User|int $user): void
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        $openSessions = ChargingSession::query()
            ->with('station')
            ->where('user_id', $userId)
            ->whereNull('end_time')
            ->whereNotNull('ocpp_connector_id')
            ->orderByDesc('id')
            ->get();

        $seen = [];

        foreach ($openSessions as $session) {
            if (! $session->station) {
                continue;
            }

            $key = $session->station_id . ':' . $session->ocpp_connector_id;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            DB::transaction(function () use ($session, $userId): void {
                $station = Station::query()
                    ->whereKey($session->station_id)
                    ->lockForUpdate()
                    ->first();

                if (! $station) {
                    return;
                }

                $this->reconcileOpenSessionsBeforeStart(
                    $station,
                    $userId,
                    (int) $session->ocpp_connector_id
                );
            });
        }
    }

    private function shouldCloseStaleAbandonedSession(ChargingSession $session, Station $station): bool
    {
        if ($this->sessionIsCurrentlyCharging($session, $station)) {
            return false;
        }

        if ($this->pendingRemoteStop($session)) {
            return false;
        }

        $connectorId = (int) ($session->ocpp_connector_id ?: 1);
        $status = $station->connectorOcppStatus($connectorId);

        if (in_array($status, ['Charging', 'SuspendedEV', 'SuspendedEVSE'], true)) {
            return false;
        }

        if (
            $status === 'Preparing'
            && $session->start_time
            && $session->start_time->greaterThan(now()->subMinutes(3))
        ) {
            return false;
        }

        if (
            $session->ocpp_transaction_id
            && $session->start_time
            && $session->start_time->greaterThan(now()->subMinutes(2))
        ) {
            return false;
        }

        if (! $session->ocpp_transaction_id) {
            return $session->start_time === null
                || $session->start_time->lessThanOrEqualTo(now()->subMinutes(2));
        }

        return in_array($status, ['Available', 'Finishing'], true)
            && $this->shouldAutoFinalizeOnConnectorRelease($session, $station, $status);
    }

    public function sessionIsCurrentlyCharging(ChargingSession $session, Station $station): bool
    {
        $connectorId = (int) ($session->ocpp_connector_id ?: 1);
        $status = $station->connectorOcppStatus($connectorId);

        return in_array($status, ['Charging', 'SuspendedEV', 'SuspendedEVSE'], true);
    }

    private function sessionHadChargingActivity(ChargingSession $session): bool
    {
        if ((float) $session->kwh_consumed > 0) {
            return true;
        }

        $live = is_array($session->live_metrics) ? $session->live_metrics : [];

        if ((float) ($live['energy_integrated_kwh'] ?? 0) >= 0.01) {
            return true;
        }

        if ((float) ($live['power_kw'] ?? 0) >= 0.5) {
            return true;
        }

        if ((float) ($live['current_a'] ?? 0) >= 0.5) {
            return true;
        }

        return app(SessionEnergyService::class)->telemetryKwhDelivered($session) >= 0.01;
    }

    private function connectorAlreadyReleased(Station $station, ChargingSession $session): bool
    {
        $connectorId = (int) ($session->ocpp_connector_id ?: 1);
        $status = $station->connectorOcppStatus($connectorId);

        if ($status === 'Finishing') {
            return true;
        }

        if ($status === 'Available') {
            return ! $this->ocppService->sessionIsPhysicallyActive($session, $station);
        }

        return false;
    }

    private function pendingRemoteStop(ChargingSession $session): ?OcppCommand
    {
        return OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->whereIn('action', ['RemoteStopTransaction', 'RequestStopTransaction'])
            ->whereIn('status', [
                OcppCommand::STATUS_PENDING,
                OcppCommand::STATUS_SENT,
                OcppCommand::STATUS_ACCEPTED,
            ])
            ->latest('id')
            ->first();
    }

    private function shouldFinalizeLocally(ChargingSession $session): bool
    {
        if (! $session->ocpp_transaction_id) {
            return false;
        }

        return (float) $session->kwh_consumed <= 0
            && $session->start_time
            && $session->start_time->diffInMinutes(now()) < 3;
    }
}
