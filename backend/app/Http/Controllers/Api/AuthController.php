<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'email.unique' => 'Acest e-mail este deja folosit.',
        ]);

        $user = User::query()->create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'password' => $data['password'],
            'currency' => 'MDL',
        ]);

        $user->forceFill([
            'is_admin' => false,
            'account_type' => User::ACCOUNT_TYPE_CUSTOMER,
        ])->save();

        $token = Auth::guard('api')->login($user);

        return response()->json([
            'message' => 'Contul a fost creat.',
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => $user->fresh(),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! $token = Auth::guard('api')->attempt($credentials)) {
            return response()->json([
                'message' => 'Credențiale invalide.',
            ], 401);
        }

        /** @var \App\Models\User $user */
        $user = Auth::guard('api')->user();

        if ($user->isAdmin()) {
            Auth::guard('api')->logout();

            return response()->json([
                'message' => 'Contul de administrator se foloseste doar in backoffice.',
            ], 403);
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => $user,
        ]);
    }

    public function me(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::guard('api')->user();

        if ($user->isAdmin()) {
            Auth::guard('api')->logout();

            return response()->json([
                'message' => 'Contul de administrator se foloseste doar in backoffice.',
            ], 403);
        }

        return response()->json([
            'user' => $user,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        $data = $request->validate([
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'name' => 'nullable|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);
        $fallbackName = trim((string) ($data['name'] ?? ''));

        $user->forceFill([
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'name' => $fullName !== '' ? $fullName : ($fallbackName !== '' ? $fallbackName : $user->name),
            'email' => strtolower($data['email']),
            'currency' => 'MDL',
        ])->save();

        return response()->json([
            'user' => $user->fresh(),
        ]);
    }
}
