<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Password reset and verification resend.
 *
 * The Vue screens for these existed with nowhere to post — the logic lived in
 * Volt components that no longer have routes. Restated here so the forms work.
 */
final class PasswordResetController extends Controller
{
    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(
            ['email' => ['required', 'string', 'email', 'exists:users,email']],
            ['email.exists' => 'We could not find an account with that email address.']
        );

        $status = Password::sendResetLink($request->only('email'));

        if (Password::RESET_THROTTLED === $status) {
            DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
                ->where('email', $request->string('email')->lower()->toString())
                ->delete();

            $status = Password::sendResetLink($request->only('email'));
        }

        if (Password::RESET_LINK_SENT !== $status) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return back()->with('status', __(Password::RESET_LINK_SENT));
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if (Password::PASSWORD_RESET !== $status) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return redirect()->route('login')->with('status', __($status));
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('post-login');
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (TransportExceptionInterface $e) {
            report($e);

            return back()->with('status', 'verification-link-failed');
        }

        return back()->with('status', 'verification-link-sent');
    }
}
