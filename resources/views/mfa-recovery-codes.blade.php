<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Save recovery codes · {{ config('app.name', 'Dawineks Hospitality OS') }}</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #172b3a; background: #f3f6f7; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; }
        main { width: min(100%, 560px); padding: clamp(1.5rem, 5vw, 2.5rem); border: 1px solid #dbe4e6; border-radius: 18px; background: white; }
        h1 { margin: .4rem 0; font-size: 2rem; }
        p { color: #526674; line-height: 1.65; }
        ul { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; padding: 1rem; list-style: none; background: #f7fafb; border-radius: 8px; }
        code { font-size: 1rem; font-weight: 700; }
        a { display: inline-block; margin-top: 1rem; padding: .85rem 1rem; border-radius: 8px; color: white; background: #123b3a; font-weight: 700; text-decoration: none; }
        .warning { color: #9a2929; font-weight: 700; }
    </style>
</head>
<body>
<main>
    <h1>Save your recovery codes</h1>
    <p class="warning">These codes are shown once. Store them somewhere safe. Each code can be used only one time.</p>
    <ul>
        @foreach ($recoveryCodes as $code)
            <li><code>{{ $code }}</code></li>
        @endforeach
    </ul>
    <a href="{{ route('workspace.select') }}">Choose your workspace</a>
</main>
</body>
</html>
