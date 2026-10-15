<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects API tokens issued before the user's last password change.
 */
class EnsureTokenVersion
{
    public const CLAIM = 'tv';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('api');
        $user = $guard->user();

        // No bearer token means the user was set directly (tests' actingAs); auth:api requires one otherwise.
        if ($user && $guard->getToken()) {
            // Tokens from before this claim existed count as version 0.
            $tokenVersion = (int) ($guard->payload()->get(self::CLAIM) ?? 0);

            if ($tokenVersion !== (int) $user->token_version) {
                return response()->json([
                    'message' => 'Sesiunea a expirat. Autentifica-te din nou.',
                ], 401);
            }
        }

        return $next($request);
    }
}
