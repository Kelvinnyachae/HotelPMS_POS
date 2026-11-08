<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class ClientLoginController extends Controller
{
    public function create(): View|RedirectResponse
    {
        return Auth::check()
            ? redirect()->route('home')
            : view('login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:72'],
        ]);

        $key = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($key),
                    'minutes' => ceil(RateLimiter::availableIn($key) / 60),
                ]),
            ]);
        }

        $provider = Auth::guard()->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user || ! $provider->validateCredentials($user, $credentials)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($key);

        if ($user instanceof User && $user->mfaEnabled) {
            $request->session()->regenerate();
            $request->session()->put([
                'auth.pending_mfa_user_id' => $user->getAuthIdentifier(),
                'auth.pending_mfa_secret' => $user->mfaSecretCiphertext,
            ]);

            return redirect()->route('mfa.challenge');
        }

        if ($user instanceof User && $user->requiresMfa) {
            $request->session()->regenerate();
            $request->session()->put('auth.pending_mfa_enrollment_user_id', $user->getAuthIdentifier());

            return redirect()->route('mfa.setup');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
