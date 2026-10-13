<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/*
 * Forgot password — the parts tests/Feature/Auth/PasswordResetTest.php does not
 * cover. That file asserts the two happy paths and the old Volt page's
 * Turnstile; these are the failure modes underneath: a link that points
 * somewhere real, a token that cannot be replayed, and an unknown address
 * rejected before the screen claims an email was sent.
 *
 * Worth knowing while reading these: the reset notification is NOT queued.
 * Laravel's own `ResetPassword` does not implement ShouldQueue and User does
 * not override `sendPasswordResetNotification`, so it goes out on the request.
 * Verification mail (`VerifyEmailQueued`) does queue. That asymmetry is a
 * diagnostic in itself — if reset mail arrives and verification does not, the
 * worker is down rather than the mailer being wrong.
 */

test('the link points at the reset screen with a usable token', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'ada@example.test']);

    $this->post(route('password.email'), ['email' => 'ada@example.test']);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        /*
         * The URL in the mail is built from `password.reset`. If that route is
         * ever renamed the mail still sends and the link 404s — which is the
         * failure a member reports as "the email does not work".
         */
        return str_contains((string) $mail->actionUrl, route('password.reset', $notification->token));
    });
});

test('an unknown address is rejected before claiming a reset email was sent', function (): void {
    Notification::fake();

    $this->post(route('password.email'), ['email' => 'nobody@example.test'])
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('status');

    Notification::assertNothingSent();
});

test('requesting another reset link replaces the old token and sends again', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'ada@example.test']);

    $this->post(route('password.email'), ['email' => 'ada@example.test'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    $this->post(route('password.email'), ['email' => 'ada@example.test'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    Notification::assertSentToTimes($user, ResetPassword::class, 2);
});

test('a token cannot be used twice', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'ada@example.test']);
    $this->post(route('password.email'), ['email' => 'ada@example.test']);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $payload = [
        'token' => $token,
        'email' => 'ada@example.test',
        'password' => 'A-brand-new-one-42',
        'password_confirmation' => 'A-brand-new-one-42',
    ];

    $this->post(route('password.store'), $payload)->assertSessionHasNoErrors();
    $this->post(route('password.store'), $payload)->assertSessionHasErrors('email');
});

test('a forged token is refused', function (): void {
    User::factory()->create(['email' => 'ada@example.test']);

    $this->post(route('password.store'), [
        'token' => 'not-a-real-token',
        'email' => 'ada@example.test',
        'password' => 'A-brand-new-one-42',
        'password_confirmation' => 'A-brand-new-one-42',
    ])->assertSessionHasErrors('email');
});

test('a weak or unconfirmed password is refused', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'ada@example.test']);
    $this->post(route('password.email'), ['email' => 'ada@example.test']);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => 'ada@example.test',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => 'ada@example.test',
        'password' => 'A-brand-new-one-42',
        'password_confirmation' => 'something-else-entirely',
    ])->assertSessionHasErrors('password');
});

test('both screens render, and the reset one carries the token through', function (): void {
    $this->get(route('password.request'))->assertOk();

    $this->get(route('password.reset', ['token' => 'abc123']) . '?email=ada%40example.test')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/ResetPasswordView')
            ->where('token', 'abc123')
            // Both are posted back, and the reset fails without either.
            ->where('email', 'ada@example.test'));
});

test('the request endpoint is rate limited', function (): void {
    // Still limited, but high enough not to block normal account recovery.
    $route = app('router')->getRoutes()->getByName('password.email');

    expect($route->gatherMiddleware())->toContain('throttle:30,1');
});
