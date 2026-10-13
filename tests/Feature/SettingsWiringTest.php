<?php

declare(strict_types=1);

use App\Models\Country;
use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * /settings.
 *
 * The profile panel could save four text fields. Everything else on the screen
 * was drawn and inert: the avatar showed the initials "KJ" for every member
 * with two bare buttons beside it, and the Appearance and Notifications panels
 * were client-side `ref`s that reset on reload.
 */

function settingsMember(): User
{
    return paidMember(['firstname' => 'Chidi', 'lastname' => 'Okafor']);
}

test('the settings screen sends the avatar and the stored preferences', function (): void {
    $response = $this->actingAs(settingsMember())
        ->get(route('profile.edit'))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page->component('account/SettingsView'));

    // Initials came from the member, not from "KJ".
    expect(inertiaProp($response, 'profile.initials'))->toBe('CO')
        ->and(inertiaProp($response, 'profile.photoUrl'))->toBeNull();

    // A member who has never saved gets exactly what the frames draw.
    expect(inertiaProp($response, 'preferences.appearance.theme'))->toBe('light')
        // 'right' is the real default: every built screen puts the rail
        // there, whatever the frame's label says.
        ->and(inertiaProp($response, 'preferences.appearance.railSide'))->toBe('right')
        ->and(inertiaProp($response, 'preferences.notifications.applicationUpdates'))->toBeTrue()
        ->and(inertiaProp($response, 'preferences.notifications.marketingResearch'))->toBeFalse();
});

test('a member can upload a profile photo', function (): void {
    Storage::fake('public');

    $user = settingsMember();

    $this->actingAs($user)
        ->post(route('settings.photo.update'), [
            'photo' => UploadedFile::fake()->image('me.jpg', 400, 400),
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'photo-updated');

    $path = $user->refresh()->getAttributes()['profile_photo_path'];

    expect($path)->not->toBeNull();
    Storage::disk('public')->assertExists($path);

    // And it then appears on the screens that show an avatar.
    $response = $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    expect(inertiaProp($response, 'profile.photoUrl'))->toContain($path);
});

test('uploading a new photo deletes the one it replaces', function (): void {
    Storage::fake('public');

    $user = settingsMember();

    $this->actingAs($user)->post(route('settings.photo.update'), [
        'photo' => UploadedFile::fake()->image('first.jpg'),
    ]);
    $first = $user->refresh()->getAttributes()['profile_photo_path'];

    $this->actingAs($user)->post(route('settings.photo.update'), [
        'photo' => UploadedFile::fake()->image('second.jpg'),
    ]);
    $second = $user->refresh()->getAttributes()['profile_photo_path'];

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

test('the panel rejects what it tells the member it rejects', function (): void {
    Storage::fake('public');

    // "JPG or PNG. Max size 2MB."
    $this->actingAs(settingsMember())
        ->post(route('settings.photo.update'), ['photo' => UploadedFile::fake()->create('cv.pdf', 100)])
        ->assertSessionHasErrors('photo');

    $this->actingAs(settingsMember())
        ->post(route('settings.photo.update'), ['photo' => UploadedFile::fake()->image('huge.jpg')->size(3000)])
        ->assertSessionHasErrors('photo');
});

test('a photo can be removed, falling back to initials', function (): void {
    Storage::fake('public');

    $user = settingsMember();

    $this->actingAs($user)->post(route('settings.photo.update'), [
        'photo' => UploadedFile::fake()->image('me.jpg'),
    ]);
    $path = $user->refresh()->getAttributes()['profile_photo_path'];

    $this->actingAs($user)
        ->delete(route('settings.photo.destroy'))
        ->assertRedirect()
        ->assertSessionHas('status', 'photo-removed');

    expect($user->refresh()->getAttributes()['profile_photo_path'])->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('appearance preferences survive a reload', function (): void {
    $user = settingsMember();

    $this->actingAs($user)
        ->patch(route('settings.preferences.update'), [
            'appearance' => [
                'theme' => 'dark',
                'accent' => '#8b5cf6',
                'fontSize' => 'Large',
                'density' => 'compact',
                'railSide' => 'right',
                'toggles' => ['reduceMotion' => true],
            ],
        ])
        ->assertRedirect();

    $response = $this->actingAs($user)->get(route('appearance.edit'))->assertOk();

    expect(inertiaProp($response, 'preferences.appearance.theme'))->toBe('dark')
        ->and(inertiaProp($response, 'preferences.appearance.accent'))->toBe('#8b5cf6')
        ->and(inertiaProp($response, 'preferences.appearance.density'))->toBe('compact')
        ->and(inertiaProp($response, 'preferences.appearance.toggles.reduceMotion'))->toBeTrue()
        // Untouched switches keep their designed default.
        ->and(inertiaProp($response, 'preferences.appearance.toggles.screenReaderOptimizations'))->toBeTrue();
});

test('notification preferences survive a reload', function (): void {
    $user = settingsMember();

    $this->actingAs($user)
        ->patch(route('settings.preferences.update'), [
            'notifications' => ['applicationUpdates' => false, 'weeklyNewsletter' => true],
        ])
        ->assertRedirect();

    $response = $this->actingAs($user)->get(route('settings.notifications'))->assertOk();

    expect(inertiaProp($response, 'preferences.notifications.applicationUpdates'))->toBeFalse()
        ->and(inertiaProp($response, 'preferences.notifications.weeklyNewsletter'))->toBeTrue()
        ->and(inertiaProp($response, 'preferences.notifications.paymentConfirmations'))->toBeTrue();
});

test('saving one panel does not reset the other', function (): void {
    $user = settingsMember();

    $this->actingAs($user)->patch(route('settings.preferences.update'), [
        'notifications' => ['networkActivity' => false],
    ]);
    $this->actingAs($user)->patch(route('settings.preferences.update'), [
        'appearance' => ['theme' => 'dark'],
    ]);

    $prefs = UserPreferences::for($user->refresh());

    expect($prefs['notifications']['networkActivity'])->toBeFalse()
        ->and($prefs['appearance']['theme'])->toBe('dark');
});

test('an unknown preference is rejected rather than stored', function (): void {
    $user = settingsMember();

    $this->actingAs($user)->patch(route('settings.preferences.update'), [
        'appearance' => ['theme' => 'neon'],
    ])->assertSessionHasErrors('appearance.theme');

    // And a key nobody defined is dropped, not written into the column.
    $this->actingAs($user)->patch(route('settings.preferences.update'), [
        'notifications' => ['somethingInvented' => true],
    ])->assertRedirect();

    expect(UserPreferences::for($user->refresh())['notifications'])
        ->not->toHaveKey('somethingInvented');
});

test('the profile fields still save, as they did before', function (): void {
    $user = settingsMember();

    $this->actingAs($user)
        ->patch(route('settings.profile.update'), [
            'firstname' => 'Ada',
            'lastname' => 'Nwosu',
            'email' => 'ada@example.test',
            'phone' => '+2348031234567',
        ])
        ->assertRedirect();

    expect($user->refresh()->firstname)->toBe('Ada')
        ->and($user->email)->toBe('ada@example.test')
        // Changing the address invalidates the previous verification.
        ->and($user->email_verified_at)->toBeNull();
});

test('a local profile phone number is normalised before the user model saves', function (): void {
    Country::query()->firstOrCreate(['name' => 'Nigeria'], ['iso2' => 'NG']);

    $user = paidMember(
        ['firstname' => 'Ada', 'lastname' => 'Okafor'],
        ['country' => 'Nigeria', 'owner_country' => 'Nigeria'],
    );

    $this->actingAs($user)
        ->patch(route('settings.profile.update'), [
            'firstname' => 'Ada',
            'lastname' => 'Okafor',
            'email' => $user->email,
            'phone' => '08031234567',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect((string) $user->refresh()->phone)->toBe('+2348031234567');
});

test('an invalid profile phone number is a validation error, not a server error', function (): void {
    Country::query()->firstOrCreate(['name' => 'Nigeria'], ['iso2' => 'NG']);

    $user = paidMember([], ['country' => 'Nigeria', 'owner_country' => 'Nigeria']);

    $this->actingAs($user)
        ->patch(route('settings.profile.update'), [
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'phone' => '123',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('phone');
});

test('settings are signed-in only', function (): void {
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->post(route('settings.photo.update'))->assertRedirect(route('login'));
    $this->patch(route('settings.preferences.update'))->assertRedirect(route('login'));
});
