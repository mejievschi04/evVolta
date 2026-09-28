<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\OcppMessage;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class PrivacyPurgeExpiredData extends Command
{
    protected $signature = 'privacy:purge-expired {--dry-run : Report counts without deleting}';

    protected $description = 'Purge expired operational personal-data logs according to privacy retention policy.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();

        $auditDays = max(7, (int) config('privacy.retention.audit_logs_days', 7));
        $financialAuditDays = max(
            $auditDays,
            (int) config('privacy.retention.audit_logs_financial_days', 365)
        );
        $ocppDays = max(14, (int) config('privacy.retention.ocpp_messages_days', 90));
        $reservationDays = max(30, (int) config('privacy.retention.reservations_days', 730));
        $financialPrefixes = array_values(array_filter(
            (array) config('privacy.audit_financial_action_prefixes', [])
        ));

        $auditCutoff = $now->copy()->subDays($auditDays);
        $financialAuditCutoff = $now->copy()->subDays($financialAuditDays);
        $ocppCutoff = $now->copy()->subDays($ocppDays);
        $reservationCutoff = $now->copy()->subDays($reservationDays);

        $operationalAuditQuery = AuditLog::query()
            ->where('created_at', '<', $auditCutoff)
            ->where(function ($query) use ($financialPrefixes): void {
                if ($financialPrefixes === []) {
                    return;
                }

                $query->where(function ($inner) use ($financialPrefixes): void {
                    foreach ($financialPrefixes as $prefix) {
                        $inner->where('action', 'not like', $prefix.'%');
                    }
                });
            });

        $financialAuditQuery = AuditLog::query()
            ->where('created_at', '<', $financialAuditCutoff)
            ->where(function ($query) use ($financialPrefixes): void {
                if ($financialPrefixes === []) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->where(function ($inner) use ($financialPrefixes): void {
                    foreach ($financialPrefixes as $index => $prefix) {
                        if ($index === 0) {
                            $inner->where('action', 'like', $prefix.'%');
                        } else {
                            $inner->orWhere('action', 'like', $prefix.'%');
                        }
                    }
                });
            });

        $auditCount = (clone $operationalAuditQuery)->count() + (clone $financialAuditQuery)->count();
        $ocppCount = Schema::hasTable('ocpp_messages')
            ? OcppMessage::query()->where('created_at', '<', $ocppCutoff)->count()
            : 0;
        $reservationCount = Reservation::query()
            ->whereIn('status', [
                Reservation::STATUS_CANCELLED,
                Reservation::STATUS_EXPIRED,
                Reservation::STATUS_COMPLETED,
                Reservation::STATUS_NO_SHOW,
            ])
            ->where('updated_at', '<', $reservationCutoff)
            ->count();

        $this->info("Operational audit logs older than {$auditCutoff->toDateString()}: ".(clone $operationalAuditQuery)->count());
        $this->info("Financial audit logs older than {$financialAuditCutoff->toDateString()}: ".(clone $financialAuditQuery)->count());
        $this->info("OCPP messages older than {$ocppCutoff->toDateString()}: {$ocppCount}");
        $this->info("Closed reservations older than {$reservationCutoff->toDateString()}: {$reservationCount}");

        if ($dryRun) {
            $this->warn('Dry run only — nothing deleted.');

            return self::SUCCESS;
        }

        $operationalAuditQuery->delete();
        $financialAuditQuery->delete();

        if (Schema::hasTable('ocpp_messages')) {
            OcppMessage::query()->where('created_at', '<', $ocppCutoff)->delete();
        }

        Reservation::query()
            ->whereIn('status', [
                Reservation::STATUS_CANCELLED,
                Reservation::STATUS_EXPIRED,
                Reservation::STATUS_COMPLETED,
                Reservation::STATUS_NO_SHOW,
            ])
            ->where('updated_at', '<', $reservationCutoff)
            ->delete();

        $this->info("Privacy purge completed (audit total matched: {$auditCount}).");

        return self::SUCCESS;
    }
}
