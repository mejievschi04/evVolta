<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\OcppMessage;
use App\Models\Station;
use Carbon\Carbon;

class OcppSessionDebugService
{
  private const TIMELINE_ACTIONS = [
        'StatusNotification',
        'StartTransaction',
        'StopTransaction',
        'MeterValues',
        'DataTransfer',
        'RemoteStartTransaction',
        'RemoteStopTransaction',
        'RequestStartTransaction',
        'RequestStopTransaction',
        'Reset',
        'ChangeAvailability',
        'UnlockConnector',
        'TriggerMessage',
        'BootNotification',
        'Heartbeat',
    ];

    /**
     * Snapshot captured when a session stops (persisted on the session row).
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function buildStopContext(
        Station $station,
        ChargingSession $session,
        string $trigger,
        array $extra = []
    ): array {
        $station = $station->fresh();
        $session = $session->fresh();
        $connectorId = (int) ($session->ocpp_connector_id ?: 1);
        $windowStart = ($session->start_time ?? now())->copy()->subSeconds(120);

        return array_merge([
            'captured_at' => now()->toIso8601String(),
            'trigger' => $trigger,
            'session_id' => $session->id,
            'session_connector_id' => $connectorId,
            'session_transaction_id' => $session->ocpp_transaction_id,
            'connector_states' => $this->connectorStates($station),
            'recent_timeline' => $this->timelineEntries(
                $station,
                $session,
                $windowStart,
                now()->addSeconds(5),
                limit: 40
            ),
            'recent_commands' => $this->recentCommands($station, $session, $windowStart, limit: 15),
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function debugPayload(ChargingSession $session, int $bufferSeconds = 120): array
    {
        $session->loadMissing(['station', 'user']);

        if (! $session->station) {
            return [
                'session' => $this->sessionSummary($session),
                'timeline' => [],
                'stop_context' => $session->ocpp_stop_context,
            ];
        }

        $station = $session->station->fresh();
        $from = ($session->start_time ?? now())->copy()->subSeconds($bufferSeconds);
        $to = ($session->end_time ?? now())->copy()->addSeconds($bufferSeconds);

        return [
            'session' => $this->sessionSummary($session),
            'station' => [
                'id' => $station->id,
                'name' => $station->name,
                'ocpp_identity' => $station->ocpp_identity,
                'connector_count' => $station->expectedConnectorCount(),
            ],
            'window' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'buffer_seconds' => $bufferSeconds,
            ],
            'stop_context' => is_array($session->ocpp_stop_context) ? $session->ocpp_stop_context : null,
            'connector_states_now' => $this->connectorStates($station),
            'timeline' => $this->timelineEntries($station, $session, $from, $to, limit: 200),
            'analysis' => $this->analyzeTimeline($session, $station),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timelineEntries(
        Station $station,
        ChargingSession $session,
        Carbon $from,
        Carbon $to,
        int $limit = 100
    ): array {
        $messages = OcppMessage::query()
            ->where('station_id', $station->id)
            ->whereBetween('created_at', [$from, $to])
            ->where(function ($query): void {
                foreach (self::TIMELINE_ACTIONS as $index => $action) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}(function ($inner) use ($action): void {
                        $inner->where('action', $action)
                            ->orWhere('action', 'like', $action . '%');
                    });
                }
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $commands = OcppCommand::query()
            ->where('station_id', $station->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $entries = [];

        foreach ($messages as $message) {
            $entries[] = $this->formatMessageEntry($message, $session);
        }

        foreach ($commands as $command) {
            $entries[] = $this->formatCommandEntry($command, $session);
        }

        usort($entries, fn (array $a, array $b) => strcmp($a['at'], $b['at']));

        return array_slice($entries, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentCommands(
        Station $station,
        ChargingSession $session,
        Carbon $since,
        int $limit = 15
    ): array {
        return OcppCommand::query()
            ->where('station_id', $station->id)
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (OcppCommand $command) => $this->formatCommandEntry($command, $session))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMessageEntry(OcppMessage $message, ChargingSession $session): array
    {
        $payload = is_array($message->payload) ? $message->payload : [];
        $action = $this->normalizeAction((string) $message->action);
        $connectorId = $this->extractConnectorId($payload);
        $relation = $this->entryRelation($action, $payload, $session, $connectorId);

        return [
            'kind' => 'message',
            'id' => $message->id,
            'at' => ($message->received_at ?? $message->created_at)?->toIso8601String(),
            'direction' => $message->direction,
            'action' => $action,
            'status' => $message->status,
            'connector_id' => $connectorId,
            'summary' => $this->summarizePayload($action, $payload, $message->direction),
            'relation' => $relation,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCommandEntry(OcppCommand $command, ChargingSession $session): array
    {
        $payload = is_array($command->payload) ? $command->payload : [];
        $action = (string) $command->action;
        $connectorId = $this->extractConnectorId($payload);
        $relation = $this->entryRelation($action, $payload, $session, $connectorId);

        if ((int) $command->charging_session_id === (int) $session->id) {
            $relation = 'session_command';
        }

        return [
            'kind' => 'command',
            'id' => $command->id,
            'at' => ($command->sent_at ?? $command->created_at)?->toIso8601String(),
            'direction' => 'outbound',
            'action' => $action,
            'status' => $command->status,
            'connector_id' => $connectorId,
            'summary' => $this->summarizeCommand($command),
            'relation' => $relation,
            'payload' => $payload,
            'response_payload' => is_array($command->response_payload) ? $command->response_payload : null,
            'error_message' => $command->error_message,
        ];
    }

  private function normalizeAction(string $action): string
    {
        return match (true) {
            str_ends_with($action, 'Response') => substr($action, 0, -8),
            str_ends_with($action, 'Result') => substr($action, 0, -6),
            str_ends_with($action, 'Error') => substr($action, 0, -5),
            default => $action,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractConnectorId(array $payload): ?int
    {
        $connectorId = (int) ($payload['connectorId'] ?? 0);

        return $connectorId > 0 ? $connectorId : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summarizePayload(string $action, array $payload, string $direction): string
    {
        $connectorId = $this->extractConnectorId($payload);

        return match ($action) {
            'StatusNotification' => sprintf(
                'Status C%d → %s%s',
                $connectorId ?? 0,
                (string) ($payload['status'] ?? '—'),
                isset($payload['errorCode']) && $payload['errorCode'] !== 'NoError'
                    ? ' · ' . $payload['errorCode']
                    : ''
            ),
            'StopTransaction' => sprintf(
                'Stop tx=%s reason=%s%s',
                (string) ($payload['transactionId'] ?? '—'),
                (string) ($payload['reason'] ?? '—'),
                $connectorId ? ' C' . $connectorId : ''
            ),
            'StartTransaction' => sprintf(
                'Start tx pending C%d meter=%s',
                $connectorId ?? 0,
                (string) ($payload['meterStart'] ?? '—')
            ),
            'MeterValues' => sprintf(
                'MeterValues C%d',
                $connectorId ?? 0
            ),
            'DataTransfer' => sprintf(
                'DataTransfer %s / %s',
                (string) ($payload['vendorId'] ?? '—'),
                (string) ($payload['messageId'] ?? '—')
            ),
            'BootNotification' => 'Boot ' . (string) ($payload['chargePointModel'] ?? ''),
            'Heartbeat' => 'Heartbeat',
            default => $direction === 'inbound'
                ? 'Inbound ' . $action
                : 'Outbound ' . $action,
        };
    }

    private function summarizeCommand(OcppCommand $command): string
    {
        $payload = is_array($command->payload) ? $command->payload : [];
        $connectorId = $this->extractConnectorId($payload);

        return match ($command->action) {
            'Reset' => 'Reset ' . (string) ($payload['type'] ?? '—') . ' (stație întreagă)',
            'ChangeAvailability' => sprintf(
                'ChangeAvailability C%d → %s',
                $connectorId ?? 0,
                (string) ($payload['type'] ?? '—')
            ),
            'RemoteStartTransaction', 'RequestStartTransaction' => sprintf(
                'RemoteStart C%d',
                $connectorId ?? 0
            ),
            'RemoteStopTransaction', 'RequestStopTransaction' => sprintf(
                'RemoteStop tx=%s',
                (string) ($payload['transactionId'] ?? '—')
            ),
            'UnlockConnector' => sprintf('UnlockConnector C%d', $connectorId ?? 0),
            'TriggerMessage' => sprintf(
                'Trigger %s%s',
                (string) ($payload['requestedMessage'] ?? '—'),
                $connectorId ? ' C' . $connectorId : ''
            ),
            default => $command->action . ' · ' . $command->status,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function entryRelation(
        string $action,
        array $payload,
        ChargingSession $session,
        ?int $connectorId
    ): string {
        $sessionConnector = (int) ($session->ocpp_connector_id ?: 1);
        $sessionTx = (string) ($session->ocpp_transaction_id ?? '');
        $payloadTx = (string) ($payload['transactionId'] ?? '');

        if ($action === 'StopTransaction' && $sessionTx !== '' && $payloadTx === $sessionTx) {
            return 'session_stop';
        }

        if ($action === 'StopTransaction' && $connectorId === $sessionConnector) {
            return 'session_stop';
        }

        if ($action === 'StartTransaction' && $connectorId === $sessionConnector) {
            return 'session_start';
        }

        if ($connectorId === $sessionConnector) {
            return 'session_connector';
        }

        if ($connectorId !== null && $connectorId !== $sessionConnector) {
            return 'other_connector';
        }

        if (in_array($action, ['Reset'], true)) {
            return 'station_wide';
        }

        return 'neutral';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function connectorStates(Station $station): array
    {
        $configuration = is_array($station->ocpp_configuration) ? $station->ocpp_configuration : [];
        $connectors = is_array($configuration['connectors'] ?? null) ? $configuration['connectors'] : [];

        return collect($station->expectedConnectorIds())
            ->map(function (int $connectorId) use ($connectors): array {
                $connector = is_array($connectors[$connectorId] ?? null) ? $connectors[$connectorId] : [];

                return [
                    'connector_id' => $connectorId,
                    'label' => Station::connectorPortLabel($connectorId),
                    'status' => $connector['status'] ?? null,
                    'error_code' => $connector['errorCode'] ?? null,
                    'info' => $connector['info'] ?? null,
                    'vendor_error_code' => $connector['vendorErrorCode'] ?? null,
                    'timestamp' => $connector['timestamp'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionSummary(ChargingSession $session): array
    {
        return [
            'id' => $session->id,
            'user' => $session->user?->only(['id', 'name', 'email']),
            'station_id' => $session->station_id,
            'ocpp_connector_id' => $session->ocpp_connector_id,
            'ocpp_transaction_id' => $session->ocpp_transaction_id,
            'start_source' => $session->start_source,
            'stop_source' => $session->stop_source,
            'ocpp_stop_reason' => $session->ocpp_stop_reason,
            'start_time' => $session->start_time?->toIso8601String(),
            'end_time' => $session->end_time?->toIso8601String(),
            'kwh_consumed' => $session->kwh_consumed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeTimeline(ChargingSession $session, Station $station): array
    {
        $sessionConnector = (int) ($session->ocpp_connector_id ?: 1);
        $bufferSeconds = 120;
        $from = ($session->start_time ?? now())->copy()->subSeconds($bufferSeconds);
        $to = ($session->end_time ?? now())->copy()->addSeconds($bufferSeconds);
        $timeline = $this->timelineEntries($station, $session, $from, $to, limit: 200);

        $otherConnectorEvents = array_values(array_filter(
            $timeline,
            fn (array $entry) => $entry['relation'] === 'other_connector'
                && in_array($entry['action'], ['StatusNotification', 'StopTransaction', 'StartTransaction'], true)
        ));

        $sessionStop = null;
        foreach (array_reverse($timeline) as $entry) {
            if ($entry['relation'] === 'session_stop') {
                $sessionStop = $entry;
                break;
            }
        }

        $stationResets = array_values(array_filter(
            $timeline,
            fn (array $entry) => $entry['action'] === 'Reset'
        ));

        $hypothesis = null;
        if ($sessionStop && ($sessionStop['payload']['reason'] ?? null) === 'Other') {
            $stopAt = $sessionStop['at'] ?? null;
            $priorOther = array_values(array_filter(
                $otherConnectorEvents,
                fn (array $entry) => $stopAt === null || ($entry['at'] ?? '') <= $stopAt
            ));

            if ($priorOther !== []) {
                $hypothesis = sprintf(
                    'StopTransaction reason=Other pe C%d după %d eveniment(e) pe alt conector — probabil comportament firmware dual-port.',
                    $sessionConnector,
                    count($priorOther)
                );
            } elseif ($stationResets !== []) {
                $hypothesis = 'StopTransaction reason=Other după Reset OCPP pe stație — verifică dacă recovery/reset a fost declanșat.';
            } else {
                $hypothesis = 'StopTransaction reason=Other fără evenimente clare pe alt conector în fereastra analizată — verifică log stație sau DataTransfer vendor.';
            }
        }

        return [
            'session_connector_id' => $sessionConnector,
            'other_connector_events_before_stop' => count($otherConnectorEvents),
            'station_reset_commands' => count($stationResets),
            'last_session_stop' => $sessionStop,
            'hypothesis' => $hypothesis,
        ];
    }
}
