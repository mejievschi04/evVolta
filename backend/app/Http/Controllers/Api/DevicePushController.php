<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicePushController extends Controller
{
    public function register(Request $request, PushNotificationService $pushNotificationService): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|max:255',
            'platform' => 'nullable|string|max:32',
        ]);

        $record = $pushNotificationService->registerToken(
            $request->user(),
            $data['token'],
            $data['platform'] ?? null
        );

        return response()->json([
            'message' => 'Token inregistrat.',
            'id' => $record->id,
        ]);
    }

    public function unregister(Request $request, PushNotificationService $pushNotificationService): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|max:255',
        ]);

        $pushNotificationService->unregisterToken($request->user(), $data['token']);

        return response()->json([
            'message' => 'Token eliminat.',
        ]);
    }
}
