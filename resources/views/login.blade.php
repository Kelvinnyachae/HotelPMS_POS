<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Sign in · {{ config('app.name', 'Dawineks Hospitality OS') }}</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #172b3a; background: #f3f6f7; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; }
        main { width: min(100%, 440px); padding: clamp(1.5rem, 5vw, 2.5rem); border: 1px solid #dbe4e6; border-radius: 18px; background: white; box-shadow: 0 18px 50px #17354412; }
        .brand { color: #0b7568; font-size: .75rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: .7rem 0; font-size: 2rem; letter-spacing: -.035em; }
        p { color: #526674; line-height: 1.6; }
        label { display: block; margin: 1.1rem 0 .4rem; font-size: .9rem; font-weight: 700; }
        input { width: 100%; padding: .8rem .9rem; border: 1px solid #b9c8cd; border-radius: 8px; font: inherit; }
        input:focus { outline: 3px solid #8bc9bd; border-color: #0b7568; }
        button { width: 100%; margin-top: 1.4rem; padding: .85rem 1rem; border: 0; border-radius: 8px; color: white; background: #123b3a; font: inherit; font-weight: 700; cursor: pointer; }
        .error { margin-top: .4rem; color: #9a2929; font-size: .85rem; }
        footer { margin-top: 1.4rem; color: #687984; font-size: .82rem; }
    </style>
</head>
<body>
<main>
    <div class="brand">Dawineks Hospitality OS</div>
    <h1>Sign in</h1>
    <p>Use the account credentials provided by your organization.</p>
    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        @error('email') <div class="error">{{ $message }}</div> @enderror

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        @error('password') <div class="error">{{ $message }}</div> @enderror

        <button type="submit">Sign in</button>
    </form>
    <footer>Account recovery and multi-factor setup are not yet available in this foundation preview.</footer>
</main>
</body>
</html>
