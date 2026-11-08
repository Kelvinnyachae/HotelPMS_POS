<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Choose workspace · {{ config('app.name', 'Dawineks Hospitality OS') }}</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #172b3a; background: #f3f6f7; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; }
        main { width: min(100%, 620px); padding: clamp(1.5rem, 5vw, 2.5rem); border: 1px solid #dbe4e6; border-radius: 18px; background: white; }
        h1 { margin: .4rem 0; font-size: 2rem; }
        p { color: #526674; line-height: 1.65; }
        ul { display: grid; gap: .75rem; padding: 0; list-style: none; }
        a { display: block; padding: 1rem; border: 1px solid #cbd9dc; border-radius: 10px; color: #123b3a; font-weight: 700; text-decoration: none; overflow-wrap: anywhere; }
        a:hover { background: #f3f8f6; }
        code { display: block; margin-top: .35rem; color: #526674; font-weight: 400; }
        .empty { padding: 1rem; border-radius: 8px; background: #f7fafb; }
    </style>
</head>
<body>
<main>
    <h1>Choose your workspace</h1>
    <p>Only active memberships assigned to your signed-in account are shown.</p>
    @if ($memberships->isEmpty())
        <div class="empty">There is no active workspace assigned to this account yet.</div>
    @else
        <ul>
            @foreach ($memberships as $membership)
                <li>
                    <a href="{{ route('workspace.preview', ['account_id' => $membership->account_id, 'property_id' => $membership->property_id]) }}">
                        Open assigned property
                        <code>Account {{ $membership->account_id }} · Property {{ $membership->property_id }}</code>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</main>
</body>
</html>
