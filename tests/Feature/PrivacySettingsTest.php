<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\SboView;
use App\Models\User;
use App\Support\Privacy;
use Illuminate\Support\Str;

/*
 * Settings › Privacy.
 *
 * The rail carried the word "Privacy" and nothing was behind it. A panel that
 * stored four booleans and changed nothing would be no better, so each switch
 * is asserted where the visibility actually happens — the directory query, the
 * profile payload, the connection endpoint, the view record.
 */

function privacyOwner(array $privacy = []): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    if ([] !== $privacy) {
        $user->preferences = ['privacy' => $privacy];
        $user->save();
    }

    return $user->fresh();
}

function privacySbo(User $owner, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Privacy Co ' . Str::random(4);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
        'primary_contact_email' => 'hello@privacy.test',
        'phone' => '+234 800 000 0000',
    ], $overrides));
}

test('an untouched account is listed, which is the default the panel draws', function (): void {
    $owner = privacyOwner();

    expect(Privacy::allows($owner, Privacy::LISTED))->toBeTrue()
        ->and(Privacy::allows($owner, Privacy::CONNECTIONS))->toBeTrue();
});

test('turning the directory listing off removes it from the directory', function (): void {
    $listed = privacySbo(privacyOwner(), ['name' => 'Listed Business']);
    privacySbo(privacyOwner([Privacy::LISTED => false]), ['name' => 'Hidden Business']);

    $names = directoryNames(
        $this->actingAs(paidMember())->get(route('businesses.index'))->assertSuccessful()
    );

    expect($names)->toContain($listed->name)
        ->and($names)->not->toContain('Hidden Business');
});

test('the profile page itself still opens by its direct link', function (): void {
    // Unlisted is not unpublished — a customer who already holds the link must
    // not hit a dead end.
    $sbo = privacySbo(privacyOwner([Privacy::LISTED => false]));

    $this->actingAs(paidMember())
        ->get(route('businesses.show', $sbo->slug))
        ->assertOk();
});

test('hiding contact details drops the email and phone rows, not the whole panel', function (): void {
    $sbo = privacySbo(privacyOwner([Privacy::CONTACT_DETAILS => false]), ['website' => 'example.test']);

    $this->actingAs(paidMember())
        ->get(route('businesses.show', $sbo->slug))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $labels = collect($page->toArray()['props']['business']['details'])->pluck('value');

            expect($labels)->not->toContain('hello@privacy.test')
                ->and($labels)->not->toContain('+234 800 000 0000')
                // The rest of the panel is untouched.
                ->and($labels)->toContain('example.test');
        });
});

test('a closed business refuses a connection request, not just hides the button', function (): void {
    $recipient = privacySbo(privacyOwner([Privacy::CONNECTIONS => false]));

    $requester = paidMember();
    $mySbo = $requester->sbos()->first();

    $this->actingAs($requester)
        ->post(route('sbo.connections.store'), [
            'requester_sbo_id' => $mySbo->id,
            'recipient_sbo_id' => $recipient->id,
        ])
        ->assertSessionHas('status', 'connection-closed');

    expect($recipient->receivedConnections()->count())->toBe(0);
});

test('a viewer who opted out of sharing identity is still counted, anonymously', function (): void {
    $sbo = privacySbo(privacyOwner());
    $viewer = paidMember(['email' => 'shy@example.test']);

    $viewer->preferences = ['privacy' => [Privacy::VIEW_IDENTITY => false]];
    $viewer->save();

    $this->actingAs($viewer->fresh())->get(route('businesses.show', $sbo->slug))->assertOk();

    $view = SboView::where('sbo_id', $sbo->id)->first();

    expect($view)->not->toBeNull()
        ->and($view->viewer_id)->toBeNull();
});

test('the privacy panel saves through the preferences endpoint', function (): void {
    $user = paidMember();

    $this->actingAs($user)
        ->patch(route('settings.preferences.update'), [
            'privacy' => [Privacy::LISTED => false],
        ])
        ->assertRedirect();

    expect(Privacy::allows($user->fresh(), Privacy::LISTED))->toBeFalse()
        // Untouched switches keep their defaults rather than being wiped.
        ->and(Privacy::allows($user->fresh(), Privacy::CONNECTIONS))->toBeTrue();
});

test('the privacy tab renders rather than falling through to notifications', function (): void {
    $this->actingAs(paidMember())
        ->get(route('settings.privacy'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'privacy')
            ->has('preferences.privacy'));
});
