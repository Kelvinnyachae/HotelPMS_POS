<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title') · {{ config('app.name', 'Dawineks Hospitality OS') }}</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #172b3a; background: #f3f6f7; font-synthesis: none; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem clamp(1.25rem, 5vw, 4.5rem); color: #f7fbfa; background: #123b3a; }
        .brand { font-size: .95rem; font-weight: 700; letter-spacing: .02em; }
        .preview { border: 1px solid #c3d8d2; border-radius: 999px; padding: .4rem .7rem; color: #e1f2ec; font-size: .72rem; font-weight: 700; letter-spacing: .08em; }
        main { width: min(100% - 2rem, 920px); margin: 4rem auto; }
        .eyebrow { color: #0b7568; font-size: .75rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: .75rem 0; font-size: clamp(2rem, 5vw, 3.35rem); line-height: 1.08; letter-spacing: -.04em; }
        .intro { max-width: 680px; color: #526674; font-size: 1.05rem; line-height: 1.7; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-top: 2rem; }
        .card { border: 1px solid #dbe4e6; border-radius: 16px; padding: 1.4rem; background: #fff; box-shadow: 0 12px 30px #1735440a; }
        .card h2 { margin: 0 0 .65rem; font-size: 1.05rem; }
        .card p { margin: 0; color: #5c6f7b; line-height: 1.6; }
        .status { display: inline-flex; align-items: center; gap: .45rem; margin-top: 1rem; color: #245b49; font-size: .78rem; font-weight: 700; }
        .dot { width: .5rem; height: .5rem; border-radius: 50%; background: #31a878; }
        footer { margin-top: 2rem; color: #687984; font-size: .82rem; }
        @media (max-width: 560px) { header { align-items: flex-start; flex-direction: column; } main { margin-top: 2.5rem; } }
    </style>
</head>
<body>
<header>
    <div class="brand">Dawineks Hospitality OS</div>
    <div class="preview">LOCAL FOUNDATION PREVIEW · NOT FOR DEPLOYMENT</div>
</header>
<main>
    <div class="eyebrow">@yield('eyebrow')</div>
    <h1>@yield('heading')</h1>
    <p class="intro">@yield('intro')</p>
    <section class="grid" aria-label="Foundation status">
        @yield('cards')
    </section>
    <footer>Foundation preview only. No client or business data is available in this application yet.</footer>
</main>
</body>
</html>