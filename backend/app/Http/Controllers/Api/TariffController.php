<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TariffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TariffController extends Controller
{
    public function current(Request $request, TariffService $tariffService): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'price_per_kwh' => $tariffService->pricePerKwhForUser($user),
            'customer_price_per_kwh' => $tariffService->globalPricePerKwh(),
            'personal_price_per_kwh' => $tariffService->personalPricePerKwh(),
            'account_type' => $user?->account_type,
            'currency' => 'MDL',
        ]);
    }
}
