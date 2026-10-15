<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Timestamps are stored in UTC; calendar days and months (reports, invoice periods, documents)
 * follow the business timezone (app.display_timezone, Europe/Chisinau).
 */
class LocalTime
{
    public static function zone(): string
    {
        return (string) config('app.display_timezone', 'Europe/Chisinau');
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::zone());
    }

    /**
     * Same instant, expressed in the business timezone (for formatting and day/month grouping).
     */
    public static function of(?CarbonInterface $moment): ?Carbon
    {
        return $moment ? Carbon::instance($moment)->setTimezone(self::zone()) : null;
    }

    /**
     * Same instant in UTC — use for query bindings, which are formatted without timezone conversion.
     */
    public static function utc(CarbonInterface $moment): Carbon
    {
        return Carbon::instance($moment)->utc();
    }

    /**
     * Local calendar day "Y-m-d" (00:00 business time).
     */
    public static function day(string $date): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $date, self::zone());
    }

    /**
     * First day of a local calendar month "Y-m" (00:00 business time).
     */
    public static function month(string $month): Carbon
    {
        // "!" resets unspecified fields; without it the current day is used and e.g. "2026-02" on the 30th overflows into March.
        return Carbon::createFromFormat('!Y-m', $month, self::zone());
    }
}
