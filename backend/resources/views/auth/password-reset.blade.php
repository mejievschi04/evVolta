<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Resetare parola V Charge</title>
    <style>
        body { margin: 0; padding: 24px 16px; background: #f4f5f7; color: #111827; font-family: Arial, sans-serif; line-height: 1.5; }
        main { max-width: 420px; margin: 0 auto; padding: 28px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; }
        h1 { margin: 0 0 16px; font-size: 22px; }
        label { display: block; margin: 14px 0 6px; font-weight: bold; font-size: 14px; }
        input { box-sizing: border-box; width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; }
        button { margin-top: 20px; width: 100%; padding: 12px; border: 0; border-radius: 8px; background: #111827; color: #fff; font-size: 16px; font-weight: bold; cursor: pointer; }
        .error { margin: 0 0 12px; padding: 10px 12px; border-radius: 8px; background: #fef2f2; color: #991b1b; }
        .muted { color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
<main>
    @if (! empty($done))
        <h1>Parola a fost schimbata</h1>
        <p>Te poti autentifica acum in aplicatia V Charge cu parola noua.</p>
    @elseif (! empty($invalid))
        <h1>Link invalid sau expirat</h1>
        <p>Cere un link nou din aplicatia V Charge: ecranul de autentificare, „Ai uitat parola?”.</p>
    @else
        <h1>Seteaza o parola noua</h1>
        @if (! empty($error))
            <p class="error">{{ $error }}</p>
        @endif
        <form method="post" action="{{ url('/api/password/reset') }}">
            <input type="hidden" name="token" value="{{ $token }}">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ $email }}" autocomplete="username" required>
            <label for="password">Parola noua</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
            <label for="password_confirmation">Confirma parola</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
            <p class="muted">Minim 8 caractere, cu litere si cifre.</p>
            <button type="submit">Salveaza parola</button>
        </form>
    @endif
</main>
</body>
</html>
