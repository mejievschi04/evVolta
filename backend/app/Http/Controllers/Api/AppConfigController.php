<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSupportedAppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppConfigController extends Controller
{
    /**
     * Read by the app at start-up to show the forced-update screen before any other call.
     */
    public function show(Request $request): JsonResponse
    {
        $minVersion = trim((string) config('mobile.min_version', ''));
        $platform = EnsureSupportedAppVersion::platform($request);

        return response()->json([
            'min_version' => $minVersion !== '' ? $minVersion : null,
            'store_url' => $platform ? config('mobile.store_urls.'.$platform) : null,
            'store_urls' => config('mobile.store_urls'),
        ]);
    }
}
