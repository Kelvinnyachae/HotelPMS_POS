<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Verify sign-in · {{ config('app.name', 'Dawineks Hospitality OS') }}</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #172b3a; background: #f3f6f7; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; }
        main { width: min(100%, 440px); padding: clamp(1.5rem, 5vw, 2.5rem); border: 1px solid #dbe4e6; border-radius: 18px; background: white; }
        h1 { margin: .4rem 0; font-size: 2rem; }
        p { color: #526674; line-height: 1.65; }
        label { display: block; margin: 1.1rem 0 .4rem; font-weight: 700; }
        input { width: 100%; padding: .85rem; border: 1px solid #b9c8cd; border-radius: 8px; font: inherit; }
        button { width: 100%; margin-top: 1rem; padding: .9rem; border: 0; border-radius: 8px; color: white; background: #123b3a; font: inherit; font-weight: 700; cursor: pointer; }
        .error { margin-top: .35rem; color: #9a2929; }
    </style>
</head>
<body>
<main>
    <h1>Verify sign-in</h1>
    <p>Enter a six-digit authenticator code or one unused recovery code.</p>
    <form method="POST" action="{{ route('mfa.verify') }}">
        @csrf
        <label for="code">Verification code</label>
        <input id="code" name="code" autocomplete="one-time-code" required autofocus>
        @error('code') <div class="error">{{ $message }}</div> @enderror
        <button type="submit">Verify and continue</button>
    </form>
</main>
</body>
</html>
