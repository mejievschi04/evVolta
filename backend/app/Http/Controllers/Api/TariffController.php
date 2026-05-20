<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tariff;
use Illuminate\Http\JsonResponse;

class TariffController extends Controller
{
    public function current(): JsonResponse
    {
        $tariff = Tariff::query()->latest('id')->first();

        return response()->json([
            'price_per_kwh' => (float) ($tariff?->price_per_kwh ?? config('billing.price_per_kwh', 0.20)),
            'currency' => 'MDL',
        ]);
    }
}
