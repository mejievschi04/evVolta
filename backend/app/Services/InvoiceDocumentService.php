<?php

namespace App\Services;

use App\Models\Invoice;

class InvoiceDocumentService
{
    public function filename(Invoice $invoice): string
    {
        $number = $invoice->invoice_number ?: 'invoice-' . $invoice->id;

        return str($number)->slug()->append('.html')->toString();
    }

    public function html(Invoice $invoice): string
    {
        $invoice->loadMissing('user:id,name,email,currency');

        $number = e($invoice->invoice_number ?: '#' . $invoice->id);
        $userName = e($invoice->user?->name ?: '-');
        $userEmail = e($invoice->user?->email ?: '-');
        $currency = e($invoice->currency ?: $invoice->user?->currency ?: 'MDL');
        $status = e($this->statusLabel((string) $invoice->status));
        $type = e($invoice->invoice_type === 'session' ? 'Factura sesiune' : 'Factura lunara');
        $period = e(trim(($invoice->period_start?->toDateString() ?? '-') . ' - ' . ($invoice->period_end?->toDateString() ?? '-')));
        $month = e($invoice->month ?: '-');
        $kwh = number_format((float) $invoice->total_kwh, 2, '.', ' ');
        $amount = number_format((float) $invoice->total_amount, 2, '.', ' ');
        $sessionsCount = (int) $invoice->sessions_count;
        $issuedAt = e($invoice->created_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i'));
        $paidAt = e($invoice->paid_at?->format('Y-m-d H:i') ?? '-');

        return <<<HTML
<!doctype html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <title>Factura {$number}</title>
  <style>
    body { margin: 0; padding: 42px; color: #17201f; font-family: Arial, sans-serif; background: #f6f7f2; }
    .invoice { max-width: 860px; margin: 0 auto; padding: 34px; border: 1px solid #d5dccf; background: #fff; }
    .header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 3px solid #dfff00; padding-bottom: 22px; }
    h1 { margin: 0; font-size: 34px; }
    h2 { margin: 28px 0 12px; font-size: 18px; }
    .muted { color: #68736c; }
    .pill { display: inline-block; padding: 7px 12px; border-radius: 999px; background: #17201f; color: #dfff00; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 13px 0; border-bottom: 1px solid #e4e9df; text-align: left; }
    th:last-child, td:last-child { text-align: right; }
    .total { margin-top: 26px; padding: 18px; background: #f1f5e8; text-align: right; font-size: 24px; font-weight: 800; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 22px; }
    @media print { body { background: #fff; padding: 0; } .invoice { border: 0; } }
  </style>
</head>
<body>
  <main class="invoice">
    <section class="header">
      <div>
        <h1>Volta EV Charging</h1>
        <p class="muted">Factura electronica generata din backoffice.</p>
      </div>
      <div>
        <p><strong>{$type}</strong></p>
        <p class="pill">{$status}</p>
      </div>
    </section>

    <section class="grid">
      <div>
        <h2>Factura</h2>
        <p><strong>Numar:</strong> {$number}</p>
        <p><strong>Luna:</strong> {$month}</p>
        <p><strong>Perioada:</strong> {$period}</p>
        <p><strong>Emisa:</strong> {$issuedAt}</p>
        <p><strong>Platita:</strong> {$paidAt}</p>
      </div>
      <div>
        <h2>Client</h2>
        <p><strong>Nume:</strong> {$userName}</p>
        <p><strong>Email:</strong> {$userEmail}</p>
        <p><strong>Moneda:</strong> {$currency}</p>
      </div>
    </section>

    <h2>Consum</h2>
    <table>
      <thead>
        <tr>
          <th>Descriere</th>
          <th>Sesiuni</th>
          <th>kWh</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>{$type}</td>
          <td>{$sessionsCount}</td>
          <td>{$kwh}</td>
          <td>{$amount} {$currency}</td>
        </tr>
      </tbody>
    </table>

    <div class="total">Total: {$amount} {$currency}</div>
  </main>
</body>
</html>
HTML;
    }

    public function emailBody(Invoice $invoice): string
    {
        $invoice->loadMissing('user:id,name,email,currency');
        $number = e($invoice->invoice_number ?: '#' . $invoice->id);
        $name = e($invoice->user?->name ?: 'client');
        $amount = e(number_format((float) $invoice->total_amount, 2, '.', ' ') . ' ' . ($invoice->currency ?: 'MDL'));

        return <<<HTML
<p>Buna, {$name},</p>
<p>Factura {$number} este atasata acestui email.</p>
<p><strong>Total:</strong> {$amount}</p>
<p>Multumim,<br>Volta EV Charging</p>
HTML;
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
