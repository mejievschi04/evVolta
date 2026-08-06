<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Services\AuditLogService;
use App\Services\ChargingResumeService;
use App\Services\ChargingStopService;
use App\Services\OcppService;
use App\Services\ReservationService;
use App\Services\SessionPresentationService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChargingController extends Controller
{
    public function __construct(
        private readonly OcppService $ocppService,
        private readonly AuditLogService $auditLogService,
        private readonly ChargingStopService $chargingStopService,
        private readonly ChargingResumeService $chargingResumeService,
        private readonly WalletService $walletService,
        private readonly SessionPresentationService $sessionPresentationService,
        private readonly ReservationService $reservationService,
    ) {
    }

    public function start(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'connector_id' => 'nullable|integer|min:1|max:8',
            'budget_amount' => 'nullable|numeric|min:10|max:50000',
            'target_kwh' => 'nullable|numeric|min:0.1|max:500',
        ]);

        try {
            $station = Station::query()->findOrFail($payload['station_id']);
            $station = $this->ocppService->syncConnectorStateBeforeStart($station);

            if ($this->ocppService->shouldEnforcePlugCheck($station) && ! $station->isOcppOnline()) {
                throw new RuntimeException('Statia nu este conectata la gateway-ul OCPP.', 422);
            }

            $session = DB::transaction(function () use ($payload, $request, $station) {
                $user = $request->user();
                $prepaidLimits = null;

                if ($this->walletService->enabled() && $user->usesCardPayment()) {
                    $prepaidLimits = $this->walletService->resolvePrepaidStart(
                        isset($payload['budget_amount']) ? (float) $payload['budget_amount'] : null,
                        isset($payload['target_kwh']) ? (float) $payload['target_kwh'] : null,
                        $user,
                    );
                    $this->walletService->assertCanHoldBudget($user, $prepaidLimits['budget_amount']);
                }

                $station = Station::query()
                    ->whereKey($payload['station_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $station) {
                    throw new RuntimeException('Statia nu a fost gasita.', 404);
                }

                $requestedConnector = isset($payload['connector_id']) ? (int) $payload['connector_id'] : null;

                if ($requestedConnector !== null) {
                    if ($station->hasActiveSessionOnConnector($requestedConnector)) {
                        $occupant = ChargingSession::query()
                            ->where('station_id', $station->id)
                            ->where('ocpp_connector_id', $requestedConnector)
                            ->whereNull('end_time')
                            ->first();

                        if ($occupant && $occupant->user_id !== $user->id) {
                            throw new RuntimeException('Conectorul este deja folosit de alt utilizator.', 422);
                        }
                    }

                    if (! $station->connectorCanStart($requestedConnector, $user)) {
                        throw new RuntimeException('Conectorul selectat nu este disponibil pentru pornire.', 422);
                    }
                }

                $connectorId = $station->resolveStartConnectorIdForUser($user, $requestedConnector);

                $this->reservationService->assertUserMayStart($user, $station, $connectorId);

                if (! in_array($connectorId, $station->expectedConnectorIds(), true)) {
                    throw new RuntimeException('Conector invalid pentru aceasta statie.', 422);
                }

                if ($this->ocppService->shouldEnforcePlugCheck($station)) {
                    $station = $this->ocppService->syncConnectorStateBeforeStart($station);
                }

                $this->reservationService->assertConnectorPlugged($station, $connectorId);

                if (! $station->canAcceptRemoteStart($connectorId, $user)) {
                    throw new RuntimeException('Conectorul selectat nu este disponibil pentru pornire.', 422);
                }

                $this->chargingStopService->reconcileOpenSessionsBeforeStart(
                    $station,
                    (int) $user->id,
                    $connectorId
                );

                $activeOnConnector = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->where('ocpp_connector_id', $connectorId)
                    ->whereNull('end_time')
                    ->lockForUpdate()
                    ->first();

                if ($activeOnConnector && $activeOnConnector->user_id !== $user->id) {
                    throw new RuntimeException('Conectorul este deja folosit de alt utilizator.', 422);
                }

                $userSessionOnConnector = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->where('user_id', $user->id)
                    ->where('ocpp_connector_id', $connectorId)
                    ->whereNull('end_time')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($userSessionOnConnector) {
                    if (
                        $userSessionOnConnector->ocpp_transaction_id
                        && $this->chargingStopService->sessionIsCurrentlyCharging($userSessionOnConnector, $station)
                    ) {
                        return [
                            'session' => $userSessionOnConnector->fresh(),
                            'station' => $station->fresh(),
                        ];
                    }

                    $connectorStatus = $station->connectorOcppStatus($connectorId);

                    // EU1060: Finishing + cablu inca in priza — inchide sesiunea veche
                    // si continua cu o pornire noua pe acelasi port (nu pe B).
                    if ($connectorStatus === 'Finishing') {
                        $this->chargingStopService->finalizeStop(
                            $userSessionOnConnector,
                            $station,
                            'system',
                            null,
                            null,
                            'ForceRestartFinishing',
                            ['trigger' => 'force_restart_finishing']
                        );
                        $station = $station->fresh();
                    } else {
                        $this->ocppService->ensureReadyForRemoteCommands($station);

                        $userSessionOnConnector->update([
                            'ocpp_id_tag' => $this->ocppService->remoteStartIdTag($station, $connectorId, $user),
                        ]);
                        $station->update(['status' => Station::STATUS_CHARGING]);

                        return [
                            'session' => $userSessionOnConnector->fresh(),
                            'station' => $station->fresh(),
                            'force_finishing_recovery' => false,
                        ];
                    }
                }

                $pendingWithoutConnector = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->where('user_id', $user->id)
                    ->whereNull('end_time')
                    ->whereNull('ocpp_transaction_id')
                    ->whereNull('ocpp_connector_id')
                    ->lockForUpdate()
                    ->first();

                if ($pendingWithoutConnector) {
                    $this->ocppService->ensureReadyForRemoteCommands($station);

                    $pendingWithoutConnector->update([
                        'ocpp_connector_id' => $connectorId,
                        'ocpp_id_tag' => $this->ocppService->remoteStartIdTag($station, $connectorId, $user),
                    ]);
                    $station->update(['status' => Station::STATUS_CHARGING]);

                    return [
                        'session' => $pendingWithoutConnector->fresh(),
                        'station' => $station->fresh(),
                        'force_finishing_recovery' => $station->connectorOcppStatus($connectorId) === 'Finishing',
                    ];
                }

                // Sesiune proprie fara tranzactie pe alt conector (ex. detectare gresita) —
                // remapeaza pe portul rezolvat in loc sa creeze o sesiune noua.
                $pendingWrongConnector = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->where('user_id', $user->id)
                    ->whereNull('end_time')
                    ->whereNull('ocpp_transaction_id')
                    ->where('ocpp_connector_id', '!=', $connectorId)
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if (
                    $pendingWrongConnector
                    && ! $this->chargingStopService->sessionIsCurrentlyCharging($pendingWrongConnector, $station)
                ) {
                    $this->ocppService->ensureReadyForRemoteCommands($station);

                    $pendingWrongConnector->update([
                        'ocpp_connector_id' => $connectorId,
                        'ocpp_id_tag' => $this->ocppService->remoteStartIdTag($station, $connectorId, $user),
                    ]);
                    $station->update(['status' => Station::STATUS_CHARGING]);

                    return [
                        'session' => $pendingWrongConnector->fresh(),
                        'station' => $station->fresh(),
                        'force_finishing_recovery' => $station->connectorOcppStatus($connectorId) === 'Finishing',
                    ];
                }

                $this->ocppService->ensureReadyForRemoteCommands($station);

                $expectedConnectorIds = $station->expectedConnectorIds();
                $occupiedConnectorCount = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->whereNull('end_time')
                    ->whereIn('ocpp_connector_id', $expectedConnectorIds)
                    ->distinct()
                    ->count('ocpp_connector_id');

                if ($occupiedConnectorCount >= count($expectedConnectorIds)) {
                    throw new RuntimeException('Toate porturile statiei sunt ocupate.', 422);
                }

                $session = ChargingSession::query()->create([
                    'user_id' => $request->user()->id,
                    'station_id' => $station->id,
                    'ocpp_connector_id' => $connectorId,
                    'ocpp_id_tag' => $this->ocppService->remoteStartIdTag($station, $connectorId, $request->user()),
                    'start_source' => 'app',
                    'start_time' => now(),
                    'kwh_consumed' => 0,
                ]);

                if ($prepaidLimits !== null) {
                    $this->walletService->holdBudgetForSession(
                        $request->user()->fresh(),
                        $session->fresh(),
                        $prepaidLimits['budget_amount'],
                        $prepaidLimits['target_kwh'],
                    );
                }

                $activeReservation = Reservation::query()
                    ->with('station')
                    ->where('user_id', $request->user()->id)
                    ->where('station_id', $station->id)
                    ->where('connector_id', $connectorId)
                    ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_ACTIVE])
                    ->where('starts_at', '<=', now())
                    ->get()
                    ->first(fn (Reservation $reservation) => $reservation->isWithinStartWindow());

                if ($activeReservation) {
                    $this->reservationService->attachSession($activeReservation, $session->id);
                }

                $station->update(['status' => Station::STATUS_CHARGING]);

                $this->auditLogService->record(
                    action: 'charging.start',
                    actor: $request->user(),
                    subjectType: ChargingSession::class,
                    subjectId: $session->id,
                    station: $station,
                    session: $session,
                    metadata: [
                        'status_before' => Station::STATUS_AVAILABLE,
                        'status_after' => Station::STATUS_CHARGING,
                        'ocpp_mode' => config('services.ocpp.mode'),
                    ]
                );

                return [
                    'session' => $session->fresh(),
                    'station' => $station->fresh(),
                    'force_finishing_recovery' => $station->connectorOcppStatus($connectorId) === 'Finishing',
                ];
            });
        } catch (RuntimeException $exception) {
            $stationModel = isset($payload['station_id'])
                ? Station::query()->find($payload['station_id'])
                : null;
            $candidates = $stationModel
                ? $stationModel->startConnectorCandidateOptions($request->user())
                : [];

            $needsPortChoice = $stationModel
                && count($stationModel->expectedConnectorIds()) > 1
                && str_contains($exception->getMessage(), 'Alege portul');

            return response()->json([
                'message' => $exception->getMessage(),
                'requires_connector_selection' => $needsPortChoice,
                'connectors' => $candidates,
            ], $exception->getCode() ?: 500);
        }

        $connectorId = (int) ($session['session']->ocpp_connector_id ?: 1);
        $needsFinishingRecovery = (bool) ($session['force_finishing_recovery'] ?? false)
            || $session['station']->connectorOcppStatus($connectorId) === 'Finishing';

        if ($needsFinishingRecovery) {
            $recoveryIds = $this->ocppService->recoverConnectorForRemoteStart(
                $session['station'],
                $connectorId,
                $session['session'],
                'finishing_restart',
                true
            );

            if ($recoveryIds === []) {
                $ocppResponse = $this->ocppService->queueRemoteStart(
                    $session['station'],
                    $session['session'],
                    $request->user()
                );
                $ocppResponse['finishing_recovery'] = false;
            } else {
                $ocppResponse = [
                    'station_id' => $session['station']->id,
                    'mode' => config('services.ocpp.mode'),
                    'status' => 'queued',
                    'message' => 'Repornire fortata din Finishing: reset conector + RemoteStart.',
                    'command_ids' => $recoveryIds,
                    'finishing_recovery' => true,
                ];
            }
        } else {
            $ocppResponse = $this->ocppService->queueRemoteStart(
                $session['station'],
                $session['session'],
                $request->user()
            );
        }

        return response()->json([
            'message' => 'Incarcarea a pornit.',
            'session' => $session['session'],
            'ocpp' => $ocppResponse,
            'connector_id' => $session['session']->ocpp_connector_id,
        ], 201);
    }

    public function resume(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'session_id' => 'nullable|integer|exists:charging_sessions,id',
            'connector_id' => 'nullable|integer|min:1|max:8',
        ]);

        $station = Station::query()->find($payload['station_id']);

        if (! $station) {
            return response()->json(['message' => 'Statia nu a fost gasita.'], 404);
        }

        try {
            $result = $this->chargingResumeService->resume(
                $request->user(),
                $station,
                isset($payload['session_id']) ? (int) $payload['session_id'] : null,
                isset($payload['connector_id']) ? (int) $payload['connector_id'] : null,
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getCode() >= 400 && $exception->getCode() < 600 ? (int) $exception->getCode() : 422);
        }

        return response()->json([
            'message' => 'Incarcarea continua pe acelasi port.',
            'session' => $this->sessionPresentationService->presentForUser($result['session']),
            'previous_session_id' => $result['previous_session']?->id,
            'ocpp' => $result['ocpp'],
            'connector_id' => $result['connector_id'],
        ], 201);
    }

    public function stop(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'connector_id' => 'nullable|integer|min:1|max:8',
            'session_id' => 'nullable|integer|exists:charging_sessions,id',
        ]);

        try {
            $context = DB::transaction(function () use ($payload, $request) {
                $station = Station::query()
                    ->whereKey($payload['station_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $station) {
                    throw new RuntimeException('Statia nu a fost gasita.', 404);
                }

                $sessionQuery = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->where('user_id', $request->user()->id)
                    ->whereNull('end_time')
                    ->lockForUpdate();

                if (! empty($payload['session_id'])) {
                    $sessionQuery->whereKey((int) $payload['session_id']);
                } elseif (! empty($payload['connector_id'])) {
                    $sessionQuery->where('ocpp_connector_id', (int) $payload['connector_id']);
                }

                $session = $sessionQuery->latest('id')->first();

                if (! $session) {
                    throw new RuntimeException('Nu exista o sesiune activa pentru acest utilizator si aceasta statie.', 404);
                }

                return [
                    'session' => $session,
                    'station' => $station,
                ];
            });

            $result = $this->chargingStopService->requestStop(
                $context['session'],
                $context['station'],
                'app'
            );

            if ($result['status'] === 'completed') {
                $this->auditLogService->record(
                    action: 'charging.stop',
                    actor: $request->user(),
                    subjectType: ChargingSession::class,
                    subjectId: $result['session']->id,
                    station: $result['station'],
                    session: $result['session'],
                    metadata: [
                        'status_before' => Station::STATUS_CHARGING,
                        'status_after' => Station::STATUS_AVAILABLE,
                        'duration_minutes' => $result['duration_minutes'],
                        'kwh_consumed' => $result['session']->kwh_consumed,
                        'ocpp_mode' => config('services.ocpp.mode'),
                    ]
                );
            } else {
                $this->auditLogService->record(
                    action: 'charging.stop.requested',
                    actor: $request->user(),
                    subjectType: ChargingSession::class,
                    subjectId: $result['session']->id,
                    station: $result['station'],
                    session: $result['session'],
                    metadata: [
                        'ocpp_mode' => config('services.ocpp.mode'),
                        'ocpp' => $result['ocpp'] ?? null,
                    ]
                );
            }
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getCode() ?: 500);
        }

        $presentedSession = $result['status'] === 'completed'
            ? $this->sessionPresentationService->presentForUser(
                $result['session']->fresh(['station', 'invoice']),
                ensureInvoice: true,
            )
            : null;

        return response()->json([
            ...$result,
            'session' => $presentedSession ?? $result['session'],
            'invoice' => $presentedSession['invoice']
                ?? ($result['invoice'] ?? null
                    ? $this->sessionPresentationService->invoiceSummary($result['invoice'])
                    : null),
        ]);
    }
}
