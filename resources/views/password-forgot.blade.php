<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password · {{ config('app.name', 'Dawineks Hospitality OS') }}</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #172b3a; background: #f3f6f7; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; }
        main { width: min(100%, 440px); padding: clamp(1.5rem, 5vw, 2.5rem); border: 1px solid #dbe4e6; border-radius: 18px; background: white; }
        h1 { margin: .4rem 0; font-size: 2rem; }
        p { color: #526674; line-height: 1.6; }
        label { display: block; margin: 1.1rem 0 .4rem; font-weight: 700; }
        input { width: 100%; padding: .8rem .9rem; border: 1px solid #b9c8cd; border-radius: 8px; font: inherit; }
        button { width: 100%; margin-top: 1.3rem; padding: .85rem 1rem; border: 0; border-radius: 8px; color: white; background: #123b3a; font: inherit; font-weight: 700; cursor: pointer; }
        .status { padding: .7rem; color: #175f41; background: #e6f4ec; border-radius: 8px; }
        .error { margin-top: .4rem; color: #9a2929; font-size: .85rem; }
        a { color: #0b7568; font-weight: 700; }
    </style>
</head>
<body>
<main>
    <h1>Reset your password</h1>
    <p>Enter your account email. If it matches an active account, we’ll send a one-time reset link.</p>
    @if (session('status')) <div class="status">{{ session('status') }}</div> @endif
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
        @error('email') <div class="error">{{ $message }}</div> @enderror
        <button type="submit">Send reset link</button>
    </form>
    <p><a href="{{ route('login') }}">Return to sign in</a></p>
</main>
</body>
</html>
