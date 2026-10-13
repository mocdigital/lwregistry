<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TwoFactorAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The second step of signing in, for accounts with 2FA enabled.
 *
 * The password stage deliberately leaves the caller LOGGED OUT and parks only
 * the user id in the session (see AuthenticatedSessionController::store). This
 * controller is what actually authenticates them, and only on a valid code.
 * Any other arrangement — logging them in first and "requiring" the code
 * afterwards — makes the factor cosmetic, because navigating away from the
 * prompt would leave a working session behind.
 */
final class TwoFactorChallengeController extends Controller
{
    /** Attempts per minute against one pending login. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TwoFactorAuth $twoFactor) {}

    public function create(Request $request): InertiaResponse|RedirectResponse
    {
        if (null === $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/TwoFactorChallengeView');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (null === $user) {
            return redirect()->route('login');
        }

        $request->validate([
            'code' => ['required', 'string', 'max:20'],
            // A recovery code is offered when the authenticator is lost.
            'recovery' => ['sometimes', 'boolean'],
        ]);

        /*
         * Throttled per pending user: a six-digit code is only a million
         * possibilities, which is well within reach of an unthrottled loop
         * inside one 30-second window.
         */
        $key = 'two-factor:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Try again in '
                    . RateLimiter::availableIn($key) . ' seconds.',
            ]);
        }

        $code = (string) $request->input('code');

        $passed = $request->boolean('recovery')
            ? $this->twoFactor->consumeRecoveryCode($user, $code)
            : $this->twoFactor->verify((string) $user->two_factor_secret, $code);

        if ( ! $passed) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'code' => $request->boolean('recovery')
                    ? 'That recovery code is not valid, or has already been used.'
                    : 'That code is not valid. Check your authenticator app and try again.',
            ]);
        }

        RateLimiter::clear($key);

        $remember = (bool) $request->session()->pull('two_factor.remember', false);
        $request->session()->forget('two_factor.user_id');

        Auth::login($user, $remember);

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        AuthenticatedSessionController::recordLogin($request, $user);

        return redirect()->intended(route('post-login'));
    }

    /**
     * The account waiting on a code, or null if there is no pending login.
     *
     * Re-checks suspension: an account can be suspended between entering the
     * password and entering the code.
     */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('two_factor.user_id');

        if (null === $id) {
            return null;
        }

        $user = User::find($id);

        if (null === $user || ! $user->hasTwoFactorEnabled() || $user->isSuspended()) {
            $request->session()->forget(['two_factor.user_id', 'two_factor.remember']);

            return null;
        }

        return $user;
    }
}
