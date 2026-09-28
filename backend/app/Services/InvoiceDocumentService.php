<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceDocumentService
{
    public function __construct(
        private readonly InvoiceFiscalCalculator $fiscalCalculator,
    ) {
    }

    public function filename(Invoice $invoice): string
    {
        $number = $invoice->invoice_number ?: 'invoice-'.$invoice->id;

        return str($number)->slug()->append('.pdf')->toString();
    }

    public function pdf(Invoice $invoice): string
    {
        return Pdf::loadHTML($this->html($invoice))
            ->setPaper('a4', 'landscape')
            ->output();
    }

    public function html(Invoice $invoice): string
    {
        $invoice->loadMissing(['user:id,name,email,currency', 'sourceSession.station:id,name']);

        $seller = $this->sellerData($invoice);
        $buyer = $this->buyerData($invoice);
        $fiscal = $this->fiscalAmounts($invoice);
        $line = $this->lineData($invoice, $fiscal);

        $docLabel = e((string) config('invoice.document_label', 'Factura'));
        $number = e($invoice->invoice_number ?: str_pad((string) $invoice->id, 7, '0', STR_PAD_LEFT));
        $date = e(($invoice->issued_at ?? $invoice->created_at)?->format('d.m.Y')
            ?? ($invoice->period_end?->format('d.m.Y') ?? now()->format('d.m.Y')));

        $qty = e(number_format((float) $line['quantity'], 3, ',', ' '));
        $unit = e($line['unit']);
        $unitPrice = e(number_format((float) $line['unit_price'], 5, ',', ' '));
        $vatRate = e(number_format((float) $fiscal['vat_rate'], 0, ',', ' '));
        $lineNet = e(number_format((float) $fiscal['amount_net'], 2, ',', ' '));
        $lineVat = e(number_format((float) $fiscal['amount_vat'], 2, ',', ' '));
        $lineGross = e(number_format((float) $fiscal['amount_gross'], 2, ',', ' '));
        $description = e($line['description']);

        $sellerBlock = $this->partyBlock($seller);
        $buyerBlock = $this->partyBlock($buyer);
        $missingSeller = $seller['idno'] === ''
            ? '<p class="warn">Completeaza IDNO / adresa furnizorului in configuratia facturii (.env).</p>'
            : '';

        $csp = e((string) config('security.csp_document'));

        return <<<HTML
<!doctype html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta http-equiv="Content-Security-Policy" content="{$csp}">
  <title>{$docLabel} {$number}</title>
  <style>
    body { margin: 0; padding: 18px; color: #111; font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; }
    h1 { margin: 0 0 4px; font-size: 16px; text-transform: uppercase; }
    .meta { margin-bottom: 12px; }
    .meta strong { font-size: 13px; }
    .parties { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .parties td { width: 50%; vertical-align: top; border: 1px solid #111; padding: 8px 10px; }
    .parties h2 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; }
    .parties p { margin: 0 0 3px; line-height: 1.35; }
    table.lines { width: 100%; border-collapse: collapse; }
    table.lines th, table.lines td { border: 1px solid #111; padding: 6px 5px; vertical-align: top; }
    table.lines th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; }
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .totals td { font-weight: 700; background: #f9fafb; }
    .warn { color: #b45309; margin: 4px 0 0; font-size: 10px; }
  </style>
</head>
<body>
  <h1>{$docLabel}</h1>
  <p class="meta"><strong>Nr. {$number}</strong> &nbsp;|&nbsp; Data: {$date}</p>
  {$missingSeller}

  <table class="parties">
    <tr>
      <td>
        <h2>Furnizor</h2>
        {$sellerBlock}
      </td>
      <td>
        <h2>Cumparator / Beneficiar</h2>
        {$buyerBlock}
      </td>
    </tr>
  </table>

  <table class="lines">
    <thead>
      <tr>
        <th style="width:28%">Denumirea serviciului</th>
        <th class="center" style="width:8%">U.M.</th>
        <th class="num" style="width:10%">Cantitate</th>
        <th class="num" style="width:12%">Pret unitar<br>fara TVA</th>
        <th class="num" style="width:12%">Valoare<br>fara TVA</th>
        <th class="center" style="width:8%">Cota<br>TVA %</th>
        <th class="num" style="width:10%">Suma TVA</th>
        <th class="num" style="width:12%">Valoare<br>cu TVA</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>{$description}</td>
        <td class="center">{$unit}</td>
        <td class="num">{$qty}</td>
        <td class="num">{$unitPrice}</td>
        <td class="num">{$lineNet}</td>
        <td class="center">{$vatRate}</td>
        <td class="num">{$lineVat}</td>
        <td class="num">{$lineGross}</td>
      </tr>
      <tr class="totals">
        <td colspan="4">Total</td>
        <td class="num">{$lineNet}</td>
        <td class="center">X</td>
        <td class="num">{$lineVat}</td>
        <td class="num">{$lineGross}</td>
      </tr>
    </tbody>
  </table>
</body>
</html>
HTML;
    }

    public function emailBody(Invoice $invoice): string
    {
        $invoice->loadMissing('user:id,name,email,currency');
        $number = e($invoice->invoice_number ?: '#'.$invoice->id);
        $name = e($invoice->user?->name ?: 'client');
        $fiscal = $this->fiscalAmounts($invoice);
        $amount = e(number_format((float) $fiscal['amount_gross'], 2, '.', ' ').' '.($invoice->currency ?: 'MDL'));
        $sellerName = e($this->sellerData($invoice)['name']);

        return <<<HTML
<p>Buna, {$name},</p>
<p>Factura {$number} este atasata acestui email.</p>
<p><strong>Total de plata:</strong> {$amount}</p>
<p>Multumim,<br>{$sellerName}</p>
HTML;
    }

    /**
     * @return array{name: string, address: string, idno: string, vat_code: string, iban: string, bank: string, phone: string, email: string}
     */
    private function sellerData(Invoice $invoice): array
    {
        $cfg = config('invoice.seller', []);

        return [
            'name' => (string) ($invoice->seller_name ?: ($cfg['name'] ?? 'V CHARGE')),
            'address' => (string) ($cfg['address'] ?? ''),
            'idno' => (string) ($invoice->seller_idno ?: ($cfg['idno'] ?? '')),
            'vat_code' => (string) ($invoice->seller_vat_code ?: ($cfg['vat_code'] ?? '')),
            'iban' => (string) ($cfg['iban'] ?? ''),
            'bank' => (string) ($cfg['bank'] ?? ''),
            'phone' => (string) ($cfg['phone'] ?? ''),
            'email' => (string) ($cfg['email'] ?? ''),
        ];
    }

    /**
     * @return array{name: string, address: string, idno: string, vat_code: string, iban: string, bank: string, phone: string, email: string}
     */
    private function buyerData(Invoice $invoice): array
    {
        return [
            'name' => (string) ($invoice->buyer_name ?: $invoice->user?->name ?: '-'),
            'address' => '',
            'idno' => (string) ($invoice->buyer_idno ?: ''),
            'vat_code' => '',
            'iban' => '',
            'bank' => '',
            'phone' => '',
            'email' => (string) ($invoice->buyer_email ?: $invoice->user?->email ?: '-'),
        ];
    }

    /**
     * @return array{vat_rate: float, amount_net: float, amount_vat: float, amount_gross: float}
     */
    private function fiscalAmounts(Invoice $invoice): array
    {
        if ($invoice->amount_net !== null && $invoice->amount_vat !== null) {
            return [
                'vat_rate' => (float) ($invoice->vat_rate ?? config('invoice.vat_rate', 20)),
                'amount_net' => (float) $invoice->amount_net,
                'amount_vat' => (float) $invoice->amount_vat,
                'amount_gross' => (float) $invoice->total_amount,
            ];
        }

        $quantity = (float) ($invoice->quantity ?? ($invoice->total_kwh > 0 ? $invoice->total_kwh : 1));

        return $this->fiscalCalculator->breakdown((float) $invoice->total_amount, max(0.001, $quantity));
    }

    /**
     * @param  array{vat_rate: float, amount_net: float, amount_vat: float, amount_gross: float}  $fiscal
     * @return array{description: string, unit: string, quantity: float, unit_price: float}
     */
    private function lineData(Invoice $invoice, array $fiscal): array
    {
        $quantity = (float) ($invoice->quantity ?? 0);
        if ($quantity <= 0) {
            $quantity = (float) $invoice->total_kwh > 0 ? (float) $invoice->total_kwh : 1.0;
        }

        $unit = (string) ($invoice->unit ?: ((float) $invoice->total_kwh > 0 ? 'kWh' : 'buc'));
        $unitPrice = $invoice->unit_price !== null
            ? (float) $invoice->unit_price
            : ($quantity > 0 ? round($fiscal['amount_net'] / $quantity, 5) : $fiscal['amount_net']);

        $description = (string) ($invoice->line_description ?: match ((string) $invoice->invoice_type) {
            'session' => 'Servicii de incarcare vehicul electric',
            'wallet_topup' => 'Alimentare sold preplatit pentru servicii de incarcare EV',
            default => 'Servicii de incarcare EV',
        });

        return [
            'description' => $description,
            'unit' => $unit,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ];
    }

    /**
     * @param  array{name: string, address: string, idno: string, vat_code: string, iban: string, bank: string, phone: string, email: string}  $party
     */
    private function partyBlock(array $party): string
    {
        $lines = [
            '<p><strong>'.e($party['name']).'</strong></p>',
        ];

        if ($party['address'] !== '') {
            $lines[] = '<p>'.e($party['address']).'</p>';
        }
        if ($party['idno'] !== '') {
            $vat = $party['vat_code'] !== '' ? ' / '.e($party['vat_code']) : '';
            $lines[] = '<p>c.f. / nr.TVA: '.e($party['idno']).$vat.'</p>';
        }
        if ($party['email'] !== '') {
            $lines[] = '<p>Email: '.e($party['email']).'</p>';
        }
        if ($party['phone'] !== '') {
            $lines[] = '<p>Tel: '.e($party['phone']).'</p>';
        }

        return implode("\n", $lines);
    }
}
