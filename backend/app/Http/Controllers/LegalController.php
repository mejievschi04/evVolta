<?php

namespace App\Http\Controllers;

use App\Services\LegalAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    public function terms(Request $request): Response
    {
        return $this->documentResponse($request, 'terms');
    }

    public function privacy(Request $request): Response
    {
        return $this->documentResponse($request, 'privacy');
    }

    private function documentResponse(Request $request, string $activeDoc): Response
    {
        return response()
            ->view('legal.'.$activeDoc, $this->sharedViewData($request, $activeDoc))
            ->header('Content-Security-Policy', (string) config('security.csp_document'));
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedViewData(Request $request, string $activeDoc): array
    {
        $isApiDocument = $request->is('api/legal/*');

        return [
            'companyName' => config('legal.company_name'),
            'appName' => config('legal.app_name', config('legal.company_name')),
            'contactEmail' => config('legal.contact_email'),
            'supportPhone' => config('legal.support_phone'),
            'legalVersion' => $this->legalAcceptanceService->currentVersion(),
            'effectiveDate' => (string) config('legal.effective_date', '29 iulie 2026'),
            'rightsSlaDays' => (int) config('privacy.rights_sla_days', 30),
            'authority' => config('privacy.supervisory_authority', []),
            'processors' => config('privacy.processors', []),
            'retention' => config('privacy.retention', []),
            'devicePermissions' => config('privacy.device_permissions', []),
            'activeDoc' => $activeDoc,
            'isApp' => $isApiDocument || $request->boolean('app'),
            'termsUrl' => $isApiDocument ? url('/api/legal/terms?app=1') : url('/legal/terms'.($request->boolean('app') ? '?app=1' : '')),
            'privacyUrl' => $isApiDocument ? url('/api/legal/privacy?app=1') : url('/legal/privacy'.($request->boolean('app') ? '?app=1' : '')),
        ];
    }
}
