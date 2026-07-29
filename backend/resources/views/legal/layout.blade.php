<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta http-equiv="Content-Security-Policy" content="{{ config('security.csp_document') }}">
    <title>@yield('title') · {{ $appName ?? $companyName }}</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #05070d;
            --bg-elevated: #0c1019;
            --card: rgba(18, 24, 39, 0.92);
            --text: #f4f7ff;
            --muted: #8b97b5;
            --soft: #c5cee4;
            --accent: #ffee00;
            --accent-soft: rgba(255, 238, 0, 0.12);
            --success: #4dffa1;
            --border: rgba(255, 255, 255, 0.08);
            --border-strong: rgba(255, 238, 0, 0.22);
            --radius: 18px;
            --shadow: 0 18px 50px rgba(0, 0, 0, 0.35);
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family:
                "SF Pro Text",
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                Roboto,
                "Helvetica Neue",
                sans-serif;
            line-height: 1.65;
            background:
                radial-gradient(ellipse 70% 45% at 12% -8%, rgba(255, 238, 0, 0.1), transparent 55%),
                radial-gradient(ellipse 55% 40% at 100% 8%, rgba(77, 255, 161, 0.06), transparent 50%),
                var(--bg);
        }

        .shell {
            max-width: 820px;
            margin: 0 auto;
            padding: 28px 20px 56px;
        }

        body.is-app .shell {
            padding: 12px 16px 40px;
        }

        .topnav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 22px;
        }

        .topnav a {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid var(--border);
            color: var(--soft);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            background: rgba(12, 16, 25, 0.7);
        }

        .topnav a.active {
            border-color: var(--border-strong);
            color: var(--accent-text, #080b12);
            background: linear-gradient(145deg, #f8ff7a, var(--accent));
        }

        .hero {
            margin-bottom: 22px;
            padding: 22px 22px 20px;
            border-radius: calc(var(--radius) + 4px);
            border: 1px solid var(--border-strong);
            background:
                linear-gradient(135deg, rgba(255, 238, 0, 0.1), rgba(77, 255, 161, 0.04) 48%, transparent 70%),
                var(--bg-elevated);
            box-shadow: var(--shadow);
        }

        body.is-app .hero {
            padding: 16px 16px 14px;
            margin-bottom: 16px;
        }

        body.is-app .hero h1 {
            font-size: 22px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 12px;
            padding: 5px 11px;
            border-radius: 999px;
            border: 1px solid rgba(255, 238, 0, 0.28);
            color: var(--accent);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            background: var(--accent-soft);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 30px;
            line-height: 1.15;
            letter-spacing: -0.04em;
            font-weight: 900;
        }

        .lede {
            margin: 0 0 14px;
            color: var(--soft);
            font-size: 15px;
            line-height: 1.55;
            max-width: 58ch;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .meta span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .meta strong {
            color: var(--text);
            font-weight: 800;
        }

        .toc {
            margin-bottom: 18px;
            padding: 16px 18px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: var(--card);
        }

        .toc h2 {
            margin: 0 0 12px;
            color: var(--text);
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .toc ol {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 8px;
            columns: 2;
            column-gap: 18px;
        }

        @media (max-width: 640px) {
            .toc ol { columns: 1; }
        }

        .toc a {
            display: block;
            color: var(--soft);
            font-size: 13px;
            font-weight: 650;
            text-decoration: none;
            line-height: 1.35;
            break-inside: avoid;
        }

        .toc a:hover {
            color: var(--accent);
        }

        .toc .n {
            color: var(--accent);
            font-weight: 800;
            margin-right: 6px;
        }

        section.legal-section {
            scroll-margin-top: 18px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 18px 8px;
            margin-bottom: 12px;
        }

        section.legal-section h2 {
            display: flex;
            align-items: baseline;
            gap: 10px;
            margin: 0 0 12px;
            font-size: 16px;
            line-height: 1.35;
            color: var(--text);
            letter-spacing: -0.01em;
        }

        section.legal-section h2 .n {
            flex: 0 0 auto;
            min-width: 28px;
            color: var(--accent);
            font-variant-numeric: tabular-nums;
        }

        p, li {
            color: var(--soft);
            font-size: 14px;
        }

        p { margin: 0 0 12px; }

        ul, ol.content-list {
            padding-left: 18px;
            margin: 0 0 12px;
        }

        li { margin-bottom: 7px; }

        .callout {
            margin: 0 0 12px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(77, 255, 161, 0.2);
            background: rgba(77, 255, 161, 0.07);
            color: var(--soft);
            font-size: 13px;
        }

        .callout strong { color: var(--success); }

        a {
            color: var(--accent);
            text-decoration-thickness: 1px;
            text-underline-offset: 2px;
        }

        footer.legal-footer {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        footer.legal-footer a { font-weight: 700; }
    </style>
</head>
<body class="{{ !empty($isApp) ? 'is-app' : '' }}">
    <div class="shell">
        <nav class="topnav" aria-label="Documente legale">
            <a class="{{ ($activeDoc ?? '') === 'terms' ? 'active' : '' }}" href="{{ $termsUrl ?? url('/legal/terms') }}">Termeni si conditii</a>
            <a class="{{ ($activeDoc ?? '') === 'privacy' ? 'active' : '' }}" href="{{ $privacyUrl ?? url('/legal/privacy') }}">Politica de confidentialitate</a>
        </nav>

        <header class="hero">
            <span class="badge">{{ $appName ?? $companyName }}</span>
            <h1>@yield('title')</h1>
            @hasSection('lede')
                <p class="lede">@yield('lede')</p>
            @endif
            <div class="meta">
                <span>Versiune <strong>{{ $legalVersion }}</strong></span>
                <span>In vigoare de la <strong>{{ $effectiveDate }}</strong></span>
            </div>
        </header>

        @hasSection('toc')
            <nav class="toc" aria-label="Cuprins">
                <h2>Cuprins</h2>
                @yield('toc')
            </nav>
        @endif

        @yield('content')

        <footer class="legal-footer">
            {{ $companyName }} · Aplicatia {{ $appName ?? $companyName }} · Contact:
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
            @if(!empty($supportPhone))
                · {{ $supportPhone }}
            @endif
            <br>
            Documentul poate fi actualizat; versiunea afisata in aplicatie este cea aplicabila.
        </footer>
    </div>
</body>
</html>
