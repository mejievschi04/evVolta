<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBackofficeAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = session('backoffice_user_id');
        $user = $userId ? User::query()->find($userId) : null;

        if (! $user || ! $user->isAdmin()) {
            $request->session()->forget(['backoffice_user_id', 'backoffice_user_name']);
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Autentificarea backoffice este necesara.',
                ], 401);
            }

            return redirect()->route('backoffice.login');
        }

        return $next($request);
    }
}
