<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

class SocialAuthService
{
    /**
     * @return array{provider_user_id: string, email: ?string, email_verified: bool, name: ?string, first_name: ?string, last_name: ?string}
     */
    public function verifyGoogleIdToken(string $idToken): array
    {
        $clientIds = $this->googleClientIds();
        if ($clientIds === []) {
            throw new RuntimeException('Autentificarea Google nu este configurata pe server.', 503);
        }

        $payload = $this->decodeOidcToken(
            $idToken,
            'https://www.googleapis.com/oauth2/v3/certs',
            ['https://accounts.google.com', 'accounts.google.com'],
            $clientIds,
        );

        $sub = trim((string) ($payload->sub ?? ''));
        if ($sub === '') {
            throw new RuntimeException('Token Google invalid.', 401);
        }

        $email = isset($payload->email) ? strtolower(trim((string) $payload->email)) : null;
        $emailVerified = filter_var($payload->email_verified ?? false, FILTER_VALIDATE_BOOL);

        return [
            'provider_user_id' => $sub,
            'email' => $email !== '' ? $email : null,
            'email_verified' => $emailVerified,
            'name' => isset($payload->name) ? trim((string) $payload->name) : null,
            'first_name' => isset($payload->given_name) ? trim((string) $payload->given_name) : null,
            'last_name' => isset($payload->family_name) ? trim((string) $payload->family_name) : null,
        ];
    }

    /**
     * @return array{provider_user_id: string, email: ?string, email_verified: bool, name: ?string, first_name: ?string, last_name: ?string}
     */
    public function verifyAppleIdentityToken(string $identityToken, ?string $fullName = null): array
    {
        $audiences = $this->appleAudiences();
        if ($audiences === []) {
            throw new RuntimeException('Autentificarea Apple nu este configurata pe server.', 503);
        }

        $payload = $this->decodeOidcToken(
            $identityToken,
            'https://appleid.apple.com/auth/keys',
            ['https://appleid.apple.com'],
            $audiences,
        );

        $sub = trim((string) ($payload->sub ?? ''));
        if ($sub === '') {
            throw new RuntimeException('Token Apple invalid.', 401);
        }

        $email = isset($payload->email) ? strtolower(trim((string) $payload->email)) : null;
        $emailVerified = ! isset($payload->email_verified)
            || filter_var($payload->email_verified, FILTER_VALIDATE_BOOL)
            || $payload->email_verified === 'true';

        $firstName = null;
        $lastName = null;
        $name = null;
        if (is_string($fullName) && trim($fullName) !== '') {
            $name = trim($fullName);
            $parts = preg_split('/\s+/', $name, 2) ?: [];
            $firstName = $parts[0] ?? null;
            $lastName = $parts[1] ?? null;
        }

        return [
            'provider_user_id' => $sub,
            'email' => $email !== '' ? $email : null,
            'email_verified' => $emailVerified,
            'name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
    }

    /**
     * @param  array{provider_user_id: string, email: ?string, email_verified: bool, name: ?string, first_name: ?string, last_name: ?string}  $identity
     */
    public function findOrCreateUser(string $provider, array $identity): User
    {
        $providerColumn = match ($provider) {
            'google' => 'google_id',
            'apple' => 'apple_id',
            default => throw new RuntimeException('Provider necunoscut.', 422),
        };

        $providerUserId = $identity['provider_user_id'];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $user = User::query()->where($providerColumn, $providerUserId)->first();
            if ($user) {
                return $user;
            }

            $email = $identity['email'];
            if ($email) {
                $user = User::query()->where('email', $email)->first();
                if ($user) {
                    if ($user->isAdmin()) {
                        throw new RuntimeException('Contul de administrator se foloseste doar in backoffice.', 403);
                    }

                    if ($user->isAnonymized()) {
                        throw new RuntimeException('Contul a fost sters.', 403);
                    }

                    if (! ($identity['email_verified'] ?? false)) {
                        throw new RuntimeException(
                            'Email-ul din provider nu este verificat. Autentifica-te cu parola sau foloseste un email verificat.',
                            403
                        );
                    }

                    try {
                        $user->forceFill([
                            $providerColumn => $providerUserId,
                            'email_verified_at' => $user->email_verified_at ?? now(),
                        ])->save();

                        return $user->fresh();
                    } catch (\Illuminate\Database\QueryException $exception) {
                        if (! $this->isUniqueConstraintViolation($exception) || $attempt >= 3) {
                            throw $exception;
                        }

                        continue;
                    }
                }
            }

            if (! $email) {
                throw new RuntimeException(
                    'Providerul nu a furnizat un email. Partajeaza email-ul la autentificare sau foloseste un cont existent.',
                    422
                );
            }

            $firstName = $identity['first_name'] ?: null;
            $lastName = $identity['last_name'] ?: null;
            $fullName = trim((string) ($identity['name'] ?? ''));
            if ($fullName === '') {
                $fullName = trim(implode(' ', array_filter([$firstName, $lastName])));
            }
            if ($fullName === '') {
                $fullName = strstr($email, '@', true) ?: 'Utilizator V CHARGE';
            }

            try {
                $user = User::query()->create([
                    'name' => $fullName,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                    'currency' => 'MDL',
                    $providerColumn => $providerUserId,
                ]);

                $user->forceFill([
                    'is_admin' => false,
                    'account_type' => User::ACCOUNT_TYPE_CUSTOMER,
                    'wallet_balance' => 0,
                    'email_verified_at' => ($identity['email_verified'] ?? false) ? now() : null,
                ])->save();

                return $user->fresh();
            } catch (\Illuminate\Database\QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception) || $attempt >= 3) {
                    throw $exception;
                }
            }
        }

        $existing = User::query()->where($providerColumn, $providerUserId)->first()
            ?? ($identity['email'] ? User::query()->where('email', $identity['email'])->first() : null);

        if ($existing) {
            return $existing;
        }

        throw new RuntimeException('Nu s-a putut crea contul social. Incearca din nou.', 409);
    }

    private function isUniqueConstraintViolation(\Illuminate\Database\QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '23000'
            || $driverCode === 1062
            || $driverCode === 19
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }

    /**
     * @param  list<string>  $issuers
     * @param  list<string>  $audiences
     */
    private function decodeOidcToken(string $token, string $jwksUrl, array $issuers, array $audiences): stdClass
    {
        try {
            $keys = Cache::remember('oidc_jwks:'.md5($jwksUrl), now()->addHours(6), function () use ($jwksUrl) {
                $response = Http::timeout(10)->acceptJson()->get($jwksUrl);
                $response->throw();

                return $response->json();
            });

            if (! is_array($keys) || ! isset($keys['keys'])) {
                throw new RuntimeException('Cheile OIDC nu au putut fi incarcate.', 503);
            }

            JWT::$leeway = 60;
            $payload = JWT::decode($token, JWK::parseKeySet($keys));
        } catch (RequestException $exception) {
            throw new RuntimeException('Verificarea tokenului a esuat (JWKS indisponibil).', 503, $exception);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Token de autentificare invalid sau expirat.', 401, $exception);
        }

        $issuer = (string) ($payload->iss ?? '');
        if (! in_array($issuer, $issuers, true)) {
            throw new RuntimeException('Token de autentificare invalid (issuer).', 401);
        }

        $audience = $payload->aud ?? null;
        $audienceValues = is_array($audience) ? $audience : [$audience];
        $audienceValues = array_map(static fn ($value) => (string) $value, $audienceValues);
        if (count(array_intersect($audienceValues, $audiences)) === 0) {
            throw new RuntimeException('Token de autentificare invalid (audience).', 401);
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function googleClientIds(): array
    {
        return array_values(array_unique(array_filter([
            trim((string) config('services.google.client_id')),
            trim((string) config('services.google.ios_client_id')),
            trim((string) config('services.google.android_client_id')),
            ...array_map('trim', explode(',', (string) config('services.google.client_ids', ''))),
        ])));
    }

    /**
     * @return list<string>
     */
    private function appleAudiences(): array
    {
        return array_values(array_unique(array_filter([
            trim((string) config('services.apple.client_id')),
            trim((string) config('services.apple.bundle_id')),
            ...array_map('trim', explode(',', (string) config('services.apple.audiences', ''))),
        ])));
    }
}
