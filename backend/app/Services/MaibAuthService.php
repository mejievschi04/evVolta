<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MaibAuthService
{
    private const ACCESS_CACHE_KEY = 'maib.checkout.access_token';

    public function isConfigured(): bool
    {
        return filled(config('services.maib.client_id'))
            && filled(config('services.maib.client_secret'));
    }

    public function accessToken(): string
    {
        $cached = Cache::get(self::ACCESS_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->storeTokenResponse($this->requestToken());
    }

    public function clearCachedTokens(): void
    {
        Cache::forget(self::ACCESS_CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestToken(): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Plata MAIB nu este configurata. Contacteaza administratorul.', 422);
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->baseUrl().'/v2/auth/token', [
                'clientId' => (string) config('services.maib.client_id'),
                'clientSecret' => (string) config('services.maib.client_secret'),
            ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['ok'] ?? false) !== true) {
            $message = data_get($body, 'errors.0.errorMessage')
                ?: 'Nu am putut genera token-ul MAIB Checkout.';

            throw new RuntimeException($message, 422);
        }

        $result = $body['result'] ?? null;
        if (! is_array($result) || empty($result['accessToken'])) {
            throw new RuntimeException('Raspuns invalid la generarea token-ului MAIB Checkout.', 422);
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

        return $accessToken;
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('services.maib.base_url', 'https://api.maibmerchants.md'), '/');

        // Legacy env may still include /v1 — Checkout paths are rooted at host.
        return preg_replace('#/v1$#', '', $base) ?: 'https://api.maibmerchants.md';
    }
}
