<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\StationFavorite;
use App\Models\Station;
use App\Services\ChargingStopService;
use App\Services\OcppService;
use App\Services\QrStationResolver;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationController extends Controller
{
    public function __construct(
        private readonly QrStationResolver $qrStationResolver,
        private readonly OcppService $ocppService,
        private readonly ChargingStopService $chargingStopService,
        private readonly WalletService $walletService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $favoriteStationIds = $request->user()
            ->stationFavorites()
            ->pluck('station_id')
            ->all();

        $query = Station::query();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', '%' . $search . '%')
                    ->orWhere('location', 'like', '%' . $search . '%')
                    ->orWhere('connector_type', 'like', '%' . $search . '%');
            });
        }

        if ($status = trim((string) $request->query('status', ''))) {
            $query->where('status', $status);
        }

        if ($connector = trim((string) $request->query('connector', ''))) {
            $query->where('connector_type', $connector);
        }

        if ($request->filled('min_power')) {
            $query->where('power_kw', '>=', (float) $request->query('min_power'));
        }

        if ($request->filled('max_power')) {
            $query->where('power_kw', '<=', (float) $request->query('max_power'));
        }

        if ($request->boolean('favorite_only')) {
            $query->whereIn('id', $favoriteStationIds);
        }

        Station::markStaleOcppConnectionsOffline();

        $stations = $query->orderBy('name')->get()->map(function (Station $station) use ($favoriteStationIds) {
            $station->setAttribute('is_favorite', in_array($station->id, $favoriteStationIds, true));
            $station->setAttribute('live_status', $station->liveStatus());
            $station->setAttribute('display_status', $station->displayStatus());

            return $station;
        });

        return response()->json($stations);
    }

    public function resolveQr(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'code' => 'required|string|max:2048',
        ]);

        $result = $this->qrStationResolver->resolve($payload['code']);

        if (! $result) {
            return response()->json([
                'message' => 'Codul scanat nu corespunde unei statii inregistrate.',
            ], 404);
        }

        $station = $result['station'];
        $connectorId = $result['connector_id'];

        return response()->json([
            'station_id' => $station->id,
            'connector_id' => $connectorId,
            'station' => [
                'id' => $station->id,
                'name' => $station->name,
                'location' => $station->location,
                'live_status' => $station->liveStatus($connectorId),
            ],
        ]);
    }

    public function toggleFavorite(Request $request, Station $station): JsonResponse
    {
        $favorite = StationFavorite::query()
            ->where('user_id', $request->user()->id)
            ->where('station_id', $station->id)
            ->first();

        if ($favorite) {
            $favorite->delete();
            $isFavorite = false;
        } else {
            StationFavorite::query()->create([
                'user_id' => $request->user()->id,
                'station_id' => $station->id,
            ]);
            $isFavorite = true;
        }

        return response()->json([
            'station_id' => $station->id,
            'is_favorite' => $isFavorite,
        ]);
    }

    public function refreshStatus(Request $request, Station $station): JsonResponse
    {
        $activeSession = ChargingSession::query()
            ->where('station_id', $station->id)
            ->where('user_id', $request->user()->id)
            ->whereNull('end_time')
            ->latest('id')
            ->first();

        if ($activeSession) {
            $hasPendingStop = OcppCommand::query()
                ->where('charging_session_id', $activeSession->id)
                ->whereIn('action', ['RemoteStopTransaction', 'RequestStopTransaction'])
                ->whereIn('status', [
                    OcppCommand::STATUS_PENDING,
                    OcppCommand::STATUS_SENT,
                    OcppCommand::STATUS_ACCEPTED,
                ])
                ->exists();

            if (! $hasPendingStop) {
                $station = $this->ocppService->refreshSessionTelemetry(
                    $station,
                    (int) ($activeSession->ocpp_connector_id ?: 1)
                );
            }
        } else {
            $station = $this->ocppService->refreshConnectorStatus($station);
        }

        $response = [
            'station_id' => $station->id,
            'live_status' => $station->liveStatus(),
        ];

        if ($activeSession) {
            $activeSession = $activeSession->fresh();
            $station = $station->fresh();
            $connectorStatus = $station->connectorOcppStatus((int) ($activeSession->ocpp_connector_id ?: 1));

            if ($this->chargingStopService->shouldAutoFinalizeOnConnectorRelease($activeSession, $station, $connectorStatus)) {
                $result = $this->chargingStopService->finalizeStop($activeSession, $station, 'ocpp');
                $response['session_completed'] = $result['session']->load('station');
                $response['invoice'] = $result['invoice'] ?? null;
            } else {
                app(WalletService::class)->maybeAutoStopForBudget($activeSession, $station);
                $activeSession = $activeSession->fresh();
                $response['session'] = $activeSession->load('station');
            }
        }

        return response()->json($response);
    }

    public function resetConnector(Request $request, Station $station): JsonResponse
    {
        $payload = $request->validate([
            'connector_id' => 'sometimes|integer|min:1|max:9',
            'retry_remote_start' => 'sometimes|boolean',
        ]);

        $activeSession = ChargingSession::query()
            ->where('station_id', $station->id)
            ->where('user_id', $request->user()->id)
            ->whereNull('end_time')
            ->latest('id')
            ->first();

        if (! $activeSession) {
            return response()->json([
                'message' => 'Nu exista o sesiune activa pe aceasta statie.',
            ], 404);
        }

        $connectorId = (int) ($payload['connector_id'] ?? $activeSession->ocpp_connector_id ?? 1);
        $retryRemoteStart = array_key_exists('retry_remote_start', $payload)
            ? (bool) $payload['retry_remote_start']
            : ! $activeSession->ocpp_transaction_id;

        try {
            $result = $this->ocppService->manualResetConnector(
                $station,
                $connectorId,
                $activeSession,
                $retryRemoteStart
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getCode() ?: 422);
        }

        return response()->json([
            'status' => $result['status'],
            'station_id' => $result['station_id'],
            'connector_id' => $result['connector_id'],
            'command_ids' => $result['command_ids'],
            'session' => $activeSession->fresh()->load('station'),
        ]);
    }

    public function unlockConnector(Request $request, Station $station): JsonResponse
    {
        $payload = $request->validate([
            'connector_id' => 'sometimes|integer|min:1|max:9',
        ]);

        $activeSession = ChargingSession::query()
            ->where('station_id', $station->id)
            ->where('user_id', $request->user()->id)
            ->whereNull('end_time')
            ->latest('id')
            ->first();

        if (! $activeSession) {
            return response()->json([
                'message' => 'Nu exista o sesiune activa pe aceasta statie.',
            ], 404);
        }

        $connectorId = (int) ($payload['connector_id'] ?? $activeSession->ocpp_connector_id ?? 1);

        try {
            $result = $this->ocppService->unlockConnector($station, $connectorId);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getCode() ?: 422);
        }

        return response()->json([
            'status' => $result['status'],
            'command_id' => $result['command_id'],
            'connector_id' => $connectorId,
        ]);
    }
}
