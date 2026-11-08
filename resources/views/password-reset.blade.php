<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a new password · {{ config('app.name', 'Dawineks Hospitality OS') }}</title>
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
        .error { margin-top: .4rem; color: #9a2929; font-size: .85rem; }
    </style>
</head>
<body>
<main>
    <h1>Choose a new password</h1>
    <p>Use at least 12 characters. This one-time link expires after 15 minutes.</p>
    <form method="POST" action="{{ route('password.reset') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required>
        @error('email') <div class="error">{{ $message }}</div> @enderror
        <label for="password">New password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
        @error('password') <div class="error">{{ $message }}</div> @enderror
        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
        <button type="submit">Update password</button>
    </form>
</main>
</body>
</html>
