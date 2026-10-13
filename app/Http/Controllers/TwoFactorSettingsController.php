<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\TwoFactorAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Enrolling, confirming and disabling two-factor auth.
 *
 * Three steps, and the split is what makes the feature safe to turn on:
 *
 *   enable()   mints a secret and shows the QR. 2FA is NOT yet in force.
 *   confirm()  the member types a code their app produced, proving the secret
 *              transferred correctly. Only now is it in force, and only now
 *              are the recovery codes issued.
 *   disable()  requires the current password, because an unattended session
 *              should not be able to strip the account's second factor.
 *
 * Collapsing enable() and confirm() into one step is the classic way to lock
 * a user out of their own account: a QR that scanned badly would leave them
 * facing a prompt no app on earth could answer.
 */
final class TwoFactorSettingsController extends Controller
{
    public function __construct(private readonly TwoFactorAuth $twoFactor) {}

    /** Begin enrolment: mint a secret and hand back a QR to scan. */
    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();

        /*
         * A fresh secret every time setup is started. Reusing a pending one
         * would mean a member who abandoned setup, deleted the entry from
         * their app and started again would be shown a QR for a secret their
         * app no longer holds.
         */
        $secret = $this->twoFactor->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            // Explicitly not confirmed: login stays password-only until the
            // member proves the code works.
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return back()->with('status', 'two-factor-setup-started');
    }

    /** Prove the authenticator works, then put 2FA into force. */
    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate(['code' => ['required', 'string', 'max:20']]);

        if (blank($user->two_factor_secret)) {
            throw ValidationException::withMessages([
                'code' => 'Start the setup again — no pending authenticator was found.',
            ]);
        }

        if ( ! $this->twoFactor->verify((string) $user->two_factor_secret, (string) $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => 'That code is not valid. Check your authenticator app and try again.',
            ]);
        }

        /*
         * Recovery codes are issued here rather than at enable(), so they only
         * ever exist for an account that can actually complete the challenge.
         * Shown once — they are stored encrypted and never redisplayed.
         */
        $codes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

        return back()
            ->with('status', 'two-factor-enabled')
            ->with('recoveryCodes', $codes);
    }

    /** Turn 2FA off. Password required. */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ], [
            'password.current_password' => 'That password is not correct.',
        ]);

        $this->twoFactor->disable($request->user());

        return back()->with('status', 'two-factor-disabled');
    }

    /**
     * Re-issue recovery codes.
     *
     * The old set is replaced outright: a member regenerates because they
     * think the previous list leaked, so leaving any of it valid would defeat
     * the point.
     */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ( ! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'code' => 'Set up two-factor authentication first.',
            ]);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return back()
            ->with('status', 'two-factor-recovery-regenerated')
            ->with('recoveryCodes', $codes);
    }
}
