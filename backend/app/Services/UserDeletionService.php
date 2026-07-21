<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UserDeletionService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly OcppService $ocppService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function assertDeletable(User $user): void
    {
        if ($user->isAdmin()) {
            throw new RuntimeException('Contul de administrator nu poate fi sters.', 403);
        }

        if ($this->walletService->hasOpenChargingSession($user)) {
            throw new RuntimeException('Exista o incarcare activa. Opreste sesiunea inainte de stergerea contului.', 422);
        }

        if ((float) $user->wallet_balance > 0.009) {
            throw new RuntimeException('Soldul contului trebuie sa fie zero inainte de stergere.', 422);
        }

        $unpaidInvoices = Invoice::query()
            ->where('user_id', $user->id)
            ->where('status', 'unpaid')
            ->count();

        if ($unpaidInvoices > 0) {
            throw new RuntimeException('Contul are facturi neplatite. Achita sau contacteaza suportul.', 422);
        }
    }

    public function delete(User $user, ?User $actor = null, string $auditAction = 'auth.account_deleted'): void
    {
        $this->assertDeletable($user);

        $metadata = [
            'email' => $user->email,
            'name' => $user->name,
            'account_type' => $user->account_type,
            'wallet_balance' => $user->wallet_balance,
        ];

        DB::transaction(function () use ($user, $actor, $auditAction, $metadata): void {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->assertDeletable($user);
            $this->releaseBlockingReservations($user);

            $this->auditLogService->record(
                action: $auditAction,
                actor: $actor,
                subjectType: User::class,
                subjectId: $user->id,
                metadata: $metadata,
            );

            $user->delete();
        });
    }

    private function releaseBlockingReservations(User $user): void
    {
        $reservations = Reservation::query()
            ->where('user_id', $user->id)
            ->blocking()
            ->with('station')
            ->get();

        foreach ($reservations as $reservation) {
            $reservation->update([
                'status' => Reservation::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            if (! $reservation->station || ! $reservation->ocpp_reservation_id) {
                continue;
            }

            try {
                $this->ocppService->cancelReservation(
                    $reservation->station,
                    $reservation->ocpp_reservation_id,
                );
            } catch (\Throwable) {
                // Deletion continues even if the station cannot confirm the cancel.
            }
        }
    }
}
