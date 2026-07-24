<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\LegalAcceptanceService;
use App\Services\UserDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use RuntimeException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly LegalAcceptanceService $legalAcceptanceService,
        private readonly UserDeletionService $userDeletionService,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
            'accept_terms' => 'required|accepted',
        ]);

        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);
        $fallbackName = trim((string) ($data['name'] ?? ''));

        $user = User::query()->create([
            'name' => $fullName !== '' ? $fullName : ($fallbackName !== '' ? $fallbackName : $data['email']),
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'email' => strtolower(trim($data['email'])),
            'password' => $data['password'],
            'currency' => 'MDL',
        ]);

        $user->forceFill([
            'is_admin' => false,
            'account_type' => User::ACCOUNT_TYPE_CUSTOMER,
            'wallet_balance' => 0,
        ])->save();

        $this->legalAcceptanceService->recordAcceptance($user);

        $token = Auth::guard('api')->login($user);

        $this->auditLogService->record(
            action: 'auth.register',
            actor: $user,
            subjectType: User::class,
            subjectId: $user->id,
            metadata: [
                'ip' => $request->ip(),
                'legal_version' => $this->legalAcceptanceService->currentVersion(),
            ],
        );

        return response()->json([
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
            'accept_terms' => 'required|accepted',
        ]);

        $loginCredentials = [
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ];

        if (! $token = Auth::guard('api')->attempt($loginCredentials)) {
            $this->auditLogService->record(
                action: 'auth.login_failed',
                subjectType: User::class,
                metadata: [
                    'email' => strtolower(trim($loginCredentials['email'])),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );

            return response()->json([
                'message' => 'Credențiale invalide.',
            ], 401);
        }

        /** @var \App\Models\User $user */
        $user = Auth::guard('api')->user();

        if ($user->isAdmin()) {
            Auth::guard('api')->logout();

            $this->auditLogService->record(
                action: 'auth.login_blocked_admin',
                actor: $user,
                subjectType: User::class,
                subjectId: $user->id,
                metadata: [
                    'ip' => $request->ip(),
                ],
            );

            return response()->json([
                'message' => 'Contul de administrator se foloseste doar in backoffice.',
            ], 403);
        }

        if (! $this->legalAcceptanceService->hasCurrentAcceptance($user)) {
            $this->legalAcceptanceService->recordAcceptance($user);
        }

        $this->auditLogService->record(
            action: 'auth.login',
            actor: $user,
            subjectType: User::class,
            subjectId: $user->id,
            metadata: [
                'ip' => $request->ip(),
            ],
        );

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => $user,
        ]);
    }

    public function refresh(): JsonResponse
    {
        $token = Auth::guard('api')->refresh();

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
        ]);
    }

    public function logout(): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::guard('api')->user();

        Auth::guard('api')->logout();

        if ($user) {
            $this->auditLogService->record(
                action: 'auth.logout',
                actor: $user,
                subjectType: User::class,
                subjectId: $user->id,
            );
        }

        return response()->json([
            'message' => 'Delogat.',
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
        $previousEmail = $user->email;

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

        $metadata = [
            'email_changed' => $previousEmail !== $user->email,
        ];

        if ($metadata['email_changed']) {
            $metadata['previous_email'] = $previousEmail;
            $metadata['new_email'] = $user->email;
        }

        $this->auditLogService->record(
            action: 'auth.profile_updated',
            actor: $user,
            subjectType: User::class,
            subjectId: $user->id,
            metadata: $metadata,
        );

        return response()->json([
            'user' => $user->fresh(),
        ]);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::guard('api')->user();

        if ($user->isAdmin()) {
            Auth::guard('api')->logout();

            return response()->json([
                'message' => 'Contul de administrator se foloseste doar in backoffice.',
            ], 403);
        }

        $data = $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Parola este incorecta.',
            ], 422);
        }

        try {
            $this->userDeletionService->delete($user, $user, 'auth.account_deleted');
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getCode() >= 400 ? $exception->getCode() : 422);
        }

        try {
            Auth::guard('api')->logout();
        } catch (\Throwable) {
            // Token may already be invalid after account deletion.
        }

        return response()->json([
            'message' => 'Contul a fost sters.',
        ]);
    }
}
