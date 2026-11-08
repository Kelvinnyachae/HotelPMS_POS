<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Providers\ClientTotp;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class ClientMfaController extends Controller
{
    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $this->hasPendingChallenge($request)) {
            return redirect()->route('login');
        }

        return view('mfa-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        if (! $this->hasPendingChallenge($request)) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:32'],
        ]);
        $userId = (string) $request->session()->get('auth.pending_mfa_user_id');
        $ciphertext = (string) $request->session()->get('auth.pending_mfa_secret');
        $rateKey = 'mfa:'.$userId.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Too many verification attempts. Please wait before trying again.',
            ]);
        }

        try {
            $secret = Crypt::decryptString($ciphertext);
        } catch (Throwable) {
            $secret = '';
        }

        $code = preg_replace('/[^A-Fa-f0-9]/', '', $validated['code']) ?? '';
        $verified = $secret !== '' && ClientTotp::verify($secret, $validated['code']);

        if (! $verified && preg_match('/\A[A-Fa-f0-9]{12}\z/', $code) === 1) {
            $verified = app(TenantContext::class)->withinPrincipal(
                $userId,
                fn () => (bool) $this->databaseBoolean(DB::selectOne(
                    'SELECT security.consume_client_mfa_recovery_code(?, ?) AS consumed',
                    [$userId, $code],
                )->consumed),
            );
        }

        if (! $verified) {
            RateLimiter::hit($rateKey, 300);

            throw ValidationException::withMessages([
                'code' => 'That verification code is not valid.',
            ]);
        }

        RateLimiter::clear($rateKey);
        $user = Auth::guard()->getProvider()->retrieveById($userId);

        if (! $user instanceof User || ! $user->mfaEnabled) {
            $request->session()->forget(['auth.pending_mfa_user_id', 'auth.pending_mfa_secret']);

            return redirect()->route('login');
        }

        Auth::login($user);
        $request->session()->forget(['auth.pending_mfa_user_id', 'auth.pending_mfa_secret']);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $this->pendingEnrollmentUser($request);

        abort_unless($user instanceof User && $user->requiresMfa, 404);

        if ($user->mfaEnabled) {
            $request->session()->forget('auth.pending_mfa_enrollment_user_id');
            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->route('workspace.select');
        }

        $ciphertext = $request->session()->get('mfa.setup_secret');

        if (! is_string($ciphertext) || $ciphertext === '') {
            $ciphertext = Crypt::encryptString(ClientTotp::generateSecret());
            $request->session()->put('mfa.setup_secret', $ciphertext);
        }

        $secret = Crypt::decryptString($ciphertext);

        return view('mfa-setup', [
            'secret' => $secret,
            'provisioningUri' => ClientTotp::provisioningUri(config('app.name'), $user->email, $secret),
        ]);
    }

    public function confirmSetup(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'regex:/\A[0-9]{6}\z/'],
        ]);
        $user = $this->pendingEnrollmentUser($request);
        $ciphertext = $request->session()->get('mfa.setup_secret');

        abort_unless($user instanceof User && $user->requiresMfa && ! $user->mfaEnabled && is_string($ciphertext), 403);

        $secret = Crypt::decryptString($ciphertext);

        if (! ClientTotp::verify($secret, $validated['code'])) {
            throw ValidationException::withMessages(['code' => 'That authenticator code is not valid.']);
        }

        $recoveryCodes = ClientTotp::generateRecoveryCodes();
        $recoveryHashes = array_map(
            static fn (string $code): string => Hash::make(str_replace('-', '', $code)),
            $recoveryCodes,
        );
        $saved = app(TenantContext::class)->withinPrincipal(
            $user->getAuthIdentifier(),
            fn () => $this->databaseBoolean(DB::selectOne(
                'SELECT security.save_client_mfa(?, ?, ?::jsonb) AS saved',
                [$user->getAuthIdentifier(), $ciphertext, json_encode($recoveryHashes, JSON_THROW_ON_ERROR)],
            )->saved),
        );

        if (! $saved) {
            throw ValidationException::withMessages(['code' => 'MFA could not be saved. Please try again.']);
        }

        $request->session()->forget('mfa.setup_secret');
        $request->session()->forget('auth.pending_mfa_enrollment_user_id');
        $user->mfaEnabled = true;
        $user->mfaSecretCiphertext = $ciphertext;
        Auth::login($user);
        $request->session()->regenerate();

        return view('mfa-recovery-codes', ['recoveryCodes' => $recoveryCodes]);
    }

    private function pendingEnrollmentUser(Request $request): ?User
    {
        if ($request->user() instanceof User) {
            return $request->user();
        }

        $userId = $request->session()->get('auth.pending_mfa_enrollment_user_id');
        if (! is_string($userId) || ! TenantContext::isValidUuid($userId)) {
            return null;
        }

        $user = Auth::guard()->getProvider()->retrieveById($userId);

        return $user instanceof User ? $user : null;
    }

    private function hasPendingChallenge(Request $request): bool
    {
        return is_string($request->session()->get('auth.pending_mfa_user_id'))
            && is_string($request->session()->get('auth.pending_mfa_secret'));
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }
}
