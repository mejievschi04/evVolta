<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MaibAuthService
{
    private const ACCESS_CACHE_KEY = 'maib.access_token';

    private const REFRESH_CACHE_KEY = 'maib.refresh_token';

    public function isConfigured(): bool
    {
        return filled(config('services.maib.project_id'))
            && filled(config('services.maib.project_secret'));
    }

    public function accessToken(): string
    {
        $cached = Cache::get(self::ACCESS_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $refresh = Cache::get(self::REFRESH_CACHE_KEY);
        if (is_string($refresh) && $refresh !== '') {
            try {
                return $this->storeTokenResponse($this->requestToken(['refreshToken' => $refresh]));
            } catch (RuntimeException) {
                Cache::forget(self::REFRESH_CACHE_KEY);
            }
        }

        return $this->storeTokenResponse($this->requestToken([
            'projectId' => (string) config('services.maib.project_id'),
            'projectSecret' => (string) config('services.maib.project_secret'),
        ]));
    }

    public function clearCachedTokens(): void
    {
        Cache::forget(self::ACCESS_CACHE_KEY);
        Cache::forget(self::REFRESH_CACHE_KEY);
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function requestToken(array $payload): array
    {
        if (! $this->isConfigured() && ! isset($payload['refreshToken'])) {
            throw new RuntimeException('Plata MAIB nu este configurata. Contacteaza administratorul.', 422);
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->baseUrl().'/generate-token', $payload);

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['ok'] ?? false) !== true) {
            $message = data_get($body, 'errors.0.errorMessage')
                ?: 'Nu am putut genera token-ul MAIB.';

            throw new RuntimeException($message, 422);
        }

        $result = $body['result'] ?? null;
        if (! is_array($result) || empty($result['accessToken'])) {
            throw new RuntimeException('Raspuns invalid la generarea token-ului MAIB.', 422);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function storeTokenResponse(array $result): string
    {
        $accessToken = (string) $result['accessToken'];
        $expiresIn = max(30, (int) ($result['expiresIn'] ?? 300) - 30);
        Cache::put(self::ACCESS_CACHE_KEY, $accessToken, $expiresIn);

        $refreshToken = (string) ($result['refreshToken'] ?? '');
        if ($refreshToken !== '') {
            $refreshExpiresIn = max(60, (int) ($result['refreshExpiresIn'] ?? 1800) - 60);
            Cache::put(self::REFRESH_CACHE_KEY, $refreshToken, $refreshExpiresIn);
        }

        return $accessToken;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.maib.base_url', 'https://api.maibmerchants.md/v1'), '/');
    }
}
