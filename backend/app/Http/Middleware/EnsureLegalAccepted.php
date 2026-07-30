<?php

namespace App\Http\Middleware;

use App\Services\LegalAcceptanceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLegalAccepted
{
    public function __construct(
        private readonly LegalAcceptanceService $legalAcceptanceService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->isAdmin()) {
            return $next($request);
        }

        if ($this->legalAcceptanceService->hasCurrentAcceptance($user)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Trebuie sa accepti versiunea curenta a Termenilor si Politicii de confidentialitate.',
            'code' => 'LEGAL_ACCEPTANCE_REQUIRED',
            'legal' => $this->legalAcceptanceService->publicConfig($request),
        ], 428);
    }
}
