<?php

namespace App\Http\Controllers;

use App\Services\LegalAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LegalController extends Controller
{
    public function __construct(
        private readonly LegalAcceptanceService $legalAcceptanceService,
    ) {
    }

    public function config(Request $request): JsonResponse
    {
        return response()->json($this->legalAcceptanceService->publicConfig($request));
    }

    public function terms(): View
    {
        return view('legal.terms', $this->sharedViewData());
    }

    public function privacy(): View
    {
        return view('legal.privacy', $this->sharedViewData());
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedViewData(): array
    {
        return [
            'companyName' => config('legal.company_name'),
            'contactEmail' => config('legal.contact_email'),
            'supportPhone' => config('legal.support_phone'),
            'legalVersion' => $this->legalAcceptanceService->currentVersion(),
            'effectiveDate' => '21 iulie 2026',
        ];
    }
}
