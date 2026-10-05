<?php

namespace App\Http\Controllers;

use PDOException;
use Throwable;

abstract class Controller
{
    /**
     * Erorile de business sunt RuntimeException cu cod HTTP (404, 422...). Erorile de baza
     * de date (QueryException extinde PDOException, deci tot RuntimeException) au ca cod un
     * SQLSTATE si mesajul contine SQL: nu le transformam in raspuns, le lasam la handler-ul
     * global (500 generic + log).
     */
    protected function rethrowIfInfrastructure(Throwable $exception): void
    {
        if ($exception instanceof PDOException) {
            throw $exception;
        }
    }

    /**
     * Codul exceptiei ca status HTTP, doar daca e un status de eroare valid.
     */
    protected function httpStatusFor(Throwable $exception, int $fallback = 422): int
    {
        $code = $exception->getCode();

        return is_int($code) && $code >= 400 && $code <= 599 ? $code : $fallback;
    }
}
