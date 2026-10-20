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
                if ($this->awaitingStopTransaction($session, $station)) {
                    return [
                        'status' => 'stopping',
                        'message' => 'Statia a oprit incarcarea. Asteptam confirmarea finala a contorului.',
                        'session' => $session->fresh(),
                        'station' => $station->fresh(),
                    ];
                }

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

            // Fara transactionId confirmat pe OCPP, RemoteStop cu id-ul sesiunii din app e respins.
            // Soft Reset / ChangeAvailability elibereaza conectorul; sesiunea goala se inchide local.
            if ($session->remoteStopTransactionId() <= 0) {
                $connectorId = (int) ($session->ocpp_connector_id ?: 0);
                $recovery = $connectorId > 0
                    ? $this->ocppService->recoverConnectorForRemoteStart(
                        $station,
                        $connectorId,
                        null,
                        'stop_without_transaction',
                        true
                    )
                    : [];

                if (! $session->hasConfirmedOcppTransaction()) {
                    $finalized = $this->finalizeStop(
                        $session,
                        $station,
                        $stopSource,
                        null,
                        null,
                        'NeverStarted',
                        ['trigger' => 'stop_without_ocpp_transaction', 'command_ids' => $recovery]
                    );

                    return array_merge($finalized, [
                        'ocpp' => [
                            'status' => $recovery === [] ? 'local_only' : 'queued',
                            'command_ids' => $recovery,
                            'message' => 'Sesiunea nu a pornit pe statie; a fost inchisa local.',
                        ],
                    ]);
                }

                return [
                    'status' => 'stopping',
                    'message' => 'Oprire prin reset conector (fara transactionId OCPP valid).',
                    'session' => $session->fresh(),
                    'station' => $station->fresh(),
                    'ocpp' => [
                        'status' => 'queued',
                        'command_ids' => $recovery,
                    ],
                ];
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
            'stop_source' => $session->stoppedAfterEvPause() ? ChargingSession::STOP_SOURCE_SUSPENDED_EV : $stopSource,
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

    /**
     * Conectorul tocmai a fost eliberat, dar statia inca n-a trimis StopTransaction
     * (cu contorul final). Asteptam o fereastra scurta ca sa nu facturam din ultima
     * citire MeterValues si sa pierdem energia livrata dupa ea.
     */
    public function awaitingStopTransaction(
        ChargingSession $session,
        Station $station,
        ?string $connectorStatus = null
    ): bool {
        if (! $this->isGatewayMode() || $session->end_time || ! $session->ocpp_transaction_id) {
            return false;
        }

        $connectorId = (int) ($session->ocpp_connector_id ?: 1);
        $status = $connectorStatus ?? $station->connectorOcppStatus($connectorId);

        if (! in_array($status, ['Available', 'Finishing'], true)) {
            return false;
        }

        $releasedAt = $station->connectorReleasedAt($connectorId);

        if (! $releasedAt) {
            return false;
        }

        $grace = max(0, (int) config('services.ocpp.stop_transaction_grace_seconds', 60));

        return $releasedAt->greaterThan(now()->subSeconds($grace));
    }

    public function shouldAutoFinalizeOnConnectorRelease(
        ChargingSession $session,
        Station $station,
        ?string $connectorStatus = null,
        bool $waitForStopTransaction = true,
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

        if ($waitForStopTransaction && $this->awaitingStopTransaction($session, $station, $status)) {
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

        // Finishing + cable still present is handled by force-restart on start, not abandoned close.
        if ($status === 'Finishing') {
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

        // Pornire noua pe acelasi port: inchidem acum sesiunea veche, fara sa asteptam
        // StopTransaction — altfel noul StartTransaction ar reseta kWh-ii deja livrati.
        return in_array($status, ['Available'], true)
            && $this->shouldAutoFinalizeOnConnectorRelease($session, $station, $status, false);
    }

    /**
     * Sesiuni din aplicatie care n-au primit niciodata StartTransaction (RemoteStart respins,
     * masina neconectata, clientul a plecat). Altfel tin portul ocupat pentru ceilalti si
     * bugetul blocat pana cand proprietarul redeschide aplicatia.
     *
     * Un StartTransaction intarziat pentru o sesiune inchisa e respins de gateway, deci
     * inchiderea nu poate livra energie nefacturata.
     */
    public function closeUnstartedSessions(Station $station): int
    {
        // Simulator mode bills estimated energy for sessions without meter data; never apply that here.
        if (! $this->isGatewayMode()) {
            return 0;
        }

        $timeoutMinutes = max(5, (int) config('services.ocpp.unstarted_session_timeout_minutes', 15));
        $acceptedTimeoutMinutes = max(1, (int) config('services.ocpp.accepted_start_without_transaction_minutes', 3));
        $cutoff = now()->subMinutes($timeoutMinutes);
        $acceptedCutoff = now()->subMinutes($acceptedTimeoutMinutes);

        $candidates = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->whereNull('ocpp_transaction_id')
            // finalizeStop marks the session's connector Available; without one it would hit connector 1.
            ->whereNotNull('ocpp_connector_id')
            ->where('start_time', '<=', now()->subMinutes(min($timeoutMinutes, $acceptedTimeoutMinutes)))
            ->get();

        $closed = 0;

        foreach ($candidates as $session) {
            $fresh = $station->fresh();
            $session = $session->fresh();

            if (! $fresh || ! $session) {
                continue;
            }

            $staleAccepted = $this->isAcceptedStartWithoutTransaction($session, $acceptedCutoff);
            $idleTimedOut = $session->start_time
                && $session->start_time->lessThanOrEqualTo($cutoff)
                && $this->isUnstartedAndIdle($session, $fresh, $cutoff);

            if (! $staleAccepted && ! $idleTimedOut) {
                continue;
            }

            $this->finalizeStop(
                $session,
                $fresh,
                'system',
                null,
                null,
                'NeverStarted',
                [
                    'trigger' => $staleAccepted
                        ? 'accepted_start_without_transaction_timeout'
                        : 'unstarted_session_timeout',
                    'timeout_minutes' => $staleAccepted ? $acceptedTimeoutMinutes : $timeoutMinutes,
                ]
            );
            $closed++;
        }

        return $closed;
    }

    private function isUnstartedAndIdle(?ChargingSession $session, Station $station, Carbon $cutoff): bool
    {
        if (! $session || $session->end_time || $session->ocpp_transaction_id) {
            return false;
        }

        if ($this->sessionIsCurrentlyCharging($session, $station) || $this->sessionHadChargingActivity($session)) {
            return false;
        }

        // A RemoteStart / recovery still in flight, or sent recently, may yet produce a StartTransaction
        // (the same 15-minute window the gateway uses to match StartTransaction to a RemoteStart).
        return ! OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('status', [OcppCommand::STATUS_PENDING, OcppCommand::STATUS_SENT])
                    ->orWhere('created_at', '>', $cutoff);
            })
            ->exists();
    }

    /**
     * RemoteStart Accepted, dar StartTransaction nu a venit — fiecare Continua ar crea alta sesiune goala.
     */
    private function isAcceptedStartWithoutTransaction(ChargingSession $session, Carbon $acceptedCutoff): bool
    {
        if ($session->end_time || $session->ocpp_transaction_id || $session->hasConfirmedOcppTransaction()) {
            return false;
        }

        if ($this->sessionHadChargingActivity($session)) {
            return false;
        }

        $acceptedStart = OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->whereIn('action', ['RemoteStartTransaction', 'RequestStartTransaction'])
            ->where('status', OcppCommand::STATUS_ACCEPTED)
            ->where('acknowledged_at', '<=', $acceptedCutoff)
            ->latest('id')
            ->first();

        if (! $acceptedStart) {
            return false;
        }

        // Still waiting on a newer pending/sent start for the same session.
        return ! OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->whereIn('action', ['RemoteStartTransaction', 'RequestStartTransaction'])
            ->whereIn('status', [OcppCommand::STATUS_PENDING, OcppCommand::STATUS_SENT])
            ->where('id', '>', $acceptedStart->id)
            ->exists();
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
            ->awaitingRemoteStop()
            ->latest('id')
            ->first();
    }
}
