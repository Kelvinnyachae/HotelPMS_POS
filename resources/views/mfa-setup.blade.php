<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Set up MFA · {{ config('app.name', 'Dawineks Hospitality OS') }}</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #172b3a; background: #f3f6f7; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; }
        main { width: min(100%, 560px); padding: clamp(1.5rem, 5vw, 2.5rem); border: 1px solid #dbe4e6; border-radius: 18px; background: white; }
        h1 { margin: .4rem 0; font-size: 2rem; }
        p { color: #526674; line-height: 1.65; }
        .secret, input { display: block; width: 100%; padding: .9rem; border: 1px solid #b9c8cd; border-radius: 8px; font: inherit; }
        .secret { overflow-wrap: anywhere; background: #f7fafb; font-family: ui-monospace, monospace; font-weight: 700; letter-spacing: .08em; }
        label { display: block; margin: 1.3rem 0 .4rem; font-weight: 700; }
        button { width: 100%; margin-top: 1rem; padding: .9rem; border: 0; border-radius: 8px; color: white; background: #123b3a; font: inherit; font-weight: 700; cursor: pointer; }
        .error { margin-top: .35rem; color: #9a2929; }
        .step { margin-top: 1.2rem; }
        code { overflow-wrap: anywhere; }
    </style>
</head>
<body>
<main>
    <h1>Set up administrator MFA</h1>
    <p>Client administrators must use an authenticator app to access the workspace. Add this account manually using the secret below, then enter the six-digit code from the app.</p>
    <div class="secret" data-secret="{{ $secret }}">{{ $secret }}</div>
    <p class="step">If your authenticator accepts setup links, use this URI:</p>
    <p><code>{{ $provisioningUri }}</code></p>
    <form method="POST" action="{{ route('mfa.setup.confirm') }}">
        @csrf
        <label for="code">Authenticator code</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
        @error('code') <div class="error">{{ $message }}</div> @enderror
        <button type="submit">Confirm and enable MFA</button>
    </form>
</main>
</body>
</html>
