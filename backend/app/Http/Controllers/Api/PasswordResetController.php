<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();

        // Admins use the backoffice; anonymized accounts are gone.
        if ($user && ! $user->isAdmin() && ! $user->isAnonymized()) {
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        // Same answer whether or not the account exists, so emails cannot be enumerated.
        return response()->json([
            'message' => 'Daca exista un cont cu acest email, ai primit un link pentru resetarea parolei.',
        ]);
    }

    public function showResetForm(Request $request, string $token): Response
    {
        return $this->page([
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ], [
            'password.confirmed' => 'Parolele nu coincid.',
            'password.min' => 'Parola trebuie sa aiba cel putin 8 caractere.',
            'password.letters' => 'Parola trebuie sa contina cel putin o litera.',
            'password.numbers' => 'Parola trebuie sa contina cel putin o cifra.',
        ]);

        if ($validator->fails()) {
            return $this->page([
                'token' => (string) $request->input('token', ''),
                'email' => (string) $request->input('email', ''),
                'error' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();
        $resetUser = null;

        $status = Password::broker()->reset(
            [
                'email' => strtolower(trim($data['email'])),
                'token' => $data['token'],
                'password' => $data['password'],
            ],
            function (User $user, string $password) use (&$resetUser): void {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $resetUser = $user;
            }
        );

        if ($status !== Password::PASSWORD_RESET || ! $resetUser) {
            return $this->page([
                'invalid' => true,
            ], 422);
        }

        $this->auditLogService->record(
            action: 'auth.password_reset',
            actor: $resetUser,
            subjectType: User::class,
            subjectId: $resetUser->id,
            metadata: ['ip' => $request->ip()],
        );

        return $this->page(['done' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(array $data, int $status = 200): Response
    {
        $csp = str_replace(
            "form-action 'none'",
            "form-action 'self'",
            (string) config('security.csp_document')
        );

        return response()
            ->view('auth.password-reset', $data, $status)
            ->header('Content-Security-Policy', $csp)
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store');
    }
}
