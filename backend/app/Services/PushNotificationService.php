<?php

namespace App\Services;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    public function registerToken(User $user, string $token, ?string $platform = null): DevicePushToken
    {
        return DevicePushToken::query()->updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $user->id,
                'platform' => $platform,
                'last_seen_at' => now(),
            ]
        );
    }

    public function unregisterToken(User $user, string $token): void
    {
        DevicePushToken::query()
            ->where('user_id', $user->id)
            ->where('token', $token)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyUser(User $user, string $title, string $body, array $data = []): void
    {
        if (! config('services.expo_push.enabled', true)) {
            return;
        }

        $tokens = DevicePushToken::query()
            ->where('user_id', $user->id)
            ->pluck('token')
            ->all();

        foreach ($tokens as $token) {
            $this->sendExpoPush($token, $title, $body, $data);
        }
    }

    public function notifyChargingStarted(User $user, string $stationName): void
    {
        $this->notifyUser(
            $user,
            'Incarcare pornita',
            "Energia curge la {$stationName}.",
            ['type' => 'charging.started']
        );
    }

    public function notifyChargingStopped(User $user, string $stationName, float $kwh): void
    {
        $this->notifyUser(
            $user,
            'Incarcare finalizata',
            sprintf('%s · %.2f kWh livrate.', $stationName, $kwh),
            ['type' => 'charging.stopped']
        );
    }

    public function notifyBudgetAutoStop(User $user, string $stationName): void
    {
        $this->notifyUser(
            $user,
            'Buget atins',
            "Incarcarea s-a oprit automat la {$stationName}.",
            ['type' => 'charging.budget_stop']
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sendExpoPush(string $token, string $title, string $body, array $data = []): void
    {
        if (! str_starts_with($token, 'ExponentPushToken[')) {
            return;
        }

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->post('https://exp.host/--/api/v2/push/send', [
                    'to' => $token,
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                    'data' => $data,
                ]);

            if (! $response->successful()) {
                Log::warning('Expo push failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Expo push exception: ' . $exception->getMessage());
        }
    }
}
