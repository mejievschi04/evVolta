<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReservationService
{
    public function __construct(
        private readonly OcppService $ocppService,
        private readonly WalletService $walletService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Reservation $reservation, bool $refreshConnector = false): array
    {
        $reservation->loadMissing(['station', 'user:id,name,email']);

        $station = $reservation->station;
        if ($station && ! array_key_exists('ocpp_configuration', $station->getAttributes())) {
            $station = Station::query()->find($reservation->station_id) ?? $station;
        }

        if ($refreshConnector && $station && $this->ocppService->shouldEnforcePlugCheck($station)) {
            try {
                $station = $this->ocppService->syncConnectorStateBeforeStart($station);
            } catch (\Throwable) {
                // If the station cannot answer a status refresh, present the last known status.
            }
        }

        $connectorStatus = $station?->connectorOcppStatus($reservation->connector_id);
        $requiresPlugCheck = $station ? $this->ocppService->shouldEnforcePlugCheck($station) : false;
        $plugDetected = $station ? $station->isPluggedConnectorStatus($connectorStatus) : false;

        return [
            'id' => $reservation->id,
            'station_id' => $reservation->station_id,
            'connector_id' => $reservation->connector_id,
            'starts_at' => $reservation->starts_at?->toIso8601String(),
            'ends_at' => $reservation->ends_at?->toIso8601String(),
            'grace_ends_at' => $reservation->graceEndsAt()->toIso8601String(),
            'status' => $reservation->status,
            'fee_amount' => round((float) $reservation->fee_amount, 2),
            'fee_charged' => (bool) $reservation->fee_charged,
            'no_show_fee_amount' => round((float) $reservation->no_show_fee_amount, 2),
            'can_start' => $this->canStartCharging($reservation),
            'can_cancel' => $this->canCancel($reservation),
            'connector_status' => $connectorStatus,
            'station_online' => (bool) $station?->isOcppOnline(),
            'vehicle_connected' => $plugDetected,
            'requires_plug_check' => $requiresPlugCheck,
            'station' => $station?->only(['id', 'name', 'location']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyPlugForReservation(Reservation $reservation): array
    {
        $reservation->loadMissing('station');
        $station = $reservation->station;

        if (! $station) {
            throw new RuntimeException('Statia rezervarii nu a fost gasita.', 404);
        }

        if ($this->ocppService->shouldEnforcePlugCheck($station)) {
            try {
                $station = $this->ocppService->syncConnectorStateBeforeStart($station);
            } catch (\Throwable) {
                // Present the last known connector status when the station cannot refresh.
            }
        }

        $connectorStatus = $station->connectorOcppStatus($reservation->connector_id);
        $requiresPlugCheck = $this->ocppService->shouldEnforcePlugCheck($station);
        $plugDetected = $station->isPluggedConnectorStatus($connectorStatus);

        return [
            'connector_status' => $connectorStatus,
            'station_online' => $station->isOcppOnline(),
            'vehicle_connected' => $plugDetected,
            'requires_plug_check' => $requiresPlugCheck,
        ];
    }

    public function assertConnectorPlugged(Station $station, int $connectorId): void
    {
        if (! $this->ocppService->shouldEnforcePlugCheck($station)) {
            return;
        }

        $status = $station->connectorOcppStatus($connectorId);

        if (! $station->isPluggedConnectorStatus($status)) {
            throw new RuntimeException('Conecteaza masina la portul rezervat inainte de pornire.', 422);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availabilityForStation(Station $station, ?int $connectorId, Carbon $day): array
    {
        if (! $station->reservationsEnabled()) {
            return [];
        }

        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $connectorIds = $connectorId
            ? [$connectorId]
            : $station->expectedConnectorIds();

        $booked = Reservation::query()
            ->where('station_id', $station->id)
            ->whereIn('connector_id', $connectorIds)
            ->blocking()
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get(['id', 'connector_id', 'starts_at', 'ends_at', 'status']);

        return $booked->map(fn (Reservation $reservation) => [
            'id' => $reservation->id,
            'connector_id' => $reservation->connector_id,
            'starts_at' => $reservation->starts_at?->toIso8601String(),
            'ends_at' => $reservation->ends_at?->toIso8601String(),
            'status' => $reservation->status,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function book(
        User $user,
        Station $station,
        int $connectorId,
        Carbon $startsAt,
        int $durationMinutes,
    ): array {
        if (! $station->reservationsEnabled()) {
            throw new RuntimeException('Rezervarile nu sunt activate la aceasta statie.', 422);
        }

        if (config('services.ocpp.mode', 'simulator') !== 'simulator' && ! $station->isOcppOnline()) {
            throw new RuntimeException('Statia nu este conectata la gateway-ul OCPP.', 422);
        }

        if (! in_array($connectorId, $station->expectedConnectorIds(), true)) {
            throw new RuntimeException('Conector invalid.', 422);
        }

        // Rezervarea "acum": toleram decalajul de ceas dintre telefon si server
        // si latenta retelei. Daca startul cerut este la/aproape de momentul
        // curent (sau usor in trecut), il fixam la ora serverului.
        $immediateWindow = now()->addMinutes(2);
        if ($startsAt->lessThanOrEqualTo($immediateWindow)) {
            $startsAt = now();
        } else {
            $minLead = max(0, (int) config('reservations.min_lead_minutes', 0));
            if ($minLead > 0 && $startsAt->lessThan(now()->addMinutes($minLead))) {
                throw new RuntimeException(sprintf('Rezervarea trebuie facuta cu cel putin %d minute inainte.', $minLead), 422);
            }
        }

        $maxAdvanceDays = max(1, (int) $station->reservation_advance_days);
        if ($startsAt->greaterThan(now()->addDays($maxAdvanceDays))) {
            throw new RuntimeException(sprintf('Poti rezerva maxim cu %d zile inainte.', $maxAdvanceDays), 422);
        }

        $maxDuration = min(60, max(15, (int) $station->reservation_max_duration_minutes));
        if ($durationMinutes < 15 || $durationMinutes > $maxDuration) {
            throw new RuntimeException(sprintf('Durata rezervarii trebuie sa fie intre 15 si %d minute.', $maxDuration), 422);
        }

        $endsAt = $startsAt->copy()->addMinutes($durationMinutes);
        $feeAmount = round((float) $station->reservation_fee, 2);
        $noShowFee = round((float) $station->reservation_no_show_fee, 2);

        $reservation = DB::transaction(function () use (
            $user,
            $station,
            $connectorId,
            $startsAt,
            $endsAt,
            $feeAmount,
            $noShowFee,
        ) {
            $station = Station::query()->whereKey($station->id)->lockForUpdate()->firstOrFail();

            if (Reservation::overlapping($station->id, $connectorId, $startsAt, $endsAt)->exists()) {
                throw new RuntimeException('Intervalul selectat se suprapune cu o alta rezervare.', 422);
            }

            if (! $station->connectorCanReserve($connectorId, $user)) {
                throw new RuntimeException('Conectorul este ocupat sau indisponibil pentru rezervare.', 422);
            }

            if ($station->activeReservationForConnector($connectorId, $user)) {
                throw new RuntimeException('Conectorul are deja o rezervare activa.', 422);
            }

            $this->walletService->assertCanChargeReservationFee($user, $feeAmount);

            $reservation = Reservation::query()->create([
                'user_id' => $user->id,
                'station_id' => $station->id,
                'connector_id' => $connectorId,
                'ocpp_reservation_id' => $this->nextOcppReservationId($station),
                'id_tag' => OcppService::idTagForUser($user),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => Reservation::STATUS_PENDING,
                'fee_amount' => $feeAmount,
                'no_show_fee_amount' => $noShowFee,
            ]);

            if ($feeAmount > 0) {
                $this->walletService->chargeReservationFee($user->fresh(), $reservation, $feeAmount);
            }

            return $reservation->fresh(['station']);
        });

        $ocpp = $this->ocppService->reserveNow(
            $reservation->station,
            $reservation->connector_id,
            $reservation->id_tag,
            $reservation->ocpp_reservation_id,
            $reservation->graceEndsAt(),
        );

        if ($this->ocppService->isSimulatorMode()) {
            $this->confirmReservation($reservation->fresh(), $reservation->connector_id);
        }

        $this->auditLogService->record(
            action: 'reservation.booked',
            actor: $user,
            subjectType: Reservation::class,
            subjectId: $reservation->id,
            station: $reservation->station,
            metadata: [
                'connector_id' => $reservation->connector_id,
                'starts_at' => $reservation->starts_at?->toIso8601String(),
                'ends_at' => $reservation->ends_at?->toIso8601String(),
                'fee_amount' => $reservation->fee_amount,
                'ocpp' => $ocpp,
            ],
        );

        return [
            'reservation' => $this->present($reservation->fresh(['station'])),
            'ocpp' => $ocpp,
        ];
    }

    public function cancel(User $user, Reservation $reservation): array
    {
        if ($reservation->user_id !== $user->id) {
            throw new RuntimeException('Rezervarea nu iti apartine.', 403);
        }

        if (! $this->canCancel($reservation)) {
            throw new RuntimeException('Rezervarea nu mai poate fi anulata.', 422);
        }

        DB::transaction(function () use ($reservation) {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($reservation->status, [Reservation::STATUS_PENDING, Reservation::STATUS_CONFIRMED], true)) {
                throw new RuntimeException('Rezervarea nu mai poate fi anulata.', 422);
            }

            if ($reservation->fee_charged && $this->shouldRefundFee($reservation)) {
                $this->walletService->refundReservationFee($reservation->user, $reservation);
            }

            $reservation->update([
                'status' => Reservation::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);
        });

        $this->ocppService->cancelReservation($reservation->station, $reservation->ocpp_reservation_id);

        $this->auditLogService->record(
            action: 'reservation.cancelled',
            actor: $user,
            subjectType: Reservation::class,
            subjectId: $reservation->id,
            station: $reservation->station,
            metadata: [
                'connector_id' => $reservation->connector_id,
            ],
        );

        return [
            'reservation' => $this->present($reservation->fresh(['station'])),
        ];
    }

    public function assertUserMayStart(User $user, Station $station, int $connectorId): void
    {
        if (! $station->reservationsEnabled()) {
            return;
        }

        $foreign = $station->activeReservationForConnector($connectorId, $user);
        if ($foreign && $foreign->isWithinStartWindow()) {
            throw new RuntimeException('Conectorul este rezervat pentru alt utilizator.', 422);
        }

        if (! $station->reservation_require_for_start) {
            return;
        }

        $own = Reservation::query()
            ->where('user_id', $user->id)
            ->where('station_id', $station->id)
            ->where('connector_id', $connectorId)
            ->blocking()
            ->where('starts_at', '<=', now())
            ->orderByDesc('starts_at')
            ->get()
            ->first(fn (Reservation $reservation) => $reservation->isWithinStartWindow());

        if (! $own) {
            throw new RuntimeException('Ai nevoie de o rezervare activa pentru a porni incarcarea.', 422);
        }
    }

    public function attachSession(Reservation $reservation, int $sessionId): void
    {
        if (! in_array($reservation->status, [Reservation::STATUS_CONFIRMED, Reservation::STATUS_ACTIVE], true)) {
            return;
        }

        $reservation->update([
            'status' => Reservation::STATUS_ACTIVE,
            'charging_session_id' => $sessionId,
        ]);
    }

    public function completeForSession(int $sessionId): void
    {
        $reservation = Reservation::query()
            ->where('charging_session_id', $sessionId)
            ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_ACTIVE])
            ->first();

        if (! $reservation) {
            return;
        }

        $reservation->update([
            'status' => Reservation::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function confirmReservation(Reservation $reservation, int $connectorId): void
    {
        // Atomic transition: only a still-pending reservation can become confirmed.
        // Prevents a late ReserveNow ACCEPTED callback from resurrecting a cancelled reservation.
        $confirmed = Reservation::query()
            ->whereKey($reservation->id)
            ->where('status', Reservation::STATUS_PENDING)
            ->update(['status' => Reservation::STATUS_CONFIRMED]);

        if ($confirmed > 0) {
            $reservation->refresh();
            $reservation->station?->updateConnectorOcppStatus($connectorId, 'Reserved');
        }
    }

    public function handleOcppReserveFailed(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if (! $reservation || $reservation->status !== Reservation::STATUS_PENDING) {
                return;
            }

            if ($reservation->fee_charged) {
                $this->walletService->refundReservationFee($reservation->user, $reservation);
            }

            $reservation->update([
                'status' => Reservation::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'metadata' => array_merge($reservation->metadata ?? [], [
                    'ocpp_reserve_failed' => true,
                ]),
            ]);
        });
    }

    public function processDueReservations(): array
    {
        $expired = 0;
        $noShows = 0;

        Reservation::query()
            ->with(['station', 'user'])
            ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_PENDING])
            ->where('ends_at', '<', now())
            ->orderBy('id')
            ->chunkById(50, function ($reservations) use (&$expired, &$noShows) {
                foreach ($reservations as $reservation) {
                    if ($reservation->graceEndsAt()->isFuture()) {
                        continue;
                    }

                    if ($reservation->status === Reservation::STATUS_PENDING) {
                        $this->handleOcppReserveFailed($reservation);
                        $expired++;

                        continue;
                    }

                    $this->markNoShow($reservation);
                    $noShows++;
                }
            });

        return ['expired' => $expired, 'no_shows' => $noShows];
    }

    public function markNoShow(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if (! $reservation || ! in_array($reservation->status, [Reservation::STATUS_CONFIRMED, Reservation::STATUS_PENDING], true)) {
                return;
            }

            $noShowFee = round((float) $reservation->no_show_fee_amount, 2);
            if ($noShowFee > 0 && ! $reservation->no_show_charged) {
                $this->walletService->chargeNoShowFee($reservation->user, $reservation, $noShowFee);
            }

            $reservation->update([
                'status' => Reservation::STATUS_NO_SHOW,
                'completed_at' => now(),
            ]);
        });

        $this->ocppService->cancelReservation($reservation->station, $reservation->ocpp_reservation_id);
    }

    public function canStartCharging(Reservation $reservation): bool
    {
        return in_array($reservation->status, [Reservation::STATUS_CONFIRMED, Reservation::STATUS_ACTIVE], true)
            && $reservation->isWithinStartWindow();
    }

    public function canCancel(Reservation $reservation): bool
    {
        return in_array($reservation->status, [Reservation::STATUS_PENDING, Reservation::STATUS_CONFIRMED], true)
            && $reservation->starts_at->greaterThan(now());
    }

    private function shouldRefundFee(Reservation $reservation): bool
    {
        $refundMinutes = max(0, (int) config('reservations.cancel_refund_minutes', 60));

        return $reservation->starts_at->greaterThan(now()->addMinutes($refundMinutes));
    }

    private function nextOcppReservationId(Station $station): int
    {
        $max = (int) Reservation::query()
            ->where('station_id', $station->id)
            ->max('ocpp_reservation_id');

        return max(1, $max + 1);
    }
}
