<?php

namespace App\Console\Commands;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Services\OcppService;
use Illuminate\Console\Command;

class OcppReset extends Command
{
    protected $signature = 'ocpp:reset
        {identity : OCPP identity al statiei}
        {--connector=2 : ID conector}
        {--no-remote-start : Nu retrimite RemoteStart dupa reset}';

    protected $description = 'Reset manual pe statie (ChangeAvailability + Soft Reset)';

    public function handle(OcppService $ocppService): int
    {
        $station = Station::query()
            ->where('ocpp_identity', $this->argument('identity'))
            ->first();

        if (! $station) {
            $this->error('Statia nu a fost gasita.');

            return self::FAILURE;
        }

        $connectorId = max(1, (int) $this->option('connector'));
        $session = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->whereNull('ocpp_transaction_id')
            ->where('ocpp_connector_id', $connectorId)
            ->latest('id')
            ->first();

        try {
            $result = $ocppService->manualResetConnector(
                $station,
                $connectorId,
                $session,
                ! $this->option('no-remote-start')
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Reset manual pus in coada pentru %s conector %d: %s',
            $station->ocpp_identity,
            $connectorId,
            implode(', ', $result['command_ids'])
        ));

        if ($session) {
            $this->line(sprintf('Sesiune pending legata: #%d', $session->id));
        }

        return self::SUCCESS;
    }
}
