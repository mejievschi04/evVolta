<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletTopup;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MaibPaymentService
{
    public function __construct(private readonly MaibAuthService $auth)
    {
    }

    public function isConfigured(): bool
    {
        return $this->auth->isConfigured()
            && filled(config('services.maib.signature_key'));
    }

    /**
     * @return array{id: string, url: string, order_id: string}
     */
    public function createWalletTopupPayment(WalletTopup $topup, User $user, string $clientIp): array
    {
        if (! $this->auth->isConfigured()) {
            throw new RuntimeException('Plata MAIB nu este configurata. Contacteaza administratorul.', 422);
        }

        $currency = strtoupper($topup->currency ?: ($user->currency ?: 'MDL'));
        $orderId = 'wallet-topup-'.$topup->id;
        $language = (string) config('services.maib.language', 'ro');
        if (! in_array($language, ['ro', 'en', 'ru'], true)) {
            $language = 'ro';
        }

        $payload = [
            'amount' => round((float) $topup->amount, 2),
            'currency' => $currency,
            'clientIp' => $this->normalizeClientIp($clientIp),
            'language' => $language,
            'description' => 'Alimentare cont Volta EV',
            'orderId' => $orderId,
            'callbackUrl' => url('/api/maib/callback'),
            'okUrl' => route('payments.maib.success', ['wallet_topup_id' => $topup->id]),
            'failUrl' => route('payments.maib.fail', ['wallet_topup_id' => $topup->id]),
        ];

        if ($user->name) {
            $payload['clientName'] = mb_substr((string) $user->name, 0, 128);
        }
        if ($user->email) {
            $payload['email'] = mb_substr((string) $user->email, 0, 40);
        }
        if ($user->phone) {
            $payload['phone'] = mb_substr((string) $user->phone, 0, 40);
        }

        $body = $this->authorizedJson('POST', '/pay', $payload);
        $result = $body['result'] ?? null;

        if (! is_array($result) || empty($result['payId']) || empty($result['payUrl'])) {
            throw new RuntimeException('Raspuns invalid de la MAIB la crearea platii.', 422);
        }

        return [
            'id' => (string) $result['payId'],
            'url' => (string) $result['payUrl'],
            'order_id' => (string) ($result['orderId'] ?? $orderId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentInfo(string $payId): array
    {
        $body = $this->authorizedJson('GET', '/pay-info/'.rawurlencode($payId));
        $result = $body['result'] ?? null;

        if (! is_array($result)) {
            throw new RuntimeException('Nu am putut citi starea platii MAIB.', 422);
        }

        return $result;
    }

    /**
     * @return array{id: string, status: string, refund_amount: float}
     */
    public function refund(string $payId, float $amount): array
    {
        $body = $this->authorizedJson('POST', '/refund', [
            'payId' => $payId,
            'refundAmount' => round($amount, 2),
        ]);

        $result = $body['result'] ?? null;
        if (! is_array($result)) {
            throw new RuntimeException('Raspuns invalid de la MAIB la returnare.', 422);
        }

        $status = (string) ($result['status'] ?? '');
        if (! in_array($status, ['OK', 'REVERSED'], true)) {
            $message = (string) ($result['statusMessage'] ?? 'Returnarea MAIB a esuat.');
            throw new RuntimeException($message, 422);
        }

        return [
            'id' => (string) ($result['payId'] ?? $payId),
            'status' => $status,
            'refund_amount' => (float) ($result['refundAmount'] ?? $amount),
        ];
    }

    public function verifyCallbackSignature(array $result, string $signature): bool
    {
        $key = (string) config('services.maib.signature_key', '');
        if ($key === '' || $signature === '') {
            return false;
        }

        return hash_equals($this->buildSignature($result, $key), $signature);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function buildSignature(array $result, ?string $signatureKey = null): string
    {
        $key = $signatureKey ?? (string) config('services.maib.signature_key', '');
        $sorted = $this->sortByKeyRecursive($result);
        $values = $this->flattenValues($sorted);
        $values[] = $key;
        $signString = implode(':', $values);

        return base64_encode(hash('sha256', $signString, true));
    }

    public function resolveTopupFromOrderId(?string $orderId): ?WalletTopup
    {
        if (! is_string($orderId) || $orderId === '') {
            return null;
        }

        if (preg_match('/^wallet-topup-(\d+)$/', $orderId, $matches) !== 1) {
            return null;
        }

        return WalletTopup::query()->find((int) $matches[1]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function authorizedJson(string $method, string $path, array $payload = []): array
    {
        $url = $this->baseUrl().$path;

        try {
            return $this->doAuthorizedRequest($method, $url, $payload, false);
        } catch (RuntimeException $exception) {
            if ((int) $exception->getCode() !== 401) {
                throw $exception;
            }

            $this->auth->clearCachedTokens();

            return $this->doAuthorizedRequest($method, $url, $payload, true);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function doAuthorizedRequest(string $method, string $url, array $payload, bool $retried): array
    {
        $token = $this->auth->accessToken();
        $request = Http::acceptJson()
            ->withToken($token)
            ->timeout(25);

        if (strtoupper($method) === 'GET') {
            $response = $request->get($url);
        } else {
            $response = $request->asJson()->send($method, $url, ['json' => $payload]);
        }

        $body = $response->json() ?? [];

        if ($response->status() === 401 && ! $retried) {
            throw new RuntimeException('Autentificare MAIB esuata.', 401);
        }

        if (! $response->successful() || ($body['ok'] ?? false) !== true) {
            $message = data_get($body, 'errors.0.errorMessage')
                ?: 'Cererea catre MAIB a esuat.';

            throw new RuntimeException($message, 422);
        }

        return $body;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.maib.base_url', 'https://api.maibmerchants.md/v1'), '/');
    }

    private function normalizeClientIp(string $clientIp): string
    {
        $ip = trim($clientIp);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return '127.0.0.1';
        }

        if ($ip === '::1') {
            return '127.0.0.1';
        }

        return mb_substr($ip, 0, 15);
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function sortByKeyRecursive(array $array): array
    {
        ksort($array, SORT_STRING);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->sortByKeyRecursive($value);
            }
        }

        return $array;
    }

    /**
     * @param  array<mixed>  $array
     * @return list<string>
     */
    private function flattenValues(array $array): array
    {
        $values = [];

        foreach ($array as $item) {
            if (is_array($item)) {
                foreach ($this->flattenValues($item) as $nested) {
                    $values[] = $nested;
                }
            } else {
                $values[] = (string) $item;
            }
        }

        return $values;
    }
}
