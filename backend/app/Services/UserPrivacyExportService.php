<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\StationFavorite;
use App\Models\User;
use App\Models\WalletRefund;
use App\Models\WalletTopup;
use RuntimeException;

class UserPrivacyExportService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, ?User $actor = null): array
    {
        if ($user->isAnonymized()) {
            throw new RuntimeException('Contul a fost sters si datele personale au fost anonimizate.', 410);
        }

        $this->auditLogService->record(
            action: 'privacy.export',
            actor: $actor ?? $user,
            subjectType: User::class,
            subjectId: $user->id,
            metadata: [
                'format' => 'json',
            ],
        );

        $userData = $user->only([
            'id',
            'name',
            'first_name',
            'last_name',
            'email',
            'phone',
            'currency',
            'account_type',
            'wallet_balance',
            'legal_accepted_at',
            'legal_version',
            'legal_accepted_source',
            'created_at',
            'updated_at',
        ]);

        return [
            'exported_at' => now()->toIso8601String(),
            'format' => 'application/json',
            'app_name' => (string) config('legal.app_name', 'V CHARGE'),
            'controller' => (string) config('legal.company_name'),
            'legal_version' => (string) config('legal.version'),
            'rights' => [
                'access' => true,
                'portability' => true,
                'erasure' => 'Disponibil din Setari cont, cu pastrarea evidentiilor fiscale anonimizate.',
                'rectification' => 'Disponibil din Setari cont.',
                'complaint' => config('privacy.supervisory_authority'),
            ],
            'user' => $userData,
            'sessions' => ChargingSession::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->get(),
            'invoices' => Invoice::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->get(),
            'reservations' => Reservation::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->get(),
            'wallet_topups' => WalletTopup::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->get(),
            'wallet_refunds' => WalletRefund::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->get(),
            'station_favorites' => StationFavorite::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->get(),
            'audit_logs' => AuditLog::query()
                ->where(function ($query) use ($user): void {
                    $query->where('actor_user_id', $user->id)
                        ->orWhere(function ($nested) use ($user): void {
                            $nested->where('subject_type', User::class)
                                ->where('subject_id', $user->id);
                        });
                })
                ->orderByDesc('id')
                ->limit(500)
                ->get()
                ->map(function (AuditLog $log) {
                    $metadata = is_array($log->metadata) ? $log->metadata : [];
                    unset($metadata['email'], $metadata['previous_email'], $metadata['new_email'], $metadata['user_agent']);

                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'created_at' => $log->created_at,
                        'metadata' => $metadata,
                    ];
                })
                ->values(),
        ];
    }
}
