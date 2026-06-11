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
            max-width: 420px;
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
