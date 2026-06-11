<?php

namespace App\Models;

use App\Services\SessionEnergyService;
use Illuminate\Database\Eloquent\Model;

class ChargingSession extends Model
{
    protected $fillable = [
        'user_id',
        'station_id',
        'ocpp_connector_id',
        'ocpp_transaction_id',
        'ocpp_id_tag',
        'meter_start_kwh',
        'meter_stop_kwh',
        'start_source',
        'stop_source',
        'start_time',
        'end_time',
        'kwh_consumed',
        'charge_budget',
        'target_kwh',
        'live_metrics',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'kwh_consumed' => 'float',
        'charge_budget' => 'float',
        'target_kwh' => 'float',
        'meter_start_kwh' => 'float',
        'meter_stop_kwh' => 'float',
        'live_metrics' => 'array',
    ];

    protected $appends = [
        'telemetry',
        'kwh_delivered',
        'power_kw_live',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function ocppCommands()
    {
        return $this->hasMany(OcppCommand::class);
    }

    /**
     * OCPP 1.6: RemoteStopTransaction foloseste transactionId din StartTransaction.conf
     * (alocat de Central System), NU valoarea din MeterValues.
     *
     * @see https://ocpp.md/ocpp-1.6j/sequences/#34-remote-stop-transaction
     */
    public function remoteStopTransactionId(): int
    {
        $transactionId = $this->ocpp_transaction_id ?: (string) $this->id;

        return is_numeric($transactionId) ? (int) $transactionId : 0;
    }

    /** Diagnostic: ce transactionId raporteaza statia in MeterValues (poate diferi de cel alocat). */
    public function rememberReportedTransactionId(int|string|null $transactionId): void
    {
        if ($transactionId === null || $transactionId === '') {
            return;
        }

        $normalized = is_numeric($transactionId) ? (int) $transactionId : (string) $transactionId;
        $live = is_array($this->live_metrics) ? $this->live_metrics : [];

        if (($live['reported_transaction_id'] ?? null) === $normalized) {
            return;
        }

        $live['reported_transaction_id'] = $normalized;
        $this->update(['live_metrics' => $live]);
    }

    public function getTelemetryAttribute(): array
    {
        $live = is_array($this->live_metrics) ? $this->live_metrics : [];
        $stationLive = $this->station?->liveStatus($this->ocpp_connector_id) ?? [];
        $kwhDelivered = app(SessionEnergyService::class)->telemetryKwhDelivered($this);

        return [
            'kwh_consumed' => $kwhDelivered,
            'meter_start_kwh' => $this->meter_start_kwh,
            'meter_stop_kwh' => $this->meter_stop_kwh,
            'meter_total_kwh' => $live['energy_kwh'] ?? $stationLive['meter_value_kwh'] ?? null,
            'power_kw' => $this->resolveLivePowerKw($live, $stationLive),
            'current_a' => $live['current_a'] ?? $stationLive['current_a'] ?? null,
            'voltage_v' => $live['voltage_v'] ?? $stationLive['voltage_v'] ?? null,
            'soc_percent' => $live['soc_percent'] ?? $stationLive['soc_percent'] ?? null,
            'temperature_c' => $live['temperature_c'] ?? null,
            'power_samples' => is_array($live['power_samples'] ?? null) ? $live['power_samples'] : [],
            'sampled_at' => $live['sampled_at'] ?? $stationLive['meter_sampled_at'] ?? null,
            'ocpp_transaction_id' => $this->ocpp_transaction_id,
            'ocpp_connector_id' => $this->ocpp_connector_id,
            'connector_label' => $this->ocpp_connector_id
                ? Station::connectorPortLabel((int) $this->ocpp_connector_id)
                : null,
            'charge_budget' => $this->charge_budget,
            'target_kwh' => $this->target_kwh,
            'kwh_remaining' => $this->target_kwh !== null
                ? round(max(0, (float) $this->target_kwh - $kwhDelivered), 3)
                : null,
            'budget_spent' => $this->charge_budget !== null
                ? round(min(
                    $kwhDelivered * (float) (Tariff::query()->latest('id')->value('price_per_kwh') ?? config('billing.price_per_kwh', 0.20)),
                    (float) $this->charge_budget
                ), 2)
                : null,
        ];
    }

    public function getKwhDeliveredAttribute(): float
    {
        return app(SessionEnergyService::class)->telemetryKwhDelivered($this);
    }

    public function getPowerKwLiveAttribute(): ?float
    {
        $live = is_array($this->live_metrics) ? $this->live_metrics : [];
        $stationLive = $this->station?->liveStatus($this->ocpp_connector_id) ?? [];

        return $this->resolveLivePowerKw($live, $stationLive);
    }

    /**
     * @param  array<string, mixed>  $live
     * @param  array<string, mixed>  $stationLive
     */
    private function resolveLivePowerKw(array $live, array $stationLive): ?float
    {
        $power = $live['power_kw'] ?? $stationLive['power_kw'] ?? null;

        return $power !== null ? round((float) $power, 3) : null;
    }
}
