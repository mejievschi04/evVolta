<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\StationFavorite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

        if ($user->anonymized_at !== null || $user->trashed()) {
            throw new RuntimeException('Contul este deja sters.', 422);
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
            'mode' => 'anonymize_retain_fiscal',
        ];

        DB::transaction(function () use ($user, $actor, $auditAction, $metadata): void {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->assertDeletable($user);
            $this->releaseBlockingReservations($user);

            StationFavorite::query()->where('user_id', $user->id)->delete();

            Invoice::query()
                ->where('user_id', $user->id)
                ->update([
                    'buyer_name' => 'Utilizator sters',
                    'buyer_email' => null,
                    'buyer_idno' => null,
                ]);

            $this->scrubAuditMetadata($user->id);

            $this->auditLogService->record(
                action: $auditAction,
                actor: $actor,
                subjectType: User::class,
                subjectId: $user->id,
                metadata: [
                    'account_type' => $metadata['account_type'],
                    'wallet_balance' => $metadata['wallet_balance'],
                    'mode' => 'anonymize_retain_fiscal',
                    // Do not store cleartext email after erasure request.
                    'email_hash' => hash('sha256', strtolower((string) $metadata['email'])),
                ],
            );

            $user->forceFill([
                'name' => 'Utilizator sters',
                'first_name' => null,
                'last_name' => null,
                'email' => sprintf('deleted.%d.%s@anonymized.vcharge.local', $user->id, Str::lower(Str::random(8))),
                'phone' => null,
                'password' => Hash::make(Str::random(64)),
                'wallet_balance' => 0,
                'remember_token' => null,
                'legal_accepted_at' => null,
                'legal_version' => null,
                'legal_accepted_ip' => null,
                'legal_accepted_user_agent' => null,
                'legal_accepted_source' => null,
                'anonymized_at' => now(),
            ])->save();

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

    private function scrubAuditMetadata(int $userId): void
    {
        AuditLog::query()
            ->where(function ($query) use ($userId): void {
                $query->where('actor_user_id', $userId)
                    ->orWhere(function ($nested) use ($userId): void {
                        $nested->where('subject_type', User::class)
                            ->where('subject_id', $userId);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($logs): void {
                foreach ($logs as $log) {
                    $metadata = is_array($log->metadata) ? $log->metadata : [];
                    unset($metadata['email'], $metadata['previous_email'], $metadata['new_email'], $metadata['name']);
                    if (isset($metadata['email']) || isset($metadata['ip'])) {
                        // keep non-PII operational fields only
                    }
                    unset($metadata['user_agent']);
                    $log->forceFill([
                        'metadata' => $metadata === [] ? null : $metadata,
                    ])->save();
                }
            });
    }
}
