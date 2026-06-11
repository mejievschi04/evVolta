<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Services\ChargingStopService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SessionController extends Controller
{
    public function __construct(
        private readonly ChargingStopService $chargingStopService,
        private readonly WalletService $walletService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $sessions = ChargingSession::query()
            ->with('station')
            ->where('user_id', $request->user()->id)
            ->latest('start_time')
            ->get();

        foreach ($sessions as $session) {
            if ($session->end_time || ! $session->station) {
                continue;
            }

            $station = $session->station->fresh();
            $connectorStatus = $station->connectorOcppStatus((int) ($session->ocpp_connector_id ?: 1));

            if ($this->chargingStopService->shouldAutoFinalizeOnConnectorRelease($session, $station, $connectorStatus)) {
                $this->chargingStopService->finalizeStop($session, $station, 'ocpp');
            }
        }

        $sessions = ChargingSession::query()
            ->with('station')
            ->where('user_id', $request->user()->id)
            ->latest('start_time')
            ->get()
            ->map(function (ChargingSession $session) {
                if ($session->station) {
                    $session->station->setAttribute('live_status', $session->station->liveStatus());
                }

                return $session;
            });

        return response()->json($sessions);
    }

    public function live(Request $request, ChargingSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        return response()->json($this->buildLivePayload($session));
    }

    public function stream(Request $request, ChargingSession $session): StreamedResponse
    {
        $this->authorizeSession($request, $session);

        return response()->stream(function () use ($session): void {
            $startedAt = time();
            $lastVersion = null;

            while (! connection_aborted() && (time() - $startedAt) < 120) {
                $session = $session->fresh(['station']);
                $payload = $this->buildLivePayload($session);
                $version = $payload['stream_version'];

                if ($version !== $lastVersion) {
                    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    $lastVersion = $version;
                } else {
                    echo ": keepalive\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }

                usleep(500000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLivePayload(ChargingSession $session): array
    {
        $session = $session->fresh(['station']);
        $station = $session->station?->fresh();
        $connectorId = (int) ($session->ocpp_connector_id ?: 1);
        $liveStatus = $station?->liveStatus($connectorId);

        if ($station && ! $session->end_time) {
            $connectorStatus = $station->connectorOcppStatus($connectorId);

            if ($this->chargingStopService->shouldAutoFinalizeOnConnectorRelease($session, $station, $connectorStatus)) {
                $result = $this->chargingStopService->finalizeStop($session, $station, 'ocpp');
                $session = $result['session']->fresh(['station']);
                $station = $session->station?->fresh();
                $liveStatus = $station?->liveStatus($connectorId);

                return [
                    'stream_version' => $session->updated_at?->toIso8601String(),
                    'session' => $session->load('station'),
                    'live_status' => $liveStatus,
                    'session_completed' => [
                        'session' => $session,
                        'invoice' => $result['invoice'] ?? null,
                    ],
                ];
            }

            $this->walletService->maybeAutoStopForBudget($session, $station);
            $session = $session->fresh(['station']);
        }

        return [
            'stream_version' => $session->updated_at?->toIso8601String(),
            'session' => $session->load('station'),
            'live_status' => $liveStatus,
        ];
    }

    private function authorizeSession(Request $request, ChargingSession $session): void
    {
        if ((int) $session->user_id !== (int) $request->user()->id) {
            abort(403, 'Nu ai acces la aceasta sesiune.');
        }
    }
}
