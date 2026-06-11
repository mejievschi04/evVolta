<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\OcppService;
use Illuminate\Console\Command;

class OcppForceStop extends Command
{
    protected $signature = 'ocpp:force-stop
        {identity : OCPP identity al statiei}
        {--connector=2 : ID conector}
        {--no-reset : Nu trimite Reset Soft (implicit se trimite)}';

    protected $description = 'Oprire fortata pe statie (RemoteStop + ChangeAvailability)';

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

        try {
            $result = $ocppService->forceStopConnector(
                $station,
                $connectorId,
                ! $this->option('no-reset')
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Comenzi puse in coada pentru %s conector %d: %s',
            $station->ocpp_identity,
            $connectorId,
            implode(', ', $result['command_ids'])
        ));

        return self::SUCCESS;
    }
}
