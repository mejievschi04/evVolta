<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Station;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $status = trim((string) $request->query('status', ''));

        $query = Reservation::query()
            ->with(['station:id,name,location'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('starts_at');

        if ($status !== '') {
            $query->where('status', $status);
        }

        $reservations = $query->limit(100)->get()
            ->map(fn (Reservation $reservation) => $this->reservationService->present($reservation));

        return response()->json(['data' => $reservations]);
    }

    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        if ($reservation->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Rezervarea nu iti apartine.'], 403);
        }

        return response()->json([
            'data' => $this->reservationService->present($reservation->load('station:id,name,location')),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'connector_id' => 'required|integer|min:1|max:8',
            'starts_at' => 'required|date',
            'duration_minutes' => 'required|integer|min:15|max:480',
        ]);

        try {
            $station = Station::query()->findOrFail($payload['station_id']);
            $result = $this->reservationService->book(
                $request->user(),
                $station,
                (int) $payload['connector_id'],
                Carbon::parse($payload['starts_at']),
                (int) $payload['duration_minutes'],
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getCode() ?: 500);
        }

        return response()->json([
            'message' => 'Rezervarea a fost creata.',
            ...$result,
        ], 201);
    }

    public function cancel(Request $request, Reservation $reservation): JsonResponse
    {
        try {
            $result = $this->reservationService->cancel($request->user(), $reservation);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getCode() ?: 500);
        }

        return response()->json([
            'message' => 'Rezervarea a fost anulata.',
            ...$result,
        ]);
    }

    public function availability(Request $request, Station $station): JsonResponse
    {
        $payload = $request->validate([
            'date' => 'nullable|date',
            'connector_id' => 'nullable|integer|min:1|max:8',
        ]);

        $day = isset($payload['date']) ? Carbon::parse($payload['date']) : now();
        $connectorId = isset($payload['connector_id']) ? (int) $payload['connector_id'] : null;

        return response()->json([
            'station_id' => $station->id,
            'policy' => $station->reservationPolicy(),
            'connectors' => collect($station->expectedConnectorIds())
                ->map(fn (int $id) => [
                    'id' => $id,
                    'label' => Station::connectorPortLabel($id),
                    'can_reserve' => $station->connectorCanReserve($id, $request->user()),
                    'availability' => $station->liveStatus($id, $request->user())['availability'] ?? null,
                ])
                ->values()
                ->all(),
            'slots' => $this->reservationService->availabilityForStation($station, $connectorId, $day),
        ]);
    }
}
