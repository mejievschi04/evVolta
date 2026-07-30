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

        $auditDays = max(30, (int) config('privacy.retention.audit_logs_days', 730));
        $ocppDays = max(14, (int) config('privacy.retention.ocpp_messages_days', 90));
        $reservationDays = max(30, (int) config('privacy.retention.reservations_days', 730));

        $auditCutoff = $now->copy()->subDays($auditDays);
        $ocppCutoff = $now->copy()->subDays($ocppDays);
        $reservationCutoff = $now->copy()->subDays($reservationDays);

        $auditCount = AuditLog::query()->where('created_at', '<', $auditCutoff)->count();
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

        $this->info("Audit logs older than {$auditCutoff->toDateString()}: {$auditCount}");
        $this->info("OCPP messages older than {$ocppCutoff->toDateString()}: {$ocppCount}");
        $this->info("Closed reservations older than {$reservationCutoff->toDateString()}: {$reservationCount}");

        if ($dryRun) {
            $this->warn('Dry run only — nothing deleted.');

            return self::SUCCESS;
        }

        AuditLog::query()->where('created_at', '<', $auditCutoff)->delete();

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

        $this->info('Privacy purge completed.');

        return self::SUCCESS;
    }
}
