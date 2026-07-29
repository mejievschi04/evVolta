<?php

namespace App\Support;

class MoneyToWordsRo
{
    /**
     * Converteste o suma in MDL in litere (ex: "doua sute trei lei si 45 bani").
     */
    public static function convert(float $amount, string $currency = 'MDL'): string
    {
        $amount = round($amount, 2);
        $lei = (int) floor($amount);
        $bani = (int) round(($amount - $lei) * 100);

        $major = match (strtoupper($currency)) {
            'EUR' => ['euro', 'euro'],
            'USD' => ['dolar', 'dolari'],
            default => ['leu', 'lei'],
        };
        $minor = match (strtoupper($currency)) {
            'EUR' => ['cent', 'centi'],
            'USD' => ['cent', 'centi'],
            default => ['ban', 'bani'],
        };

        $leiWord = self::integerToWords($lei);
        $majorLabel = $lei === 1 ? $major[0] : $major[1];
        $minorLabel = $bani === 1 ? $minor[0] : $minor[1];

        if ($bani === 0) {
            return trim($leiWord.' '.$majorLabel);
        }

        return trim($leiWord.' '.$majorLabel.' si '.self::integerToWords($bani).' '.$minorLabel);
    }

    private static function integerToWords(int $value): string
    {
        if ($value < 0) {
            return 'minus '.self::integerToWords(abs($value));
        }

        if ($value === 0) {
            return 'zero';
        }

        $units = ['', 'unu', 'doi', 'trei', 'patru', 'cinci', 'sase', 'sapte', 'opt', 'noua'];
        $teens = ['zece', 'unsprezece', 'doisprezece', 'treisprezece', 'paisprezece', 'cincisprezece', 'saisprezece', 'saptesprezece', 'optsprezece', 'nouasprezece'];
        $tens = ['', '', 'douazeci', 'treizeci', 'patruzeci', 'cincizeci', 'saizeci', 'saptezeci', 'optzeci', 'nouazeci'];
        $hundreds = ['', 'o suta', 'doua sute', 'trei sute', 'patru sute', 'cinci sute', 'sase sute', 'sapte sute', 'opt sute', 'noua sute'];

        if ($value < 10) {
            return $units[$value];
        }

        if ($value < 20) {
            return $teens[$value - 10];
        }

        if ($value < 100) {
            $t = intdiv($value, 10);
            $u = $value % 10;
            if ($u === 0) {
                return $tens[$t];
            }

            return $tens[$t].' si '.$units[$u];
        }

        if ($value < 1000) {
            $h = intdiv($value, 100);
            $rest = $value % 100;
            if ($rest === 0) {
                return $hundreds[$h];
            }

            return $hundreds[$h].' '.self::integerToWords($rest);
        }

        if ($value < 1000000) {
            $thousands = intdiv($value, 1000);
            $rest = $value % 1000;
            $thousandLabel = $thousands === 1 ? 'o mie' : self::integerToWords($thousands).' mii';
            if ($rest === 0) {
                return $thousandLabel;
            }

            return $thousandLabel.' '.self::integerToWords($rest);
        }

        return (string) $value;
    }
}
