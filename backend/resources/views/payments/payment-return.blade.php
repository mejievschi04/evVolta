<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #07090f;
            color: #eaf0ff;
            font-family: Inter, Arial, sans-serif;
        }
        .wrap {
            max-width: 440px;
            width: calc(100% - 32px);
            padding: 32px;
            text-align: center;
            border: 1px solid #2a3244;
            border-radius: 20px;
            background: #111725;
        }
        h1 {
            margin: 0 0 12px;
            font-size: 28px;
            color: #ffee00;
        }
        p {
            margin: 0 0 20px;
            color: #9ca8c3;
            line-height: 1.5;
        }
        .details {
            margin: 0 0 22px;
            text-align: left;
            border: 1px solid #2a3244;
            border-radius: 14px;
            overflow: hidden;
            background: #0c111c;
        }
        .details-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-bottom: 1px solid #2a3244;
            font-size: 14px;
        }
        .details-row:last-child { border-bottom: 0; }
        .details-row span { color: #9ca8c3; }
        .details-row strong { color: #eaf0ff; text-align: right; }
        a {
            display: inline-block;
            padding: 12px 18px;
            border-radius: 999px;
            background: #ffee00;
            color: #111725;
            font-weight: 800;
            text-decoration: none;
        }
    </style>
</head>
<body>
<main class="wrap">
    <h1>{{ $title }}</h1>
    @if($status === 'success')
        <p>Plata a fost procesata. Te redirectionam inapoi in aplicatie pentru confirmare.</p>
    @else
        <p>Plata nu a fost finalizata. Poti reveni in aplicatie si incerca din nou.</p>
    @endif

    @if(!empty($payment))
        <div class="details" aria-label="Detalii plata">
            <div class="details-row"><span>Numar comanda</span><strong>{{ $payment['order_number'] }}</strong></div>
            <div class="details-row"><span>Descriere</span><strong>{{ $payment['description'] }}</strong></div>
            <div class="details-row"><span>Suma</span><strong>{{ number_format((float) $payment['amount'], 2, '.', ' ') }} {{ $payment['currency'] }}</strong></div>
            @if(!empty($payment['paid_at']))
                <div class="details-row"><span>Data platii</span><strong>{{ $payment['paid_at'] }}</strong></div>
            @endif
            <div class="details-row"><span>Comerciant</span><strong>{{ $payment['company_name'] }}</strong></div>
            <div class="details-row"><span>Aplicatie</span><strong>{{ $payment['app_name'] }}</strong></div>
        </div>
    @endif

    <a id="open-app" href="{{ $deepLink }}">Deschide aplicatia</a>
</main>
<script>
    (function () {
        var deepLink = @json($deepLink);
        setTimeout(function () {
            window.location.href = deepLink;
        }, 800);
        document.getElementById('open-app').addEventListener('click', function (event) {
            event.preventDefault();
            window.location.href = deepLink;
        });
    })();
</script>
</body>
</html>
