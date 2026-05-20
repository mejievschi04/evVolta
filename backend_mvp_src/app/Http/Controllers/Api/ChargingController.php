<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Models\Station;
use App\Services\OcppService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChargingController extends Controller
{
    public function __construct(private readonly OcppService $ocppService)
    {
    }

    public function start(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'station_id' => 'required|exists:stations,id',
        ]);

        $station = Station::query()->findOrFail($payload['station_id']);

        if ($station->status !== Station::STATUS_AVAILABLE) {
            return response()->json([
                'message' => 'Station is not available.',
            ], 422);
        }

        $activeSession = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->first();

        if ($activeSession) {
            return response()->json([
                'message' => 'Station already has an active session.',
            ], 422);
        }

        $ocppResponse = $this->ocppService->startTransaction((int) $station->id);

        $session = ChargingSession::query()->create([
            'user_id' => $request->user()->id,
            'station_id' => $station->id,
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        $station->update(['status' => Station::STATUS_CHARGING]);

        return response()->json([
            'message' => 'Charging started.',
            'session' => $session,
            'ocpp' => $ocppResponse,
        ], 201);
    }

    public function stop(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'station_id' => 'required|exists:stations,id',
        ]);

        $station = Station::query()->findOrFail($payload['station_id']);

        $session = ChargingSession::query()
            ->where('station_id', $station->id)
            ->where('user_id', $request->user()->id)
            ->whereNull('end_time')
            ->latest('id')
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'No active session found for this user and station.',
            ], 404);
        }

        $ocppResponse = $this->ocppService->stopTransaction((int) $station->id);

        $endTime = Carbon::now();
        $minutes = max(1, (int) $session->start_time->diffInMinutes($endTime));
        $kwhConsumed = round($minutes * 0.12, 2);

        $session->update([
            'end_time' => $endTime,
            'kwh_consumed' => $kwhConsumed,
        ]);

        $station->update(['status' => Station::STATUS_AVAILABLE]);

        return response()->json([
            'message' => 'Charging stopped.',
            'session' => $session->fresh(),
            'duration_minutes' => $minutes,
            'ocpp' => $ocppResponse,
        ]);
    }
}
