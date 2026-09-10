<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>Confirmare plată V CHARGE</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;color:#111827;font-family:Arial,sans-serif;line-height:1.5">
    <main style="max-width:620px;margin:0 auto;padding:28px;background:#ffffff;border:1px solid #e5e7eb">
        <h1 style="margin:0 0 18px;font-size:24px">Plata a fost efectuată cu succes</h1>
        <p>Bună, {{ $topup->user?->name ?: 'client' }}.</p>
        <p>Soldul contului tău V CHARGE a fost alimentat.</p>

        <table role="presentation" style="width:100%;border-collapse:collapse;margin:22px 0">
            <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Număr comandă</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">wallet-topup-{{ $topup->id }}</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Comerciant</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">Volta SRL</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Website / aplicație</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">V CHARGE</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Descriere</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">Alimentare sold V CHARGE × 1</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Sumă</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">{{ number_format((float) $topup->amount, 2, '.', ' ') }} {{ $topup->currency ?: 'MDL' }}</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Data achitării</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">{{ ($topup->paid_at ?: $topup->updated_at)->format('d.m.Y H:i') }}</td></tr>
            @if ($invoice)
                <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Factură</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">{{ $invoice->invoice_number ?: '#'.$invoice->id }}</td></tr>
            @endif
        </table>

        <p>Factura și istoricul tranzacției sunt disponibile în aplicația V CHARGE.</p>
        <p style="margin-bottom:0;color:#6b7280">Ai nevoie de ajutor? Scrie-ne la <a href="mailto:support@volta.md">support@volta.md</a>.</p>
    </main>
</body>
</html>
