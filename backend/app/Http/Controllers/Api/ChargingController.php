<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Models\Station;
use App\Services\AuditLogService;
use App\Services\BillingService;
use App\Services\OcppService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChargingController extends Controller
{
    public function __construct(
        private readonly OcppService $ocppService,
        private readonly AuditLogService $auditLogService,
        private readonly BillingService $billingService
    ) {
    }

    public function start(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'station_id' => 'required|exists:stations,id',
        ]);

        try {
            $session = DB::transaction(function () use ($payload, $request) {
                $station = Station::query()
                    ->whereKey($payload['station_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $station) {
                    throw new RuntimeException('Statia nu a fost gasita.', 404);
                }

                if ($station->status !== Station::STATUS_AVAILABLE) {
                    throw new RuntimeException('Statia nu este disponibila.', 422);
                }

                $activeSession = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->whereNull('end_time')
                    ->lockForUpdate()
                    ->first();

                if ($activeSession) {
                    throw new RuntimeException('Statia are deja o sesiune activa.', 422);
                }

                $this->ocppService->ensureReadyForRemoteCommands($station);

                $session = ChargingSession::query()->create([
                    'user_id' => $request->user()->id,
                    'station_id' => $station->id,
                    'ocpp_id_tag' => 'user:' . $request->user()->id,
                    'start_source' => 'app',
                    'meter_start_kwh' => $station->meter_value_kwh,
                    'start_time' => now(),
                    'kwh_consumed' => 0,
                ]);

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

        $ocppResponse = $this->ocppService->startTransaction($session['station'], $session['session'], $request->user());

        return response()->json([
            'message' => 'Incarcarea a pornit.',
            'session' => $session['session'],
            'ocpp' => $ocppResponse,
        ], 201);
    }

    public function stop(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'station_id' => 'required|exists:stations,id',
        ]);

        try {
            $result = DB::transaction(function () use ($payload, $request) {
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

                $endTime = Carbon::now();
                $minutes = max(1, (int) $session->start_time->diffInMinutes($endTime));
                $kwhConsumed = round($minutes * 0.12, 2);

                $session->update([
                    'end_time' => $endTime,
                    'kwh_consumed' => $kwhConsumed,
                    'meter_stop_kwh' => $station->meter_value_kwh,
                    'stop_source' => 'app',
                ]);

                $invoice = $this->billingService->createSessionInvoice($session->fresh());
                $station->update(['status' => Station::STATUS_AVAILABLE]);

                $this->auditLogService->record(
                    action: 'charging.stop',
                    actor: $request->user(),
                    subjectType: ChargingSession::class,
                    subjectId: $session->id,
                    station: $station,
                    session: $session,
                    metadata: [
                        'status_before' => Station::STATUS_CHARGING,
                        'status_after' => Station::STATUS_AVAILABLE,
                        'duration_minutes' => $minutes,
                        'kwh_consumed' => $kwhConsumed,
                    ]
                );

                return [
                    'session' => $session->fresh(),
                    'duration_minutes' => $minutes,
                    'station' => $station->fresh(),
                    'invoice' => $invoice->fresh(),
                ];
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getCode() ?: 500);
        }

        $ocppResponse = $this->ocppService->stopTransaction($result['station'], $result['session']);

        return response()->json([
            'message' => 'Incarcarea a fost oprita.',
            'session' => $result['session'],
            'duration_minutes' => $result['duration_minutes'],
            'invoice' => $result['invoice'],
            'ocpp' => $ocppResponse,
        ]);
    }
}
