<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>Confirmare suplinire cont V Charge</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;color:#111827;font-family:Arial,sans-serif;line-height:1.5">
    <main style="max-width:620px;margin:0 auto;padding:28px;background:#ffffff;border:1px solid #e5e7eb">
        <h1 style="margin:0 0 18px;font-size:24px">Confirmare suplinire cont</h1>
        <p>Bună, {{ $details['name'] ?: 'client' }}.</p>
        <p>Soldul contului tău V Charge a fost suplinit.</p>

        <table role="presentation" style="width:100%;border-collapse:collapse;margin:22px 0">
            <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Sumă</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">{{ $details['amount'] }}</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Sold cont</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">{{ $details['balance'] }}</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Nume Prenume</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">{{ $details['name'] }}</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Email</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">{{ $details['email'] }}</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;color:#6b7280">Data și ora</td><td style="padding:9px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold">{{ $details['paid_at'] }}</td></tr>
        </table>

        <p>Confirmarea suplinirii în format PDF este atașată acestui email.</p>
        <p style="margin-bottom:0;color:#6b7280">Ai nevoie de ajutor? Scrie-ne la <a href="mailto:support@volta.md">support@volta.md</a>.</p>
    </main>
</body>
</html>
