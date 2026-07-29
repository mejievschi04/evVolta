<?php

namespace App\Services;

class InvoiceFiscalCalculator
{
    /**
     * @return array{
     *   vat_rate: float,
     *   amount_net: float,
     *   amount_vat: float,
     *   amount_gross: float,
     *   unit_price: float
     * }
     */
    public function breakdown(float $grossOrNet, float $quantity = 1.0, ?float $vatRate = null, ?bool $vatIncluded = null): array
    {
        $vatRate = $vatRate ?? (float) config('invoice.vat_rate', 20);
        $vatIncluded = $vatIncluded ?? (bool) config('invoice.vat_included', true);
        $quantity = max(0.0, $quantity);
        $amount = round(max(0.0, $grossOrNet), 2);

        if ($vatRate <= 0) {
            $unitPrice = $quantity > 0 ? round($amount / $quantity, 4) : $amount;

            return [
                'vat_rate' => 0.0,
                'amount_net' => $amount,
                'amount_vat' => 0.0,
                'amount_gross' => $amount,
                'unit_price' => $unitPrice,
            ];
        }

        $factor = 1 + ($vatRate / 100);

        if ($vatIncluded) {
            $gross = $amount;
            $net = round($gross / $factor, 2);
            $vat = round($gross - $net, 2);
        } else {
            $net = $amount;
            $vat = round($net * ($vatRate / 100), 2);
            $gross = round($net + $vat, 2);
        }

        $unitPrice = $quantity > 0 ? round($net / $quantity, 4) : $net;

        return [
            'vat_rate' => round($vatRate, 2),
            'amount_net' => $net,
            'amount_vat' => $vat,
            'amount_gross' => $gross,
            'unit_price' => $unitPrice,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sellerSnapshot(): array
    {
        $seller = config('invoice.seller', []);

        return [
            'seller_name' => (string) ($seller['name'] ?? config('legal.company_name', 'Volta SRL')),
            'seller_idno' => (string) ($seller['idno'] ?? ''),
            'seller_vat_code' => (string) ($seller['vat_code'] ?? ''),
        ];
    }
}
