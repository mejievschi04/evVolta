<?php

namespace App\Services;

class OcppService
{
    public function startTransaction(int $stationId): array
    {
        return [
            'station_id' => $stationId,
            'status' => 'accepted',
            'transaction_id' => 'tx-' . uniqid(),
            'message' => 'Charging started (simulated OCPP).',
        ];
    }

    public function stopTransaction(int $stationId): array
    {
        return [
            'station_id' => $stationId,
            'status' => 'accepted',
            'message' => 'Charging stopped (simulated OCPP).',
        ];
    }
}
