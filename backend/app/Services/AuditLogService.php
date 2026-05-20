<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;

class AuditLogService
{
    public function record(
        string $action,
        ?User $actor = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?Station $station = null,
        ?ChargingSession $session = null,
        array $metadata = []
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'station_id' => $station?->id,
            'charging_session_id' => $session?->id,
            'metadata' => $metadata ?: null,
        ]);
    }
}
