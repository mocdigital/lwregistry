<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Timezones;
use Illuminate\Support\Carbon;

/*
 * The Timezone field on /settings/profile.
 *
 * It was a text box reading "East Africa Time (GMT+3)" that saved nothing, so a
 * member in Lagos read every deadline, message time and posting stamp in UTC
 * while being told it was Nairobi.
 *
 * Storage stays UTC. These pin the DISPLAY side, and the one rule that matters:
 * a preference nobody set falls back to the app's zone rather than a guess.
 */

test('an unset preference means the app’s own zone, not a guess', function (): void {
    $user = User::factory()->create();

    expect(Timezones::for($user))->toBe(config('app.timezone'))
        ->and(Timezones::for(null))->toBe(config('app.timezone'));
});

test('a saved zone is what timestamps are rendered on', function (): void {
    $user = User::factory()->create();
    $user->preferences = ['regional' => ['timezone' => 'Africa/Lagos']];
    $user->save();

    $this->actingAs($user->fresh());

    // 23:30 UTC is 00:30 the NEXT day in Lagos — the case a naive
    // implementation gets wrong and nobody notices until a deadline slips.
    $at = Carbon::parse('2026-08-06 23:30:00', 'UTC');

    expect(Timezones::format($at, 'M j, Y g:ia'))->toBe('Aug 7, 2026 12:30am')
        ->and($at->format('M j, Y g:ia'))->toBe('Aug 6, 2026 11:30pm');
});

test('null in, null out — every column this formats is nullable', function (): void {
    expect(Timezones::format(null, 'M j, Y'))->toBeNull();
});

test('the panel saves a zone through the preferences endpoint', function (): void {
    $user = paidMember();

    $this->actingAs($user)
        ->patch(route('settings.preferences.update'), [
            'regional' => ['timezone' => 'Europe/London'],
        ])
        ->assertRedirect();

    expect(Timezones::for($user->fresh()))->toBe('Europe/London');
});

test('a zone that is not a real identifier is refused', function (): void {
    $user = paidMember();

    $this->actingAs($user)
        ->patch(route('settings.preferences.update'), [
            'regional' => ['timezone' => 'Middle/Earth'],
        ])
        ->assertSessionHasErrors('regional.timezone');

    expect(Timezones::for($user->fresh()))->toBe(config('app.timezone'));
});

test('any IANA zone saves, not only the ones the select offers', function (): void {
    // A value stored before the list changed must not become unsavable.
    $user = paidMember();

    $this->actingAs($user)
        ->patch(route('settings.preferences.update'), [
            'regional' => ['timezone' => 'Indian/Mauritius'],
        ])
        ->assertSessionHasNoErrors();

    expect(Timezones::for($user->fresh()))->toBe('Indian/Mauritius');
});

test('the settings screen ships the zone list and the active one', function (): void {
    $user = paidMember();
    $user->preferences = ['regional' => ['timezone' => 'Africa/Accra']];
    $user->save();

    $this->actingAs($user->fresh())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activeTimezone', 'Africa/Accra')
            ->has('timezones.0.value')
            ->has('timezones.0.label')
            ->has('timezones.0.group'));
});
