<?php

namespace App\Http\Controllers;

use App\Mail\ClientPasswordResetMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class ClientPasswordResetController extends Controller
{
    private const GENERIC_STATUS = 'If an active account matches that email, a password reset link will be sent.';

    public function requestForm(): View
    {
        return view('password-forgot');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
        ]);
        $email = Str::lower(trim($validated['email']));
        $rateKey = 'password-reset:'.hash('sha256', $email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            return back()->with('status', self::GENERIC_STATUS);
        }

        if (! $this->canDeliverSecureResetLink()) {
            Log::warning('Password reset suppressed because secure URL or mail delivery is not configured.');

            return back()->with('status', self::GENERIC_STATUS);
        }

        RateLimiter::hit($rateKey, 900);
        $reset = DB::selectOne('SELECT * FROM security.issue_client_password_reset(?)', [$email]);

        if ($reset && is_string($reset->email) && is_string($reset->token)) {
            $resetUrl = route('password.reset.form', [
                'token' => $reset->token,
                'email' => $reset->email,
            ]);
            Mail::to($reset->email)->send(new ClientPasswordResetMail($resetUrl));
        }

        return back()->with('status', self::GENERIC_STATUS);
    }

    public function resetForm(Request $request, string $token): View
    {
        abort_unless(preg_match('/\A[0-9a-fA-F]{64}\z/', $token) === 1, 404);

        return view('password-reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function completeReset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'token' => ['required', 'regex:/\A[0-9a-fA-F]{64}\z/'],
            'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
        ]);

        if (strlen($validated['password']) > 72) {
            throw ValidationException::withMessages(['password' => 'The password must be 72 bytes or fewer.']);
        }

        $rateKey = 'password-reset-complete:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            throw ValidationException::withMessages([
                'email' => 'The reset link could not be verified. Request a new link and try again later.',
            ]);
        }
        RateLimiter::hit($rateKey, 900);

        $completed = DB::selectOne(
            'SELECT security.complete_client_password_reset(?, ?, ?)::text AS completed',
            [
                Str::lower(trim($validated['email'])),
                $validated['token'],
                Hash::make($validated['password']),
            ],
        );

        if (! $this->databaseBoolean($completed?->completed)) {
            throw ValidationException::withMessages([
                'email' => 'That reset link is invalid or expired. Request a new link and try again.',
            ]);
        }

        RateLimiter::clear($rateKey);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Password updated. Sign in with your new password.');
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === '1' || $value === 1;
    }

    private function canDeliverSecureResetLink(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);
        $mailer = (array) config('mail.mailers.'.config('mail.default'), []);
        $usesLogTransport = ($mailer['transport'] ?? null) === 'log'
            || in_array('log', (array) ($mailer['mailers'] ?? []), true);

        return strtolower((string) $scheme) === 'https' && ! $usesLogTransport;
    }
}
