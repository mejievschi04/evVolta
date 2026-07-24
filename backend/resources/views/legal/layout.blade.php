<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ $companyName }}</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0b0d08;
            --card: #12150f;
            --text: #eef3e6;
            --muted: #9eb08a;
            --accent: #ffee00;
            --border: rgba(255, 255, 255, 0.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            line-height: 1.6;
        }
        main {
            max-width: 760px;
            margin: 0 auto;
            padding: 28px 20px 48px;
        }
        .badge {
            display: inline-block;
            margin-bottom: 10px;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid rgba(255, 238, 0, 0.28);
            color: var(--accent);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 28px;
            line-height: 1.15;
        }
        .meta {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 24px;
        }
        section {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 18px 18px 6px;
            margin-bottom: 14px;
        }
        h2 {
            margin: 0 0 10px;
            font-size: 17px;
            color: var(--accent);
        }
        p, li {
            color: #d8e7b8;
            font-size: 14px;
        }
        ul {
            padding-left: 18px;
            margin-top: 0;
        }
        a {
            color: var(--accent);
        }
        footer {
            margin-top: 24px;
            color: var(--muted);
            font-size: 12px;
        }
    </style>
</head>
<body>
    <main>
        <span class="badge">{{ $companyName }}</span>
        <h1>@yield('title')</h1>
        <p class="meta">Versiune {{ $legalVersion }} · in vigoare de la {{ $effectiveDate }}</p>
        @yield('content')
        <footer>
            Contact: <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
            @if(!empty($supportPhone))
                · {{ $supportPhone }}
            @endif
        </footer>
    </main>
</body>
</html>
