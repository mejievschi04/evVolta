<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChargingResumeService
{
    public const RESUMABLE_STATUSES = ['SuspendedEV', 'SuspendedEVSE', 'Finishing'];

    public function __construct(
        private readonly OcppService $ocppService,
        private readonly ChargingStopService $chargingStopService,
        private readonly WalletService $walletService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function connectorCanResume(Station $station, int $connectorId): bool
    {
        $status = $station->connectorOcppStatus($connectorId);

        return in_array($status, self::RESUMABLE_STATUSES, true);
    }

    /**
     * User-initiated Continua: close paused session if needed, recover connector, RemoteStart.
     *
     * @return array{
     *     session: ChargingSession,
     *     station: Station,
     *     previous_session: ?ChargingSession,
     *     ocpp: array<string, mixed>,
     *     connector_id: int
     * }
     */
    public function resume(User $user, Station $station, ?int $sessionId = null, ?int $connectorId = null): array
    {
        $station = $station->fresh();

        if (! $station) {
            throw new RuntimeException('Statia nu a fost gasita.', 404);
        }

        $this->ocppService->ensureReadyForRemoteCommands($station);

        $openSession = $this->resolveOpenSession($user, $station, $sessionId, $connectorId);
        $resolvedConnectorId = (int) (
            $connectorId
            ?? $openSession?->ocpp_connector_id
            ?? $this->detectResumableConnectorId($station, $user)
            ?? 0
        );

        if ($resolvedConnectorId <= 0 || ! in_array($resolvedConnectorId, $station->expectedConnectorIds(), true)) {
            throw new RuntimeException('Nu am putut identifica portul pentru continuare.', 422);
        }

        if ($station->connectorOccupiedByOtherUser($resolvedConnectorId, (int) $user->id)) {
            throw new RuntimeException('Conectorul este deja folosit de alt utilizator.', 422);
        }

        $status = $station->connectorOcppStatus($resolvedConnectorId);
        if (! in_array($status, self::RESUMABLE_STATUSES, true)) {
            throw new RuntimeException(
                'Portul nu este in pauza (SuspendedEV). Status actual: ' . ($status ?: 'necunoscut') . '.',
                422
            );
        }

        $previousSession = null;
        $carryBudget = null;
        $carryTargetKwh = null;

        $created = DB::transaction(function () use (
            $user,
            $station,
            $openSession,
            $resolvedConnectorId,
            &$previousSession,
            &$carryBudget,
            &$carryTargetKwh
        ) {
            $station = Station::query()->whereKey($station->id)->lockForUpdate()->firstOrFail();

            if ($openSession) {
                $openSession = ChargingSession::query()
                    ->whereKey($openSession->id)
                    ->lockForUpdate()
                    ->first();

                if ($openSession && ! $openSession->end_time) {
                    $budgetBefore = (float) ($openSession->charge_budget ?? 0);
                    $spentEstimate = $this->estimateSpent($openSession);

                    if ($budgetBefore > 0) {
                        $carryBudget = round(max(0, $budgetBefore - $spentEstimate), 2);
                    }

                    $target = (float) ($openSession->target_kwh ?? 0);
                    if ($target > 0) {
                        $delivered = app(SessionEnergyService::class)->telemetryKwhDelivered($openSession);
                        $carryTargetKwh = round(max(0, $target - $delivered), 3);
                        if ($carryTargetKwh < 0.1) {
                            $carryTargetKwh = null;
                        }
                    }

                    $this->chargingStopService->finalizeStop(
                        $openSession,
                        $station,
                        'app',
                        null,
                        null,
                        'UserResume',
                        ['trigger' => 'charging.resume', 'status' => $station->connectorOcppStatus($resolvedConnectorId)]
                    );

                    $previousSession = $openSession->fresh();
                    $station = $station->fresh();
                }
            }

            // Reload user relation for tariff estimate already done before finalize.
            if ($station->hasActiveSessionOnConnector($resolvedConnectorId)) {
                throw new RuntimeException('Conectorul este deja folosit de alt utilizator.', 422);
            }

            $session = ChargingSession::query()->create([
                'user_id' => $user->id,
                'station_id' => $station->id,
                'ocpp_connector_id' => $resolvedConnectorId,
                'ocpp_id_tag' => OcppService::idTagForUser($user),
                'start_source' => 'app_resume',
                'start_time' => now(),
                'kwh_consumed' => 0,
            ]);

            if ($carryBudget !== null && $carryBudget >= WalletService::MIN_BUDGET_AMOUNT && $user->usesCardPayment()) {
                try {
                    $this->walletService->holdBudgetForSession(
                        $user->fresh(),
                        $session->fresh(),
                        $carryBudget,
                        $carryTargetKwh
                    );
                } catch (RuntimeException) {
                    // Continua fara hold daca soldul nu mai permite — user poate reporni cu buget nou.
                }
            }

            $station->update(['status' => Station::STATUS_CHARGING]);

            return $session->fresh();
        });

        $recoveryIds = $this->ocppService->recoverConnectorForRemoteStart(
            $station->fresh(),
            $resolvedConnectorId,
            $created,
            'user_resume_suspended',
            true
        );

        if ($recoveryIds === []) {
            $ocppResponse = $this->ocppService->queueRemoteStart($station->fresh(), $created, $user);
            $ocppResponse['resume_recovery'] = false;
        } else {
            $ocppResponse = [
                'station_id' => $station->id,
                'mode' => config('services.ocpp.mode'),
                'status' => 'queued',
                'message' => 'Continua: reset conector + RemoteStart.',
                'command_ids' => $recoveryIds,
                'resume_recovery' => true,
            ];
        }

        $this->auditLogService->record(
            action: 'charging.resume',
            actor: $user,
            subjectType: ChargingSession::class,
            subjectId: $created->id,
            station: $station->fresh(),
            session: $created,
            metadata: [
                'previous_session_id' => $previousSession?->id,
                'connector_id' => $resolvedConnectorId,
                'connector_status' => $status,
                'ocpp' => $ocppResponse,
            ]
        );

        return [
            'session' => $created->fresh(['station']),
            'station' => $station->fresh(),
            'previous_session' => $previousSession,
            'ocpp' => $ocppResponse,
            'connector_id' => $resolvedConnectorId,
        ];
    }

    private function resolveOpenSession(
        User $user,
        Station $station,
        ?int $sessionId,
        ?int $connectorId
    ): ?ChargingSession {
        $query = ChargingSession::query()
            ->where('station_id', $station->id)
            ->where('user_id', $user->id)
            ->whereNull('end_time');

        if ($sessionId) {
            $query->whereKey($sessionId);
        } elseif ($connectorId) {
            $query->where('ocpp_connector_id', $connectorId);
        }

        return $query->latest('id')->first();
    }

    private function detectResumableConnectorId(Station $station, User $user): ?int
    {
        foreach ($station->expectedConnectorIds() as $connectorId) {
            if ($station->connectorOccupiedByOtherUser($connectorId, (int) $user->id)) {
                continue;
            }

            if ($this->connectorCanResume($station, $connectorId)) {
                return $connectorId;
            }
        }

        return null;
    }

    private function estimateSpent(ChargingSession $session): float
    {
        $session->loadMissing('user');
        $live = is_array($session->live_metrics) ? $session->live_metrics : [];
        if (isset($live['budget_spent'])) {
            return round((float) $live['budget_spent'], 2);
        }

        $kwh = app(SessionEnergyService::class)->telemetryKwhDelivered($session);
        $price = app(TariffService::class)->pricePerKwhForUser($session->user);

        return round(max(0, $kwh * $price), 2);
    }
}
