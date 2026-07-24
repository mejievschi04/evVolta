<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletTopup;
use Illuminate\Http\Request;
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
            'language' => $language,
            'callbackUrl' => url('/api/maib/callback'),
            'successUrl' => route('payments.maib.success', ['wallet_topup_id' => $topup->id]),
            'failUrl' => route('payments.maib.fail', ['wallet_topup_id' => $topup->id]),
            'orderInfo' => [
                'id' => $orderId,
                'description' => 'Alimentare cont Volta EV',
                'date' => now()->toIso8601String(),
            ],
            'payerInfo' => [
                'ip' => $this->normalizeClientIp($clientIp),
            ],
        ];

        if ($user->name) {
            $payload['payerInfo']['name'] = mb_substr((string) $user->name, 0, 128);
        }
        if ($user->email) {
            $payload['payerInfo']['email'] = mb_substr((string) $user->email, 0, 40);
        }
        if ($user->phone) {
            $payload['payerInfo']['phone'] = mb_substr((string) $user->phone, 0, 40);
        }

        $body = $this->authorizedJson('POST', '/v2/checkouts', $payload);
        $result = $body['result'] ?? null;

        if (! is_array($result) || empty($result['checkoutId']) || empty($result['checkoutUrl'])) {
            throw new RuntimeException('Raspuns invalid de la MAIB la crearea checkout-ului.', 422);
        }

        return [
            'id' => (string) $result['checkoutId'],
            'url' => (string) $result['checkoutUrl'],
            'order_id' => $orderId,
        ];
    }

    /**
     * Checkout session details (GET /v2/checkouts/{id}).
     *
     * @return array<string, mixed>
     */
    public function getPaymentInfo(string $checkoutId): array
    {
        $body = $this->authorizedJson('GET', '/v2/checkouts/'.rawurlencode($checkoutId));
        $result = $body['result'] ?? null;

        if (! is_array($result)) {
            throw new RuntimeException('Nu am putut citi starea checkout-ului MAIB.', 422);
        }

        return $result;
    }

    public function isCheckoutPaid(array $checkout): bool
    {
        $status = strtolower((string) ($checkout['status'] ?? ''));
        if ($status !== 'completed') {
            return false;
        }

        $payment = $checkout['payment'] ?? null;
        if (! is_array($payment)) {
            return true;
        }

        $paymentStatus = strtolower((string) ($payment['status'] ?? $payment['paymentStatus'] ?? ''));

        return $paymentStatus === '' || $paymentStatus === 'executed';
    }

    public function extractPaymentId(array $checkout): ?string
    {
        $payment = $checkout['payment'] ?? null;
        if (! is_array($payment)) {
            return null;
        }

        foreach (['paymentId', 'PaymentId', 'id'] as $key) {
            $value = $payment[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function extractRrn(array $checkout): ?string
    {
        $payment = $checkout['payment'] ?? null;
        if (! is_array($payment)) {
            return null;
        }

        foreach (['referenceNumber', 'retrievalReferenceNumber', 'rrn'] as $key) {
            $value = $payment[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{id: string, status: string, refund_amount: float}
     */
    public function refund(string $checkoutOrPaymentId, float $amount, ?string $knownPaymentId = null): array
    {
        $payId = $this->resolvePaymentIdForRefund($checkoutOrPaymentId, $knownPaymentId);

        $body = $this->authorizedJson('POST', '/v2/payments/'.rawurlencode($payId).'/refund', [
            'amount' => round($amount, 2),
            'reason' => 'Returnare alimentare wallet Volta EV',
        ]);

        $result = $body['result'] ?? null;
        if (! is_array($result)) {
            throw new RuntimeException('Raspuns invalid de la MAIB la returnare.', 422);
        }

        $status = (string) ($result['status'] ?? '');
        if (! in_array($status, ['Created', 'OK', 'REVERSED', 'Requested', 'Accepted'], true)) {
            $message = (string) ($result['statusMessage'] ?? 'Returnarea MAIB a esuat.');
            throw new RuntimeException($message, 422);
        }

        return [
            'id' => (string) ($result['refundId'] ?? $result['id'] ?? $payId),
            'status' => $status,
            'refund_amount' => round($amount, 2),
        ];
    }

    /**
     * Resolve MAIB paymentId for refunds.
     *
     * Preferred order:
     * 1) known paymentId (stored on topup.payment_intent_id)
     * 2) lookup via checkoutId (topup.payment_session_id)
     * 3) treat the stored session id as paymentId (legacy rows that overwrote checkoutId)
     */
    public function resolvePaymentIdForRefund(string $checkoutOrPaymentId, ?string $knownPaymentId = null): string
    {
        $knownPaymentId = is_string($knownPaymentId) ? trim($knownPaymentId) : '';
        if ($knownPaymentId !== '' && $this->looksLikeMaibUuid($knownPaymentId)) {
            return $knownPaymentId;
        }

        $checkoutOrPaymentId = trim($checkoutOrPaymentId);
        if ($checkoutOrPaymentId === '') {
            throw new RuntimeException('Lipseste identificatorul platii MAIB pentru returnare.', 422);
        }

        if ($this->looksLikeMaibUuid($checkoutOrPaymentId)) {
            try {
                $checkout = $this->getPaymentInfo($checkoutOrPaymentId);
                $fromCheckout = $this->extractPaymentId($checkout);
                if (is_string($fromCheckout) && $fromCheckout !== '') {
                    return $fromCheckout;
                }
            } catch (RuntimeException) {
                // Legacy topups may already store paymentId in payment_session_id.
            }
        }

        return $checkoutOrPaymentId;
    }

    public function looksLikeMaibUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            trim($value)
        );
    }

    public function verifyCallbackRequest(Request $request): bool
    {
        $key = (string) config('services.maib.signature_key', '');
        $headerSignature = (string) ($request->header('X-Signature') ?: $request->header('x-signature') ?: '');
        $timestamp = (string) ($request->header('X-Signature-Timestamp') ?: $request->header('x-signature-timestamp') ?: '');
        $rawBody = $request->getContent();

        if ($key === '' || $headerSignature === '' || $timestamp === '' || $rawBody === '') {
            return false;
        }

        // Replay window: 10 minutes
        $tsMs = (int) $timestamp;
        if ($tsMs > 0) {
            $skew = abs((int) (microtime(true) * 1000) - $tsMs);
            if ($skew > 10 * 60 * 1000) {
                return false;
            }
        }

        $message = $rawBody.'.'.$timestamp;
        $binary = hash_hmac('sha256', $message, $key, true);
        $expectedBase64 = base64_encode($binary);
        $expectedHex = hash_hmac('sha256', $message, $key);

        $provided = $headerSignature;
        if (str_starts_with(strtolower($provided), 'sha256=')) {
            $provided = substr($provided, 7);
        }

        return hash_equals($expectedBase64, $provided)
            || hash_equals($expectedHex, strtolower($provided))
            || hash_equals($expectedHex, $provided);
    }

    /**
     * Build Checkout callback HMAC for tests.
     */
    public function buildCallbackSignature(string $rawBody, string $timestamp, ?string $signatureKey = null): string
    {
        $key = $signatureKey ?? (string) config('services.maib.signature_key', '');

        return base64_encode(hash_hmac('sha256', $rawBody.'.'.$timestamp, $key, true));
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
        $base = rtrim((string) config('services.maib.base_url', 'https://api.maibmerchants.md'), '/');

        return preg_replace('#/v1$#', '', $base) ?: 'https://api.maibmerchants.md';
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

        return mb_substr($ip, 0, 45);
    }
}
