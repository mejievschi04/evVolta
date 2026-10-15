<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forced update: API calls from app builds older than mobile.min_version get 426.
 */
class EnsureSupportedAppVersion
{
    // Not called by the app (payment provider, browser pages) or needed to show the update screen.
    private const EXEMPT_PATHS = ['api/maib/*', 'api/password/*', 'api/app/*', 'api/legal/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $minVersion = trim((string) config('mobile.min_version', ''));

        if ($minVersion === '' || $request->is(...self::EXEMPT_PATHS)) {
            return $next($request);
        }

        $version = trim((string) $request->header('X-App-Version', ''));

        // Builds released before this check send no version: they are older by definition.
        if ($version !== '' && version_compare($version, $minVersion, '>=')) {
            return $next($request);
        }

        $platform = self::platform($request);

        return response()->json([
            'message' => 'Este disponibila o versiune noua V CHARGE. Actualizeaza aplicatia pentru a continua.',
            'code' => 'update_required',
            'min_version' => $minVersion,
            'store_url' => $platform ? config('mobile.store_urls.'.$platform) : null,
        ], 426);
    }

    public static function platform(Request $request): ?string
    {
        $platform = strtolower(trim((string) $request->header('X-App-Platform', '')));

        if (in_array($platform, ['android', 'ios'], true)) {
            return $platform;
        }

        // Older builds: React Native's networking stack identifies itself in the user agent.
        $agent = strtolower((string) $request->userAgent());

        return match (true) {
            str_contains($agent, 'okhttp') || str_contains($agent, 'android') => 'android',
            str_contains($agent, 'cfnetwork') || str_contains($agent, 'darwin') || str_contains($agent, 'iphone') => 'ios',
            default => null,
        };
    }
}
