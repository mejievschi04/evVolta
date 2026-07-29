<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\MoneyToWordsRo;

class InvoiceDocumentService
{
    public function __construct(
        private readonly InvoiceFiscalCalculator $fiscalCalculator,
    ) {
    }

    public function filename(Invoice $invoice): string
    {
        $number = $invoice->invoice_number ?: 'invoice-'.$invoice->id;

        return str($number)->slug()->append('.html')->toString();
    }

    public function html(Invoice $invoice): string
    {
        $invoice->loadMissing(['user:id,name,email,currency', 'sourceSession.station:id,name']);

        $seller = $this->sellerData($invoice);
        $buyer = $this->buyerData($invoice);
        $fiscal = $this->fiscalAmounts($invoice);
        $line = $this->lineData($invoice, $fiscal);

        $docLabel = e((string) config('invoice.document_label', 'Factura'));
        $number = e($invoice->invoice_number ?: '#'.$invoice->id);
        $series = e($invoice->series ?: (string) config('invoice.series', 'VE'));
        $currency = e($invoice->currency ?: $invoice->user?->currency ?: 'MDL');
        $status = e($this->statusLabel((string) $invoice->status));
        $issuedAt = e(($invoice->issued_at ?? $invoice->created_at)?->format('d.m.Y H:i') ?? now()->format('d.m.Y H:i'));
        $deliveryDate = e(($invoice->period_end ?? $invoice->issued_at ?? $invoice->created_at)?->format('d.m.Y') ?? '-');
        $paidAt = e($invoice->paid_at?->format('d.m.Y H:i') ?? '—');
        $notes = e((string) config('invoice.notes', ''));
        $amountWords = e(MoneyToWordsRo::convert((float) $fiscal['amount_gross'], (string) $currency));

        $sellerBlock = $this->partyBlock($seller);
        $buyerBlock = $this->partyBlock($buyer);

        $qty = e(number_format((float) $line['quantity'], 3, '.', ' '));
        $unit = e($line['unit']);
        $unitPrice = e(number_format((float) $line['unit_price'], 4, '.', ' '));
        $vatRate = e(number_format((float) $fiscal['vat_rate'], 2, '.', ' '));
        $lineNet = e(number_format((float) $fiscal['amount_net'], 2, '.', ' '));
        $lineVat = e(number_format((float) $fiscal['amount_vat'], 2, '.', ' '));
        $lineGross = e(number_format((float) $fiscal['amount_gross'], 2, '.', ' '));
        $description = e($line['description']);

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
    :root { color-scheme: light; }
    body { margin: 0; padding: 28px; color: #111827; font-family: "Segoe UI", Arial, sans-serif; background: #f3f4f6; }
    .sheet { max-width: 920px; margin: 0 auto; padding: 28px 30px; background: #fff; border: 1px solid #d1d5db; }
    .top { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; border-bottom: 2px solid #111827; padding-bottom: 16px; }
    .brand { font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
    .muted { color: #6b7280; font-size: 12px; }
    .meta { text-align: right; font-size: 13px; line-height: 1.55; }
    .badge { display: inline-block; margin-top: 6px; padding: 4px 10px; border: 1px solid #111827; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin: 20px 0; }
    .box { border: 1px solid #e5e7eb; padding: 14px 16px; min-height: 140px; }
    .box h2 { margin: 0 0 10px; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: #6b7280; }
    .box p { margin: 0 0 4px; font-size: 13px; line-height: 1.45; }
    .box strong.name { font-size: 15px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 12.5px; }
    th, td { border: 1px solid #d1d5db; padding: 9px 8px; vertical-align: top; }
    th { background: #f9fafb; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
    td.num, th.num { text-align: right; white-space: nowrap; }
    .totals { width: 320px; margin-left: auto; margin-top: 16px; }
    .totals table { margin: 0; }
    .totals .grand td { font-weight: 800; background: #f9fafb; }
    .words { margin-top: 16px; font-size: 13px; }
    .notes { margin-top: 18px; padding-top: 12px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 11px; line-height: 1.5; }
    .warn { color: #b45309; font-size: 12px; margin: 8px 0 0; }
    .legal-ref { margin-top: 8px; font-size: 11px; color: #6b7280; }
    @media print { body { background: #fff; padding: 0; } .sheet { border: 0; } }
    @media (max-width: 720px) { .parties, .top { grid-template-columns: 1fr; display: grid; } .meta { text-align: left; } }
  </style>
</head>
<body>
  <main class="sheet">
    <section class="top">
      <div>
        <div class="brand">{$seller['name']}</div>
        <p class="muted">{$docLabel} · document primar electronic</p>
        {$missingSeller}
      </div>
      <div class="meta">
        <div><strong>{$docLabel} nr.</strong> {$number}</div>
        <div><strong>Seria:</strong> {$series}</div>
        <div><strong>Data eliberarii:</strong> {$issuedAt}</div>
        <div><strong>Data livrarii:</strong> {$deliveryDate}</div>
        <div><strong>Platita:</strong> {$paidAt}</div>
        <div class="badge">{$status}</div>
      </div>
    </section>

    <section class="parties">
      <div class="box">
        <h2>Furnizor</h2>
        {$sellerBlock}
      </div>
      <div class="box">
        <h2>Cumparator / Beneficiar</h2>
        {$buyerBlock}
      </div>
    </section>

    <table>
      <thead>
        <tr>
          <th>Nr.</th>
          <th>Denumirea serviciului / marfii</th>
          <th class="num">U.M.</th>
          <th class="num">Cantitate</th>
          <th class="num">Pret unitar fara TVA</th>
          <th class="num">Cota TVA %</th>
          <th class="num">Suma fara TVA</th>
          <th class="num">Suma TVA</th>
          <th class="num">Total</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>1</td>
          <td>{$description}</td>
          <td class="num">{$unit}</td>
          <td class="num">{$qty}</td>
          <td class="num">{$unitPrice}</td>
          <td class="num">{$vatRate}</td>
          <td class="num">{$lineNet}</td>
          <td class="num">{$lineVat}</td>
          <td class="num">{$lineGross} {$currency}</td>
        </tr>
      </tbody>
    </table>

    <div class="totals">
      <table>
        <tr>
          <td>Total fara TVA</td>
          <td class="num">{$lineNet} {$currency}</td>
        </tr>
        <tr>
          <td>Total TVA</td>
          <td class="num">{$lineVat} {$currency}</td>
        </tr>
        <tr class="grand">
          <td>Total de plata</td>
          <td class="num">{$lineGross} {$currency}</td>
        </tr>
      </table>
    </div>

    <p class="words"><strong>Total de plata (in litere):</strong> {$amountWords}</p>
    <p class="legal-ref">Structura documentului urmeaza elementele prevazute de art. 117 alin. (2) din Codul fiscal al Republicii Moldova (date identificare, serie/numar, data, cantitate, pret fara TVA, cota TVA, total livrare, total TVA).</p>

    <div class="notes">{$notes}</div>
  </main>
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
            : ($quantity > 0 ? round($fiscal['amount_net'] / $quantity, 4) : $fiscal['amount_net']);

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
            '<p><strong class="name">'.e($party['name']).'</strong></p>',
        ];

        if ($party['address'] !== '') {
            $lines[] = '<p>'.e($party['address']).'</p>';
        }
        if ($party['idno'] !== '') {
            $lines[] = '<p><strong>IDNO:</strong> '.e($party['idno']).'</p>';
        }
        if ($party['vat_code'] !== '') {
            $lines[] = '<p><strong>Cod TVA:</strong> '.e($party['vat_code']).'</p>';
        }
        if ($party['email'] !== '') {
            $lines[] = '<p><strong>Email:</strong> '.e($party['email']).'</p>';
        }
        if ($party['phone'] !== '') {
            $lines[] = '<p><strong>Tel:</strong> '.e($party['phone']).'</p>';
        }
        if ($party['iban'] !== '') {
            $bank = $party['bank'] !== '' ? ' ('.e($party['bank']).')' : '';
            $lines[] = '<p><strong>IBAN:</strong> '.e($party['iban']).$bank.'</p>';
        }

        return implode("\n", $lines);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Platita',
            'unpaid' => 'Neplatita',
            default => $status ?: '-',
        };
    }
}
