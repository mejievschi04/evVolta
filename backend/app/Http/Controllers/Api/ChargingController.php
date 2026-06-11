<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Models\Station;
use App\Services\AuditLogService;
use App\Services\ChargingStopService;
use App\Services\OcppService;
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
        private readonly WalletService $walletService,
    ) {
    }

    public function start(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'connector_id' => 'nullable|integer|min:1|max:8',
            'budget_amount' => 'nullable|numeric|min:10|max:50000',
        ]);

        try {
            $station = Station::query()->findOrFail($payload['station_id']);
            $station = $this->ocppService->syncConnectorStateBeforeStart($station);

            $session = DB::transaction(function () use ($payload, $request, $station) {
                $user = $request->user();

                if ($user->usesCardPayment()) {
                    $this->walletService->assertCanHoldBudget(
                        $user,
                        (float) ($payload['budget_amount'] ?? 0)
                    );
                }

                $station = Station::query()
                    ->whereKey($payload['station_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $station) {
                    throw new RuntimeException('Statia nu a fost gasita.', 404);
                }

                $connectorId = $station->resolveStartConnectorId(
                    isset($payload['connector_id']) ? (int) $payload['connector_id'] : null
                );

                if (! $station->canAcceptRemoteStart($connectorId)) {
                    throw new RuntimeException('Conectorul selectat nu este disponibil pentru pornire.', 422);
                }

                $activeSession = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->whereNull('end_time')
                    ->lockForUpdate()
                    ->first();

                if ($activeSession && $activeSession->user_id !== $request->user()->id) {
                    throw new RuntimeException('Statia are deja o sesiune activa.', 422);
                }

                if ($activeSession && $activeSession->user_id === $request->user()->id) {
                    if (
                        $activeSession->ocpp_transaction_id
                        && $this->ocppService->sessionIsPhysicallyActive($activeSession, $station)
                    ) {
                        return [
                            'session' => $activeSession->fresh(),
                            'station' => $station->fresh(),
                        ];
                    }

                    $this->ocppService->ensureReadyForRemoteCommands($station);

                    $activeSession->update([
                        'ocpp_connector_id' => $connectorId,
                        'ocpp_id_tag' => $this->ocppService->remoteStartIdTag($station, $connectorId, $request->user()),
                    ]);
                    $station->update(['status' => Station::STATUS_CHARGING]);

                    return [
                        'session' => $activeSession->fresh(),
                        'station' => $station->fresh(),
                    ];
                }

                $this->ocppService->ensureReadyForRemoteCommands($station);

                $session = ChargingSession::query()->create([
                    'user_id' => $request->user()->id,
                    'station_id' => $station->id,
                    'ocpp_connector_id' => $connectorId,
                    'ocpp_id_tag' => $this->ocppService->remoteStartIdTag($station, $connectorId, $request->user()),
                    'start_source' => 'app',
                    'start_time' => now(),
                    'kwh_consumed' => 0,
                ]);

                if ($this->walletService->enabled() && $request->user()->usesCardPayment()) {
                    $this->walletService->holdBudgetForSession(
                        $request->user()->fresh(),
                        $session->fresh(),
                        (float) ($payload['budget_amount'] ?? 0)
                    );
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
                ];
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getCode() ?: 500);
        }

        $ocppResponse = $this->ocppService->queueRemoteStart($session['station'], $session['session'], $request->user());

        return response()->json([
            'message' => 'Incarcarea a pornit.',
            'session' => $session['session'],
            'ocpp' => $ocppResponse,
            'connector_id' => $session['session']->ocpp_connector_id,
        ], 201);
    }

    public function stop(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'station_id' => 'required|exists:stations,id',
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

                $session = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->where('user_id', $request->user()->id)
                    ->whereNull('end_time')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

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

        return response()->json($result);
    }
}
