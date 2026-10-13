<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;

/**
 * The first real HTTP sign-in endpoint in the app.
 *
 * Until now authentication existed only inside AuthModal, a Livewire component
 * reached over `/livewire/update` — `GET /login` was a redirect to home and
 * there was no `POST /login` at all. The Vue front end cannot drive a Livewire
 * component, so the flow is restated here as an ordinary controller.
 *
 * AuthModal is deliberately left in place: it still serves every Blade page,
 * and the two will coexist until the last Blade screen is converted. The rules
 * that matter for correctness — the suspension check and the session
 * regeneration around a successful attempt — are mirrored exactly. Role-based
 * landing is not duplicated at all; both paths hand off to `post-login`, which
 * already owns it.
 */
final class AuthenticatedSessionController extends Controller
{
    /**
     * Stamp the device that just signed in.
     *
     * Feeds the Security Overview's "Last Login Device", which was the fixed
     * string "Chrome, Nairobi" for every account. `saveQuietly` so this does
     * not fire model events on every sign-in.
     */
    public static function recordLogin(Request $request, ?\App\Models\User $user): void
    {
        $user?->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_login_user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ])->saveQuietly();
    }

    public function create(): InertiaResponse
    {
        return Inertia::render('auth/LoginView');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ( ! $this->attemptLogin($credentials, $request->boolean('remember'))) {
            // Thrown rather than returned so the message lands in Inertia's
            // shared `errors` bag keyed to the field the form displays.
            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }

        // Checked after the attempt, not before: answering "is this account
        // suspended?" to an unauthenticated caller would leak which addresses
        // are registered.
        if (Auth::user()?->isSuspended()) {
            $this->forgetSession($request);

            throw ValidationException::withMessages([
                'email' => 'Your account has been suspended. Please contact support if you believe this is an error.',
            ]);
        }

        /*
         * Two-factor challenge.
         *
         * The password was right, but a member who has enrolled a second
         * factor is NOT signed in until they produce a code. So the login is
         * undone and the user id is parked in the session; TwoFactorChallenge
         * completes it. Staying logged in "pending a code" would make 2FA
         * decorative — anyone with the password could simply navigate away
         * from the prompt.
         *
         * `hasTwoFactorEnabled()` reads the confirmed timestamp, so an
         * abandoned half-finished enrolment does not lock anyone out.
         */
        if (Auth::user()?->hasTwoFactorEnabled()) {
            $userId = Auth::id();
            $remember = $request->boolean('remember');

            $this->forgetSession($request);

            $request->session()->put('two_factor.user_id', $userId);
            $request->session()->put('two_factor.remember', $remember);

            return redirect()->route('two-factor.challenge');
        }

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $this->recordLogin($request, Auth::user());

        // `post-login` resolves the landing page from role, email verification
        // and outstanding SBO fees. Duplicating that here is how the two paths
        // would drift.
        return redirect()->intended(route('post-login'));
    }

    /*
     * No `destroy` here on purpose. `POST /logout` already maps to
     * App\Livewire\Actions\Logout, which is a plain invokable class rather
     * than a Livewire component and works unchanged for Inertia requests.
     */

    private function forgetSession(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Imported/scrubbed backups can hold non-bcrypt placeholders in
     * `users.password`. Laravel's bcrypt verifier correctly refuses those, but
     * login should still fail like bad credentials rather than render a 500.
     *
     * @param  array{email: string, password: string}  $credentials
     */
    private function attemptLogin(array $credentials, bool $remember): bool
    {
        try {
            return Auth::attempt($credentials, $remember);
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'does not use the Bcrypt algorithm')) {
                return false;
            }

            throw $exception;
        }
    }
}
