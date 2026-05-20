<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StationFavorite;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationController extends Controller
{
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

        $stations = $query->orderBy('name')->get()->map(function (Station $station) use ($favoriteStationIds) {
            $station->setAttribute('is_favorite', in_array($station->id, $favoriteStationIds, true));
            $station->setAttribute('live_status', $station->liveStatus());

            return $station;
        });

        return response()->json($stations);
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
}
