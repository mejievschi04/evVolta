<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Services\ChargingResumeService;
use App\Services\ChargingStopService;
use App\Services\SessionPresentationService;
use App\Services\UsageStatisticsService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        private readonly ChargingStopService $chargingStopService,
        private readonly WalletService $walletService,
        private readonly SessionPresentationService $sessionPresentationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        // Optional: newer apps ask for the latest N and take totals from /sessions/summary.
        // Without it the full history is returned, as older app versions compute totals from it.
        $paging = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:500',
            'offset' => 'sometimes|integer|min:0',
        ]);
        $limit = $paging['limit'] ?? null;
        $offset = $limit ? (int) ($paging['offset'] ?? 0) : 0;

        $this->reconcileOpenSessions($request);

        // Plasa de siguranta pentru facturi lipsa doar pe sesiunile recente: decontarea
        // ruleaza deja in finalizeStop, iar re-decontarea intregului istoric la fiecare
        // apel facea ~16 interogari/sesiune (cu lock-uri) si depasea timeout-ul aplicatiei.
        $ensureInvoiceSince = now()->subDays(7);

        $sessions = $this->sessionPresentationService->presentManyForUser(
            ChargingSession::query()
                ->with(['station', 'invoice', 'user'])
                ->where('user_id', $request->user()->id)
                ->latest('start_time')
                ->orderByDesc('id')
                ->when($limit, fn ($query) => $query->limit((int) $limit)->offset($offset))
                ->get(),
            fn (ChargingSession $session): bool => ! $session->invoice
                && (bool) $session->end_time?->greaterThan($ensureInvoiceSince),
        );

        return response()->json($sessions);
    }

    public function summary(Request $request, UsageStatisticsService $usageStatisticsService): JsonResponse
    {
        return response()->json($usageStatisticsService->historySummary($request->user()));
    }

    /**
     * Doar sesiunile deschise ale clientului (acelasi format ca index), pentru
     * reimprospatarea frecventa din aplicatie fara a incarca istoricul.
     */
    public function active(Request $request): JsonResponse
    {
        $this->reconcileOpenSessions($request);

        $sessions = $this->sessionPresentationService->presentManyForUser(
            ChargingSession::query()
                ->with(['station', 'invoice', 'user'])
                ->where('user_id', $request->user()->id)
                ->whereNull('end_time')
                ->latest('start_time')
                ->get(),
            fn (): bool => false,
        );

        return response()->json($sessions);
    }

    /**
     * Inchide sesiunile abandonate / eliberate inainte de a raspunde, ca aplicatia
     * sa nu vada o incarcare care s-a terminat deja pe statie.
     */
    private function reconcileOpenSessions(Request $request): void
    {
        $this->chargingStopService->reconcileAllOpenSessionsForUser($request->user());

        $openSessions = ChargingSession::query()
            ->with('station')
            ->where('user_id', $request->user()->id)
            ->whereNull('end_time')
            ->get();

        foreach ($openSessions as $session) {
            if (! $session->station) {
                continue;
            }

            $station = $session->station->fresh();
            $connectorStatus = $station->connectorOcppStatus((int) ($session->ocpp_connector_id ?: 1));

            if ($this->chargingStopService->shouldAutoFinalizeOnConnectorRelease($session, $station, $connectorStatus)) {
                $this->chargingStopService->finalizeStop($session, $station, 'ocpp');
            }
        }
    }

    public function live(Request $request, ChargingSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        return response()->json($this->buildLivePayload($session));
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
                    'can_resume' => false,
                    'connector_status' => $station?->connectorOcppStatus($connectorId),
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
            'can_resume' => $station
                && ! $session->end_time
                && app(ChargingResumeService::class)->connectorCanResume($station, $connectorId),
            ...($session->end_time ? [
                'session_completed' => [
                    'session' => $session,
                    'invoice' => $session->invoice()->first(),
                ],
            ] : []),
            'connector_status' => $station?->connectorOcppStatus($connectorId),
        ];
    }

    private function authorizeSession(Request $request, ChargingSession $session): void
    {
        if ((int) $session->user_id !== (int) $request->user()->id) {
            abort(403, 'Nu ai acces la aceasta sesiune.');
        }
    }
}
