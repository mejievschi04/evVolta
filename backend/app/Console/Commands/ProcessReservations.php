<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class ProcessReservations extends Command
{
    protected $signature = 'reservations:process';

    protected $description = 'Expire pending reservations and mark no-shows';

    public function handle(ReservationService $reservationService): int
    {
        $result = $reservationService->processDueReservations();
        $this->info(sprintf(
            'Reservations processed: %d expired, %d no-shows.',
            $result['expired'],
            $result['no_shows'],
        ));

        return self::SUCCESS;
    }
}
