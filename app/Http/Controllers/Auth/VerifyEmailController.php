<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminPages;
use App\Support\SboWorkspaceAccess;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class VerifyEmailController extends Controller
{
    /**
     * Mark the given user's email address as verified.
     *
     * This route is intentionally NOT behind the `auth` middleware. The
     * verification link is opened from an email client, which is frequently a
     * different browser (or device) from the one used to register, so there is
     * often no session on this request. Requiring `auth` bounced those visitors
     * to the login redirect and back to the verification notice without ever
     * fulfilling the verification.
     *
     * The `signed` middleware is the security boundary here: the URL is
     * cryptographically signed and expires, which proves the recipient controls
     * the mailbox. We resolve the user from the signed {id} rather than from the
     * session.
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        // The hash binds the link to the email address it was sent to, so a
        // stale link stops working once the address changes.
        if ( ! hash_equals($hash, sha1((string) $user->getEmailForVerification()))) {
            throw new AccessDeniedHttpException();
        }

        // If someone else is logged in on this browser, refuse rather than
        // silently switching accounts.
        if (Auth::check() && ! Auth::user()->is($user)) {
            throw new AccessDeniedHttpException();
        }

        if ($user->hasVerifiedEmail()) {
            $this->ensureLoggedIn($user);

            return redirect()->intended($this->verifiedRedirectUrl($user));
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        $this->ensureLoggedIn($user);

        return redirect()->intended($this->verifiedRedirectUrl($user));
    }

    /**
     * Log the user in when the link was opened without a session, so they land
     * on their destination instead of a login prompt.
     */
    private function ensureLoggedIn(User $user): void
    {
        if (Auth::check()) {
            return;
        }

        Auth::login($user);

        request()->session()->regenerate();
    }

    private function verifiedRedirectUrl(User $user): string
    {
        if ($user->role->isAdmin()) {
            return route(AdminPages::firstAccessibleRouteName($user) ?? 'home', absolute: false) . '?verified=1';
        }

        if ($user->role->isSboManager()) {
            // A member who has verified but not yet added a business goes
            // straight to "Add your business" — that is their next step.
            if ( ! $user->sbos()->exists()) {
                return route('sbo.listings.create', absolute: false) . '?verified=1';
            }

            $targetSbo = app(SboWorkspaceAccess::class)->paymentTarget($user);

            if ($targetSbo && ! $targetSbo->feesPaid()) {
                return route('sbo.applications.progress', [
                    'sbo' => $targetSbo,
                    'step' => 'fees',
                ], absolute: false) . '&verified=1';
            }

            return route('sbo.dashboard', absolute: false) . '?verified=1';
        }

        return route('dashboard', absolute: false) . '?verified=1';
    }
}
