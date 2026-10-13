<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function (): void {
    /*
     * `GET /login` used to redirect to home, because sign-in existed only as a
     * Livewire modal and there was no page to show. It now serves the Vue
     * login screen. AuthModal still handles sign-in from Blade pages — that is
     * a modal and never navigates here, so the two do not collide.
     *
     * `POST /login` is new. There was previously no HTTP sign-in endpoint at
     * all; only `POST /logout` existed.
     */
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:6,1');

    /*
     * The second step for an account with 2FA enabled.
     *
     * Inside the `guest` group because that is exactly what the caller is at
     * this point: the password stage verified the credentials and then logged
     * them straight back out, parking only the pending user id in the session.
     * They are not authenticated until TwoFactorChallengeController says so.
     */
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:10,1');

    /*
     * Registration. Like login, this had no HTTP endpoint — only the Livewire
     * modal. `POST /register` mirrors AuthModal::registerBusiness(); see
     * RegisteredUserController for what is deliberately not mirrored.
     */
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    // The wizard walks /register/1..3; the step is read from the URL.
    Route::get('register/{step}', [RegisteredUserController::class, 'create'])
        ->whereIn('step', ['1', '2', '3'])
        ->name('register.step');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1');

    // Original route (kept for reference):
    // Volt::route('register', 'auth.register')->name('register');

    /*
     * Password reset, served from Vue. The Volt pages remain on disk as the
     * reference for the POST handling these screens still need.
     */
    Route::get('forgot-password', fn () => Inertia\Inertia::render('auth/ForgotPasswordView'))
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:30,1')->name('password.email');

    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:6,1')->name('password.store');

    Route::get('reset-password/{token}', fn (string $token) => Inertia\Inertia::render('auth/ResetPasswordView', [
        'token' => $token,
        'email' => request('email'),
    ]))->name('password.reset');

});

// Deliberately outside the `auth` group: verification links are opened from an
// email client, often in a different browser or on a different device from the
// one used to register. The `signed` middleware proves mailbox ownership, so a
// session is not required. See VerifyEmailController for the full rationale.
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware('auth')->group(function (): void {
    Route::get('verify-email', fn () => Inertia\Inertia::render('auth/VerifyEmailView', [
        'email' => auth()->user()?->email,
    ]))->name('verification.notice');

    Route::post('email/verification-notification', [PasswordResetController::class, 'resendVerification'])
        ->middleware('throttle:6,1')->name('verification.send');

    Volt::route('confirm-password', 'auth.confirm-password')
        ->name('password.confirm');
});

Route::post('logout', Logout::class)
    ->name('logout');
