<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class OcppService
{
    public function startTransaction(Station|int $station, ?ChargingSession $session = null, ?User $user = null): array
    {
        $station = $this->resolveStation($station);

        if ($this->isSimulatorMode()) {
            return [
                'station_id' => $station->id,
                'mode' => 'simulator',
                'status' => 'accepted',
                'transaction_id' => 'tx-' . uniqid(),
                'message' => 'Incarcarea a pornit (simulare OCPP).',
            ];
        }

        $this->assertStationCanReceiveCommands($station);

        $command = OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $session?->id,
            'message_uid' => (string) Str::uuid(),
            'action' => $station->ocpp_version === '2.0.1' ? 'RequestStartTransaction' : 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_PENDING,
            'available_at' => now(),
            'payload' => $this->buildStartPayload($station, $session, $user),
        ]);

        return [
            'station_id' => $station->id,
            'mode' => 'gateway',
            'status' => 'queued',
            'command_id' => $command->id,
            'message_uid' => $command->message_uid,
            'message' => 'Comanda de pornire a fost pusa in coada OCPP.',
        ];
    }

    public function stopTransaction(Station|int $station, ?ChargingSession $session = null): array
    {
        $station = $this->resolveStation($station);

        if ($this->isSimulatorMode()) {
            return [
                'station_id' => $station->id,
                'mode' => 'simulator',
                'status' => 'accepted',
                'message' => 'Incarcarea a fost oprita (simulare OCPP).',
            ];
        }

        $this->assertStationCanReceiveCommands($station);

        $command = OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $session?->id,
            'message_uid' => (string) Str::uuid(),
            'action' => $station->ocpp_version === '2.0.1' ? 'RequestStopTransaction' : 'RemoteStopTransaction',
            'status' => OcppCommand::STATUS_PENDING,
            'available_at' => now(),
            'payload' => $this->buildStopPayload($station, $session),
        ]);

        return [
            'station_id' => $station->id,
            'mode' => 'gateway',
            'status' => 'queued',
            'command_id' => $command->id,
            'message_uid' => $command->message_uid,
            'message' => 'Comanda de oprire a fost pusa in coada OCPP.',
        ];
    }

    public function connectionUrl(Station $station): ?string
    {
        if (! $station->ocpp_identity) {
            return null;
        }

        return rtrim((string) config('services.ocpp.public_url'), '/') . '/' . rawurlencode($station->ocpp_identity);
    }

    public function isSimulatorMode(): bool
    {
        return config('services.ocpp.mode', 'simulator') === 'simulator';
    }

    public function ensureReadyForRemoteCommands(Station $station): void
    {
        if (! $this->isSimulatorMode()) {
            $this->assertStationCanReceiveCommands($station);
        }
    }

    private function resolveStation(Station|int $station): Station
    {
        if ($station instanceof Station) {
            return $station;
        }

        return Station::query()->findOrFail($station);
    }

    private function assertStationCanReceiveCommands(Station $station): void
    {
        if (! $station->ocpp_identity) {
            throw new RuntimeException('Statia nu are OCPP identity configurat.', 422);
        }

        if ($station->ocpp_connection_status !== Station::OCPP_CONNECTION_CONNECTED) {
            throw new RuntimeException('Statia nu este conectata la gateway-ul OCPP.', 422);
        }
    }

    private function buildStartPayload(Station $station, ?ChargingSession $session, ?User $user): array
    {
        $idTag = $session?->ocpp_id_tag ?: ($user ? 'user:' . $user->id : 'volta-backoffice');

        if ($station->ocpp_version === '2.0.1') {
            return [
                'evseId' => 1,
                'remoteStartId' => $session?->id ?? random_int(100000, 999999),
                'idToken' => [
                    'idToken' => $idTag,
                    'type' => 'Central',
                ],
            ];
        }

        return [
            'connectorId' => 1,
            'idTag' => $idTag,
        ];
    }

    private function buildStopPayload(Station $station, ?ChargingSession $session): array
    {
        $transactionId = $session?->ocpp_transaction_id ?: (string) $session?->id;

        if ($station->ocpp_version === '2.0.1') {
            return [
                'transactionId' => $transactionId,
            ];
        }

        return [
            'transactionId' => is_numeric($transactionId) ? (int) $transactionId : $transactionId,
        ];
    }
}
