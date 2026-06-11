<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showLogin(): JsonResponse|RedirectResponse
    {
        $backofficeUiUrl = rtrim((string) config('app.backoffice_ui_url', ''), '/');

        if ($backofficeUiUrl !== '') {
            return redirect()->away($backofficeUiUrl);
        }

        if (session()->has('backoffice_user_id')) {
            return redirect()->route('backoffice.dashboard');
        }

        return response()->json([
            'message' => 'Backoffice UI runs as a separate frontend. Set BACKOFFICE_UI_URL in backend/.env (example: http://127.0.0.1:5173) and open /backoffice again.',
        ]);
    }

    public function login(Request $request): JsonResponse|RedirectResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Credentiale invalide.',
                ], 422);
            }

            return back()->withErrors(['email' => 'Credentiale invalide.'])->withInput();
        }

        if (! $user->isAdmin()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Acces backoffice permis doar conturilor de administrator.',
                ], 403);
            }

            return back()
                ->withErrors(['email' => 'Acces backoffice permis doar conturilor de administrator.'])
                ->withInput();
        }

        $request->session()->regenerate();
        $request->session()->put([
            'backoffice_user_id' => $user->id,
            'backoffice_user_name' => $user->display_name,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Autentificat.',
                'user' => $user->only(['id', 'name', 'first_name', 'last_name', 'email', 'currency']),
            ]);
        }

        return redirect()->route('backoffice.dashboard');
    }

    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        $request->session()->forget(['backoffice_user_id', 'backoffice_user_name']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Delogat.',
            ]);
        }

        return redirect()->route('backoffice.login');
    }
}
