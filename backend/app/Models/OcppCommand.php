<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcppCommand extends Model
{
    /** @var list<string> */
    public const HIGH_PRIORITY_ACTIONS = [
        'RemoteStartTransaction',
        'RequestStartTransaction',
        'RemoteStopTransaction',
        'RequestStopTransaction',
        'Reset',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    /** Erori electrice: nu forța ChangeAvailability/Reset automat. */
    public const NON_AUTO_RECOVERY_ERROR_CODES = [
        'LeakageRcmuError',
        'GroundFailure',
        'HighTemperature',
        'OverCurrentFailure',
        'UnderVoltage',
        'OverVoltage',
    ];

    protected $fillable = [
        'station_id',
        'charging_session_id',
        'depends_on_command_id',
        'message_uid',
        'action',
        'status',
        'payload',
        'response_payload',
        'error_message',
        'available_at',
        'sent_at',
        'acknowledged_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'response_payload' => 'array',
        'available_at' => 'datetime',
        'sent_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function chargingSession()
    {
        return $this->belongsTo(ChargingSession::class);
    }

    public function dependsOn()
    {
        return $this->belongsTo(self::class, 'depends_on_command_id');
    }

    public function dependents()
    {
        return $this->hasMany(self::class, 'depends_on_command_id');
    }

    public function scopeReadyToSend($query)
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->where(fn ($inner) => $inner
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()))
            ->where(function ($inner) {
                $inner->whereNull('depends_on_command_id')
                    ->orWhereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('ocpp_commands as ocpp_parents')
                            ->whereColumn('ocpp_parents.id', 'ocpp_commands.depends_on_command_id')
                            ->where('ocpp_parents.status', self::STATUS_ACCEPTED);
                    });
            });
    }

    /**
     * RemoteStop inca in desfasurare: in coada, trimis, sau acceptat recent.
     * Un Accepted fara StopTransaction ulterior nu trebuie sa blocheze sesiunea la nesfarsit.
     */
    public function scopeAwaitingRemoteStop($query)
    {
        $acceptedWindow = max(30, (int) config('services.ocpp.remote_stop_accepted_window_seconds', 180));

        return $query
            ->whereIn('action', ['RemoteStopTransaction', 'RequestStopTransaction'])
            ->where(function ($inner) use ($acceptedWindow) {
                $inner->whereIn('status', [self::STATUS_PENDING, self::STATUS_SENT])
                    ->orWhere(function ($accepted) use ($acceptedWindow) {
                        $accepted->where('status', self::STATUS_ACCEPTED)
                            ->where('acknowledged_at', '>=', now()->subSeconds($acceptedWindow));
                    });
            });
    }

    /**
     * Marcheaza ca esuate comenzile trimise la care statia nu a mai raspuns
     * (timeout sau conexiune inchisa) si esueaza in lant comenzile dependente.
     */
    public static function failUnanswered(
        string $reason,
        ?int $stationId = null,
        ?\DateTimeInterface $sentBefore = null,
    ): int {
        $query = self::query()->where('status', self::STATUS_SENT);

        if ($stationId !== null) {
            $query->where('station_id', $stationId);
        }

        if ($sentBefore !== null) {
            $query->where(fn ($inner) => $inner
                ->whereNull('sent_at')
                ->orWhere('sent_at', '<', $sentBefore));
        }

        $failed = 0;

        foreach ($query->get() as $command) {
            $updated = self::query()
                ->whereKey($command->id)
                ->where('status', self::STATUS_SENT)
                ->update([
                    'status' => self::STATUS_FAILED,
                    'error_message' => $reason,
                    'acknowledged_at' => now(),
                ]);

            if ($updated === 0) {
                continue;
            }

            $command->failPendingDependents(
                'Comanda parinte a esuat: ' . $command->action . ' (' . $reason . ')'
            );
            $failed++;
        }

        return $failed;
    }

    public function scopeOrderByDispatchPriority($query)
    {
        $quoted = implode("','", self::HIGH_PRIORITY_ACTIONS);

        return $query
            ->orderByRaw("CASE WHEN action IN ('{$quoted}') THEN 0 ELSE 1 END")
            ->orderBy('id');
    }

    public function failPendingDependents(string $reason): void
    {
        $pending = self::query()
            ->where('depends_on_command_id', $this->id)
            ->where('status', self::STATUS_PENDING)
            ->get();

        foreach ($pending as $dependent) {
            $dependent->update([
                'status' => self::STATUS_FAILED,
                'error_message' => $reason,
                'acknowledged_at' => now(),
            ]);
            $dependent->failPendingDependents($reason);
        }
    }

    public function releaseDelayedDependents(?\DateTimeInterface $availableAt = null): void
    {
        self::query()
            ->where('depends_on_command_id', $this->id)
            ->where('status', self::STATUS_PENDING)
            ->whereIn('action', ['RemoteStartTransaction', 'RequestStartTransaction'])
            ->update([
                'available_at' => $availableAt ?? now(),
            ]);
    }
}
