<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\TwoFactorAuth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/*
 * Two-factor authentication.
 *
 * The Security Overview panel used to tell every account "Two-Factor Auth:
 * ACTIVE" while no second factor existed anywhere in the codebase. These
 * assert the real thing: a standard TOTP secret any authenticator can hold,
 * a two-stage enrolment that cannot lock anyone out, and a login that will not
 * complete without a code.
 */

function twoFactorUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'password' => Hash::make('correct-horse-1A'),
        'email_verified_at' => now(),
    ], $overrides));
}

/** Enrol fully, the way the UI does, and return the shared secret. */
function enrolTwoFactor(User $user): string
{
    $secret = app(TwoFactorAuth::class)->generateSecret();

    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['AAAAA-BBBBB', 'CCCCC-DDDDD'],
    ])->save();

    return $secret;
}

function currentCode(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

beforeEach(function (): void {
    RateLimiter::clear('two-factor:1');
});

/* ------------------------------------------------------------ enrolment --- */

test('starting setup mints a secret but does not switch 2FA on', function (): void {
    $user = twoFactorUser();

    $this->actingAs($user)->post('/settings/two-factor')->assertRedirect();

    $user->refresh();

    /*
     * The critical property. If login gated on the secret rather than the
     * confirmation, a member whose QR scan failed would be locked out of their
     * own account by a factor they can never produce.
     */
    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse();
});

test('confirming with a real code switches 2FA on and issues recovery codes', function (): void {
    $user = twoFactorUser();

    $this->actingAs($user)->post('/settings/two-factor');
    $secret = $user->refresh()->two_factor_secret;

    $this->actingAs($user)
        ->post('/settings/two-factor/confirm', ['code' => currentCode($secret)])
        ->assertRedirect()
        ->assertSessionHas('recoveryCodes');

    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->two_factor_recovery_codes)
        ->toHaveCount(TwoFactorAuth::RECOVERY_CODE_COUNT);
});

test('a wrong code does not switch 2FA on', function (): void {
    $user = twoFactorUser();
    $this->actingAs($user)->post('/settings/two-factor');

    $this->actingAs($user)
        ->post('/settings/two-factor/confirm', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('turning 2FA off needs the current password and clears the secret', function (): void {
    $user = twoFactorUser();
    enrolTwoFactor($user);

    $this->actingAs($user)
        ->delete('/settings/two-factor', ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs($user)
        ->delete('/settings/two-factor', ['password' => 'correct-horse-1A'])
        ->assertRedirect();

    $user->refresh();

    // The secret goes too — a later re-enable must not silently reuse a secret
    // the member may already have deleted from their authenticator.
    expect($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull();
});

/* --------------------------------------------------------------- login --- */

test('the right password alone does not sign in an account with 2FA', function (): void {
    $user = twoFactorUser();
    enrolTwoFactor($user);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-1A'])
        ->assertRedirect(route('two-factor.challenge'));

    // The whole point: not authenticated yet. If the session were live here,
    // navigating away from the prompt would bypass the factor entirely.
    $this->assertGuest();
});

test('a valid code completes the sign-in', function (): void {
    $user = twoFactorUser();
    $secret = enrolTwoFactor($user);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-1A']);
    $this->post('/two-factor-challenge', ['code' => currentCode($secret)])
        ->assertRedirect(route('post-login'));

    $this->assertAuthenticatedAs($user);
});

test('a wrong code leaves the caller signed out', function (): void {
    $user = twoFactorUser();
    enrolTwoFactor($user);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-1A']);
    $this->post('/two-factor-challenge', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('the challenge cannot be reached without first passing the password', function (): void {
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect(route('login'));
});

test('an account without 2FA signs in on the password alone, as before', function (): void {
    $user = twoFactorUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-1A'])
        ->assertRedirect(route('post-login'));

    $this->assertAuthenticatedAs($user);
});

test('an abandoned half-finished setup does not gate login', function (): void {
    $user = twoFactorUser();
    // Secret minted, never confirmed — the mis-scanned-QR case.
    $this->actingAs($user)->post('/settings/two-factor');
    auth()->logout();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-1A'])
        ->assertRedirect(route('post-login'));

    $this->assertAuthenticatedAs($user->fresh());
});

/* ---------------------------------------------------- recovery codes --- */

test('a recovery code signs in once and is then spent', function (): void {
    $user = twoFactorUser();
    enrolTwoFactor($user);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-1A']);
    $this->post('/two-factor-challenge', ['code' => 'AAAAA-BBBBB', 'recovery' => true])
        ->assertRedirect(route('post-login'));

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->two_factor_recovery_codes)->toBe(['CCCCC-DDDDD']);

    // Replaying it must fail — a code read off a screenshot is not reusable.
    auth()->logout();
    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-1A']);
    $this->post('/two-factor-challenge', ['code' => 'AAAAA-BBBBB', 'recovery' => true])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

/* ------------------------------------------------------------ secrets --- */

test('the secret and recovery codes are encrypted at rest and never serialised', function (): void {
    $user = twoFactorUser();
    $secret = enrolTwoFactor($user);

    $stored = (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    expect($stored)->not->toBe($secret)
        ->and($stored)->not->toContain($secret)
        ->and($user->fresh()->toArray())->not->toHaveKey('two_factor_secret')
        ->and($user->fresh()->toArray())->not->toHaveKey('two_factor_recovery_codes');
});

/* ----------------------------------------------- security overview --- */

test('the overview reports the real 2FA state rather than always ACTIVE', function (): void {
    $user = twoFactorUser();

    $this->actingAs($user)->get('/settings/security')->assertInertia(function ($page): void {
        $row = collect($page->toArray()['props']['security']['overview'])
            ->firstWhere('label', 'Two-Factor Auth');

        expect($row['value'])->toBe('Not set up')
            ->and($row['tone'])->toBe('bad');
    });

    enrolTwoFactor($user);

    $this->actingAs($user->fresh())->get('/settings/security')->assertInertia(function ($page): void {
        $row = collect($page->toArray()['props']['security']['overview'])
            ->firstWhere('label', 'Two-Factor Auth');

        expect($row['value'])->toBe('Active')
            ->and($row['tone'])->toBe('good');
    });
});

test('password age comes from when the password was actually set', function (): void {
    $user = twoFactorUser();

    // The model stamps this on any save that changes the password, so no call
    // site has to remember to.
    $user->forceFill(['password_changed_at' => now()->subDays(200)])->save();

    $this->actingAs($user)->get('/settings/security')->assertInertia(function ($page): void {
        $row = collect($page->toArray()['props']['security']['overview'])
            ->firstWhere('label', 'Password Age');

        expect($row['value'])->toBe('200 days (Ageing)')
            ->and($row['tone'])->toBe('warn');
    });
});

test('changing the password resets its age without any call site doing so', function (): void {
    $user = twoFactorUser();
    $user->forceFill(['password_changed_at' => now()->subDays(400)])->save();

    $this->actingAs($user)->put('/settings/security', [
        'current_password' => 'correct-horse-1A',
        'password' => 'brand-new-Password-9',
        'password_confirmation' => 'brand-new-Password-9',
    ])->assertRedirect();

    expect($user->refresh()->passwordAgeInDays())->toBe(0);
});

test('the last login device is recorded from the request, not invented', function (): void {
    $user = twoFactorUser();

    $this->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux) Gecko/20100101 Firefox/121.0')
        ->post('/login', ['email' => $user->email, 'password' => 'correct-horse-1A']);

    $user->refresh();

    expect($user->last_login_at)->not->toBeNull()
        ->and($user->last_login_user_agent)->toContain('Firefox');

    $this->get('/settings/security')->assertInertia(
        fn ($page) => expect(collect($page->toArray()['props']['security']['overview'])
            ->firstWhere('label', 'Last Login Device')['value'])
            ->toContain('Firefox'),
    );
});

test('an account that has never signed in says so rather than naming a city', function (): void {
    $user = twoFactorUser();

    $this->actingAs($user)->get('/settings/security')->assertInertia(
        fn ($page) => expect(collect($page->toArray()['props']['security']['overview'])
            ->firstWhere('label', 'Last Login Device')['value'])
            ->toBe('No sign-in recorded'),
    );
});
