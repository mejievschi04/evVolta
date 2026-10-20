<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
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
     * User-initiated Continua: stop station transaction first, then recover connector + RemoteStart.
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

        $previous = ChargingSession::query()->where('station_id', $station->id)
            ->where('user_id', $user->id)
            ->when($sessionId, fn ($query) => $query->whereKey($sessionId))
            ->where('ocpp_connector_id', $resolvedConnectorId)->latest('id')->first();
        if ($previous?->stoppedAfterEvPause()) {
            throw new RuntimeException('Sesiunea a fost oprita automat dupa pauza masinii. Asteapta finalizarea opririi, apoi porneste o sesiune noua.', 422);
        }

        $status = $station->connectorOcppStatus($resolvedConnectorId);
        if (! in_array($status, self::RESUMABLE_STATUSES, true)) {
            throw new RuntimeException(
                'Incarcarea nu este in pauza pe acest port. Status actual: ' . self::connectorStatusLabel($status) . '.',
                422
            );
        }

        // Sesiune goala (RemoteStart acceptat, fara StartTransaction): reincercam pe aceeasi sesiune.
        if ($openSession && ! $openSession->hasConfirmedOcppTransaction() && ! $openSession->end_time) {
            return $this->retryPendingResume($user, $station, $openSession, $resolvedConnectorId, $status);
        }

        $pendingDuplicate = $this->findRecentEmptySession($user, $station, $resolvedConnectorId, $openSession?->id);
        if ($pendingDuplicate) {
            return $this->retryPendingResume($user, $station, $pendingDuplicate, $resolvedConnectorId, $status);
        }

        $previousSession = null;
        $carryBudget = null;
        $carryTargetKwh = null;
        $stopCommandId = null;

        // Oprire pe statie INAINTE de inchiderea locala — altfel tranzactia OCPP ramane vie
        // si RemoteStart-ul urmator e respins, iar fiecare Continua creeaza o sesiune de 0 kWh.
        if ($openSession && $openSession->hasConfirmedOcppTransaction() && $openSession->remoteStopTransactionId() > 0) {
            if (! $this->chargingStopService->isGatewayMode()) {
                // simulator: finalize only
            } elseif ($pendingStop = $this->pendingRemoteStop($openSession)) {
                $stopCommandId = (int) $pendingStop->id;
            } else {
                $stopResponse = $this->ocppService->stopTransaction($station->fresh(), $openSession);
                $stopCommandId = isset($stopResponse['command_id']) ? (int) $stopResponse['command_id'] : null;
            }
        }

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

                    if ($budgetBefore > 0) {
                        $spentSettled = (float) ($previousSession?->charge_budget ?? 0);
                        $carryBudget = round(max(0, $budgetBefore - $spentSettled), 2);
                    }
                }
            }

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
                'price_per_kwh' => app(TariffService::class)->snapshotPriceForNewSession($user),
            ]);

            if ($carryBudget !== null && $carryBudget >= WalletService::MIN_BUDGET_AMOUNT && $user->usesCardPayment()) {
                $this->walletService->holdBudgetForSession(
                    $user->fresh(),
                    $session->fresh(),
                    $carryBudget,
                    $carryTargetKwh
                );
            } elseif (
                $this->walletService->enabled()
                && $user->usesCardPayment()
                && ! $user->isFreeCharging()
            ) {
                throw new RuntimeException(
                    'Bugetul ramas este insuficient pentru continuare. Porneste o sesiune noua cu o suma selectata.',
                    422
                );
            }

            $station->update(['status' => Station::STATUS_CHARGING]);

            return $session->fresh();
        });

        try {
            $ocppResponse = $this->queueResumeStart(
                $station->fresh(),
                $created,
                $resolvedConnectorId,
                $stopCommandId
            );
        } catch (\Throwable $exception) {
            $this->chargingStopService->finalizeStop(
                $created->fresh(),
                $station->fresh(),
                'app',
                null,
                null,
                'RemoteStartFailed',
                [
                    'trigger' => 'charging.resume.ocpp_failed',
                    'error' => $exception->getMessage(),
                ]
            );

            throw new RuntimeException(
                'Nu s-a putut trimite comanda de continuare catre statie. Soldul a fost eliberat.',
                422
            );
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

    /**
     * Reuse the empty pending session instead of spawning another 0 kWh row.
     *
     * @return array{
     *     session: ChargingSession,
     *     station: Station,
     *     previous_session: ?ChargingSession,
     *     ocpp: array<string, mixed>,
     *     connector_id: int
     * }
     */
    private function retryPendingResume(
        User $user,
        Station $station,
        ChargingSession $session,
        int $connectorId,
        string $status,
    ): array {
        if ($this->hasInFlightRemoteStart($session)) {
            return [
                'session' => $session->fresh(['station']),
                'station' => $station->fresh(),
                'previous_session' => null,
                'ocpp' => [
                    'status' => 'queued',
                    'resume_recovery' => false,
                    'message' => 'Repornirea este deja in curs pe acest port.',
                ],
                'connector_id' => $connectorId,
            ];
        }

        try {
            $ocppResponse = $this->queueResumeStart($station->fresh(), $session->fresh(), $connectorId, null);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Nu s-a putut retrimite comanda de continuare catre statie. Incearca din nou peste cateva secunde.',
                422
            );
        }

        $this->auditLogService->record(
            action: 'charging.resume.retry',
            actor: $user,
            subjectType: ChargingSession::class,
            subjectId: $session->id,
            station: $station->fresh(),
            session: $session,
            metadata: [
                'connector_id' => $connectorId,
                'connector_status' => $status,
                'ocpp' => $ocppResponse,
            ]
        );

        return [
            'session' => $session->fresh(['station']),
            'station' => $station->fresh(),
            'previous_session' => null,
            'ocpp' => $ocppResponse,
            'connector_id' => $connectorId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueResumeStart(
        Station $station,
        ChargingSession $session,
        int $connectorId,
        ?int $dependsOnStopCommandId,
    ): array {
        if ($this->ocppService->isSimulatorMode()) {
            $ocppResponse = $this->ocppService->queueRemoteStart($station, $session);
            $ocppResponse['resume_recovery'] = false;

            return $ocppResponse;
        }

        // Pe Suspended/Finishing NU facem RemoteStart gol — Soft Reset elibereaza tranzactia veche.
        $recoveryIds = $this->ocppService->recoverConnectorForRemoteStart(
            $station,
            $connectorId,
            $session,
            'user_resume_suspended',
            true
        );

        if ($recoveryIds === []) {
            throw new RuntimeException(
                'Reset conector indisponibil momentan. Asteapta confirmarea comenzilor in curs.',
                409
            );
        }

        if ($dependsOnStopCommandId) {
            $firstRecovery = OcppCommand::query()->find($recoveryIds[0] ?? null);
            if ($firstRecovery && ! $firstRecovery->depends_on_command_id) {
                $firstRecovery->update(['depends_on_command_id' => $dependsOnStopCommandId]);
            }
        }

        return [
            'station_id' => $station->id,
            'mode' => config('services.ocpp.mode'),
            'status' => 'queued',
            'message' => 'Continua: RemoteStop (daca e nevoie) + reset conector + RemoteStart.',
            'command_ids' => $recoveryIds,
            'stop_command_id' => $dependsOnStopCommandId,
            'resume_recovery' => true,
        ];
    }

    private function findRecentEmptySession(
        User $user,
        Station $station,
        int $connectorId,
        ?int $excludeSessionId
    ): ?ChargingSession {
        $query = ChargingSession::query()
            ->where('station_id', $station->id)
            ->where('user_id', $user->id)
            ->where('ocpp_connector_id', $connectorId)
            ->whereNull('end_time')
            ->whereNull('ocpp_transaction_id')
            ->whereIn('start_source', ['app', 'app_resume'])
            ->where('start_time', '>=', now()->subMinutes(10))
            ->latest('id');

        if ($excludeSessionId) {
            $query->where('id', '!=', $excludeSessionId);
        }

        $session = $query->first();

        return $session && ! $session->hasConfirmedOcppTransaction() ? $session : null;
    }

    private function hasInFlightRemoteStart(ChargingSession $session): bool
    {
        return OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->whereIn('action', ['RemoteStartTransaction', 'RequestStartTransaction'])
            ->whereIn('status', [
                OcppCommand::STATUS_PENDING,
                OcppCommand::STATUS_SENT,
            ])
            ->exists();
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
            ->where('created_at', '>=', now()->subMinutes(3))
            ->latest('id')->first();
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

    private static function connectorStatusLabel(?string $status): string
    {
        return match ($status) {
            'Available' => 'liber',
            'Preparing' => 'masina conectata',
            'Charging' => 'se incarca',
            'SuspendedEV' => 'pauza (masina)',
            'SuspendedEVSE' => 'pauza (statie)',
            'Finishing' => 'incarcare oprita',
            'Reserved' => 'rezervat',
            'Unavailable' => 'indisponibil',
            'Faulted' => 'eroare port',
            null, '' => 'necunoscut',
            default => $status,
        };
    }
}
