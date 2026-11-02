<?php

namespace App\Console\Commands;

use App\Models\Station;
use Illuminate\Console\Command;

class ClearStaleLiveMeterEnergy extends Command
{
    protected $signature = 'ocpp:clear-stale-meter-energy';

    protected $description = 'Sterge energy_kwh ramas in ocpp_configuration (connectors si cheia veche connector)';

    public function handle(): int
    {
        $cleared = 0;
        Station::query()->orderBy('id')->each(function (Station $station) use (&$cleared): void {
            $before = $station->ocpp_configuration;
            $station->clearLiveMeterEnergy();
            if ($station->fresh()->ocpp_configuration !== $before) {
                $cleared++;
            }
        });

        $this->info(sprintf('Statii cu contor vechi curatat: %d.', $cleared));

        return self::SUCCESS;
    }
}
