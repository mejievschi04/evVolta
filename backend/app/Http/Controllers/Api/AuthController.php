<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\LegalAcceptanceService;
use App\Services\UserDeletionService;
use App\Services\UserPrivacyExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly LegalAcceptanceService $legalAcceptanceService,
        private readonly UserDeletionService $userDeletionService,
        private readonly UserPrivacyExportService $userPrivacyExportService,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:40',
            'password' => ['required', 'string', Password::defaults()],
            'accept_terms' => 'required|accepted',
        ]);

        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $fullName = trim($firstName.' '.$lastName);
        $fallbackName = trim((string) ($data['name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));

        $user = User::query()->create([
            'name' => $fullName !== '' ? $fullName : ($fallbackName !== '' ? $fallbackName : $data['email']),
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'email' => strtolower(trim($data['email'])),
            'phone' => $phone !== '' ? $phone : null,
            'password' => $data['password'],
            'currency' => 'MDL',
        ]);

        $user->forceFill([
            'is_admin' => false,
            'account_type' => User::ACCOUNT_TYPE_CUSTOMER,
            'wallet_balance' => 0,
        ])->save();

        $this->legalAcceptanceService->recordAcceptance($user, $request, 'register');

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
            'legal' => $this->legalAcceptanceService->statusForUser($user->fresh(), $request),
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

        if ($user->isAnonymized()) {
            Auth::guard('api')->logout();

            return response()->json([
                'message' => 'Contul a fost sters.',
            ], 403);
        }

        if (! $this->legalAcceptanceService->hasCurrentAcceptance($user)) {
            $this->legalAcceptanceService->recordAcceptance($user, $request, 'login');
            $user = $user->fresh();
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
            'legal' => $this->legalAcceptanceService->statusForUser($user, $request),
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

    public function me(Request $request): JsonResponse
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
            'legal' => $this->legalAcceptanceService->statusForUser($user, $request),
        ]);
    }

    public function acceptLegal(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::guard('api')->user();

        $request->validate([
            'accept_terms' => 'required|accepted',
        ]);

        $this->legalAcceptanceService->recordAcceptance($user, $request, 'in_app');

        $this->auditLogService->record(
            action: 'privacy.legal_accepted',
            actor: $user,
            subjectType: User::class,
            subjectId: $user->id,
            metadata: [
                'legal_version' => $this->legalAcceptanceService->currentVersion(),
                'ip' => $request->ip(),
            ],
        );

        $user = $user->fresh();

        return response()->json([
            'message' => 'Acceptarea legala a fost inregistrata.',
            'user' => $user,
            'legal' => $this->legalAcceptanceService->statusForUser($user, $request),
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
            'phone' => 'nullable|string|max:40',
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $firstName = array_key_exists('first_name', $data) ? trim((string) $data['first_name']) : (string) $user->first_name;
        $lastName = array_key_exists('last_name', $data) ? trim((string) $data['last_name']) : (string) $user->last_name;
        $fullName = trim($firstName.' '.$lastName);
        $fallbackName = array_key_exists('name', $data) ? trim((string) $data['name']) : (string) $user->name;
        $phone = array_key_exists('phone', $data) ? trim((string) ($data['phone'] ?? '')) : (string) ($user->phone ?? '');

        $payload = [
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'name' => $fullName !== '' ? $fullName : ($fallbackName !== '' ? $fallbackName : $user->name),
            'phone' => $phone !== '' ? $phone : null,
            'currency' => 'MDL',
        ];

        if (array_key_exists('email', $data)) {
            $payload['email'] = strtolower((string) $data['email']);
        }

        $user->forceFill($payload)->save();

        $metadata = [
            'email_changed' => $previousEmail !== $user->email,
            'phone_updated' => array_key_exists('phone', $data),
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

    public function exportPersonalData(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::guard('api')->user();

        try {
            $payload = $this->userPrivacyExportService->build($user, $user);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getCode() >= 400 ? $exception->getCode() : 422);
        }

        return response()->json(
            $payload,
            200,
            [
                'Content-Disposition' => 'attachment; filename="v-charge-privacy-export-'.$user->id.'.json"',
            ]
        );
    }
}
