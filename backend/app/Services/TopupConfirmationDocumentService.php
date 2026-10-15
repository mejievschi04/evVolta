<?php

namespace App\Services;

use App\Models\WalletTopup;
use App\Support\LocalTime;
use Barryvdh\DomPDF\Facade\Pdf;

class TopupConfirmationDocumentService
{
    public function filename(WalletTopup $topup): string
    {
        return 'confirmare-suplinire-'.$topup->id.'.pdf';
    }

    public function pdf(WalletTopup $topup, ?float $balanceAfter = null): string
    {
        return Pdf::loadHTML($this->html($topup, $balanceAfter))
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @return array{amount: string, balance: string, name: string, email: string, paid_at: string}
     */
    public function data(WalletTopup $topup, ?float $balanceAfter = null): array
    {
        $topup->loadMissing('user:id,name,email,wallet_balance,currency');

        $currency = $topup->currency ?: ($topup->user?->currency ?: 'MDL');
        $balance = $balanceAfter ?? (float) ($topup->user?->wallet_balance ?? 0);

        return [
            'amount' => number_format((float) $topup->amount, 2, '.', ' ').' '.$currency,
            'balance' => number_format($balance, 2, '.', ' ').' '.$currency,
            'name' => (string) ($topup->user?->name ?? ''),
            'email' => (string) ($topup->user?->email ?? ''),
            'paid_at' => LocalTime::of($topup->paid_at ?: $topup->updated_at ?: now())->format('d.m.Y H:i'),
        ];
    }

    public function html(WalletTopup $topup, ?float $balanceAfter = null): string
    {
        $data = array_map('e', $this->data($topup, $balanceAfter));
        $csp = e((string) config('security.csp_document'));

        return <<<HTML
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="{$csp}">
<title>Confirmare suplinire cont</title>
<style>
    body { font-family: DejaVu Sans, Arial, sans-serif; color: #111827; font-size: 12px; margin: 32px; }
    h1 { font-size: 20px; margin: 0 0 24px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 10px 0; border-bottom: 1px solid #e5e7eb; }
    td.label { color: #6b7280; width: 40%; }
    td.value { font-weight: bold; text-align: right; }
</style>
</head>
<body>
    <h1>Confirmare suplinire cont</h1>
    <table>
        <tr><td class="label">Sumă</td><td class="value">{$data['amount']}</td></tr>
        <tr><td class="label">Sold cont</td><td class="value">{$data['balance']}</td></tr>
        <tr><td class="label">Nume Prenume</td><td class="value">{$data['name']}</td></tr>
        <tr><td class="label">Email</td><td class="value">{$data['email']}</td></tr>
        <tr><td class="label">Data și ora</td><td class="value">{$data['paid_at']}</td></tr>
    </table>
</body>
</html>
HTML;
    }
}
