<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\OcppCommand;
use App\Models\OcppMessage;
use App\Models\RegistrationRequest;
use App\Models\Station;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeTestData extends Command
{
    protected $signature = 'volta:purge-test-data';

    protected $description = 'Sterge sesiuni, facturi si mesaje OCPP de test. Pastreaza utilizatorii si statiile.';

    public function handle(): int
    {
        DB::transaction(function (): void {
            AuditLog::query()->delete();
            OcppMessage::query()->delete();
            OcppCommand::query()->delete();
            Invoice::query()->delete();
            ChargingSession::query()->delete();
            RegistrationRequest::query()->delete();

            Station::query()->update([
                'status' => Station::STATUS_AVAILABLE,
                'ocpp_connection_status' => Station::OCPP_CONNECTION_NOT_CONFIGURED,
                'last_heartbeat_at' => null,
                'last_ocpp_message_at' => null,
                'meter_value_kwh' => null,
                'ocpp_configuration' => null,
            ]);
        });

        $this->info('Datele tranzactionale de test au fost sterse. Utilizatorii si statiile au ramas neschimbate.');

        return self::SUCCESS;
    }
}
