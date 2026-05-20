<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sessions = ChargingSession::query()
            ->with('station:id,name,location')
            ->where('user_id', $request->user()->id)
            ->latest('start_time')
            ->get();

        return response()->json($sessions);
    }
}
