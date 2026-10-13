<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * A business maintaining its own public profile.
 *
 * Two things are under test and they pull in opposite directions. The profile
 * has to become editable — before this there was no owner-facing update path
 * at all, and the services, gallery, hours and highlight cards were `const`
 * arrays in BusinessProfileView.vue that read identically on all 900+
 * listings. And the fields the MoC approved have to stay put, or the
 * verification seal ends up attesting to text nobody reviewed.
 */

function profileOwner(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

function profileSbo(User $owner, array $overrides = []): Sbo
{
    return Sbo::create(array_merge([
        'name' => 'Kingdom Media Works',
        'slug' => 'kingdom-media-' . Str::random(6),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
        'category' => 'Media Production',
        'legal_structure' => 'Public Company',
        'rc_number' => 'DEMO-RC-900002',
        'city' => 'Kumasi',
        'country' => 'Ghana',
    ], $overrides));
}

/* ------------------------------------------------------- derived data --- */

test('highlights are derived from the columns the details list already shows', function (): void {
    $sbo = profileSbo(profileOwner(), [
        'staff_count' => 19,
        'established_at' => now()->subYears(4)->startOfYear(),
    ]);

    $sbo->services()->create(['title' => 'Renovation', 'position' => 0]);
    $sbo->services()->create(['title' => 'Interior Design', 'position' => 1]);

    $cards = collect($sbo->highlights())->keyBy('key');

    // The old hard-coded cards claimed 8 staff while the details list directly
    // above said 19. Both now read staff_count, so they cannot disagree.
    expect($cards['staff']['value'])->toBe('19')
        ->and($cards['services']['value'])->toBe('2 Services')
        ->and($cards['years']['value'])->toBe('4 Yrs');
});

test('a highlight with nothing behind it is omitted rather than shown as zero', function (): void {
    $sbo = profileSbo(profileOwner(), ['staff_count' => null, 'established_at' => null]);

    // No services, no staff count, no founding date, and no SBO reviews to
    // rate — so the section has nothing to say and says nothing.
    expect($sbo->highlights())->toBe([]);
});

test('a service count of one is not pluralised', function (): void {
    $sbo = profileSbo(profileOwner(), ['staff_count' => null, 'established_at' => null]);
    $sbo->services()->create(['title' => 'Land Acquisition', 'position' => 0]);

    expect($sbo->highlights()[0]['value'])->toBe('1 Service');
});

test('an unset day is left off the profile but a closed day is printed', function (): void {
    $sbo = profileSbo(profileOwner());

    $sbo->openingHours()->create([
        'day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '18:00', 'is_closed' => false,
    ]);
    $sbo->openingHours()->create(['day_of_week' => 0, 'is_closed' => true]);
    // Tuesday through Saturday are never set.

    $rows = $sbo->refresh()->openingHoursForDisplay();

    expect($rows)->toBe([
        ['day' => 'Monday', 'time' => '8:00 AM — 6:00 PM'],
        ['day' => 'Sunday', 'time' => 'Closed'],
    ]);
});

test('two listings do not share services, hours or highlights', function (): void {
    $owner = profileOwner();
    $a = profileSbo($owner, ['slug' => 'alpha-ltd', 'staff_count' => 5]);
    $b = profileSbo($owner, ['slug' => 'beta-ltd', 'staff_count' => 40]);

    $a->services()->create(['title' => 'Only on Alpha', 'position' => 0]);
    $a->openingHours()->create(['day_of_week' => 1, 'is_closed' => true]);

    expect($b->refresh()->services()->count())->toBe(0)
        ->and($b->openingHoursForDisplay())->toBe([])
        ->and(collect($a->highlights())->firstWhere('key', 'staff')['value'])->toBe('5')
        ->and(collect($b->highlights())->firstWhere('key', 'staff')['value'])->toBe('40');
});

/* ------------------------------------------------------------ editing --- */

test('an owner can open the editor and sees the whole week plus every gallery tab', function (): void {
    $owner = profileOwner();
    $sbo = profileSbo($owner, ['staff_count' => 19]);
    $sbo->services()->create(['title' => 'Renovation', 'position' => 0]);
    $sbo->openingHours()->create([
        'day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '18:00', 'is_closed' => false,
    ]);

    $this->actingAs($owner)
        ->get("/sbo/listings/{$sbo->id}/edit")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];

            expect($props['sbo']['staff_count'])->toBe(19)
                ->and($props['services'])->toHaveCount(1)
                // All seven days, so the editor can render a full week rather
                // than only the days already saved.
                ->and($props['hours'])->toHaveCount(7)
                // Monday-first reading order.
                ->and($props['hours'][0]['day'])->toBe('Monday')
                ->and($props['hours'][0]['set'])->toBeTrue()
                ->and($props['hours'][0]['opens_at'])->toBe('08:00')
                ->and($props['hours'][1]['set'])->toBeFalse()
                // Every collection is offered for upload, even empty ones —
                // unlike the public profile, which hides the empty tabs.
                ->and($props['gallery'])->toHaveCount(4)
                // The approved fields are shown, but only to read.
                ->and(collect($props['sbo']['locked'])->pluck('value'))
                ->toContain('DEMO-RC-900002');
        });
});

test('an owner can update the open profile fields', function (): void {
    $owner = profileOwner();
    $sbo = profileSbo($owner);

    $this->actingAs($owner)
        ->patch("/sbo/listings/{$sbo->id}/profile", [
            'description' => 'We build broadcast studios.',
            'website' => 'kingdommedia.example',
            'primary_contact_email' => 'hello@kingdommedia.example',
            'phone' => '+233200000002',
            'staff_count' => 24,
        ])
        ->assertRedirect();

    expect($sbo->refresh())
        ->description->toBe('We build broadcast studios.')
        ->website->toBe('kingdommedia.example')
        ->staff_count->toBe(24);
});

test('the fields the registry approved cannot be written through the profile endpoint', function (): void {
    $owner = profileOwner();
    $sbo = profileSbo($owner);

    $this->actingAs($owner)->patch("/sbo/listings/{$sbo->id}/profile", [
        'description' => 'Legitimate change.',
        // None of these are in the validated set, so none should land.
        'name' => 'Totally Different Company',
        'rc_number' => 'FORGED-0001',
        'legal_structure' => 'Sole Proprietorship',
        'category' => 'Banking',
        'verification_status' => 'approved',
    ]);

    expect($sbo->refresh())
        ->description->toBe('Legitimate change.')
        ->name->toBe('Kingdom Media Works')
        ->rc_number->toBe('DEMO-RC-900002')
        ->legal_structure->toBe('Public Company')
        ->category->toBe('Media Production');
});

test('saving services replaces the list and keeps the order the owner set', function (): void {
    $owner = profileOwner();
    $sbo = profileSbo($owner);
    $sbo->services()->create(['title' => 'Will be replaced', 'position' => 0]);

    $this->actingAs($owner)
        ->put("/sbo/listings/{$sbo->id}/services", [
            'services' => [
                ['title' => 'Land Acquisition', 'body' => 'Verified secure land deals.'],
                ['title' => 'Interior Design', 'body' => null],
            ],
        ])
        ->assertRedirect();

    $services = $sbo->refresh()->services;

    expect($services->pluck('title')->all())->toBe(['Land Acquisition', 'Interior Design'])
        ->and($services->pluck('position')->all())->toBe([0, 1])
        ->and($services->first()->body)->toBe('Verified secure land deals.');
});

test('an empty services payload clears the section', function (): void {
    $owner = profileOwner();
    $sbo = profileSbo($owner);
    $sbo->services()->create(['title' => 'Interior Design', 'position' => 0]);

    $this->actingAs($owner)
        ->put("/sbo/listings/{$sbo->id}/services", ['services' => []])
        ->assertRedirect();

    expect($sbo->refresh()->services()->count())->toBe(0);
});

test('a closing time must be later than the opening time', function (): void {
    $owner = profileOwner();
    $sbo = profileSbo($owner);

    $this->actingAs($owner)
        ->put("/sbo/listings/{$sbo->id}/hours", [
            'hours' => [
                ['day_of_week' => 1, 'is_closed' => false, 'opens_at' => '18:00', 'closes_at' => '08:00'],
            ],
        ])
        ->assertSessionHasErrors('hours.0.closes_at');

    expect($sbo->refresh()->openingHours()->count())->toBe(0);
});

test('marking a day closed stores no times', function (): void {
    $owner = profileOwner();
    $sbo = profileSbo($owner);

    $this->actingAs($owner)
        ->put("/sbo/listings/{$sbo->id}/hours", [
            'hours' => [['day_of_week' => 0, 'is_closed' => true, 'opens_at' => null, 'closes_at' => null]],
        ])
        ->assertRedirect();

    $sunday = $sbo->refresh()->openingHours()->first();

    expect($sunday->is_closed)->toBeTrue()
        ->and($sunday->opens_at)->toBeNull()
        ->and($sunday->closes_at)->toBeNull();
});

/* ------------------------------------------------------------ gallery --- */

test('an owner can add and remove a gallery photo', function (): void {
    Storage::fake('public');

    $owner = profileOwner();
    $sbo = profileSbo($owner);

    $this->actingAs($owner)
        ->post("/sbo/listings/{$sbo->id}/gallery", [
            'collection' => 'gallery_office',
            'image' => UploadedFile::fake()->image('office.jpg', 800, 600),
        ])
        ->assertRedirect();

    expect($sbo->refresh()->getMedia('gallery_office'))->toHaveCount(1);

    $media = $sbo->getFirstMedia('gallery_office');

    $this->actingAs($owner)
        ->delete("/sbo/listings/{$sbo->id}/gallery/{$media->id}")
        ->assertRedirect();

    expect($sbo->refresh()->getMedia('gallery_office'))->toHaveCount(0);
});

test('a gallery upload must name a real collection', function (): void {
    Storage::fake('public');

    $owner = profileOwner();
    $sbo = profileSbo($owner);

    $this->actingAs($owner)
        ->post("/sbo/listings/{$sbo->id}/gallery", [
            'collection' => 'compliance_documents',
            'image' => UploadedFile::fake()->image('sneaky.jpg'),
        ])
        ->assertSessionHasErrors('collection');
});

/* ------------------------------------------------------ authorization --- */

test('a listing owned by someone else is not editable and does not confirm it exists', function (): void {
    $mine = profileOwner();
    $theirs = profileSbo(profileOwner());

    $this->actingAs($mine)->get("/sbo/listings/{$theirs->id}/edit")->assertNotFound();
    $this->actingAs($mine)
        ->patch("/sbo/listings/{$theirs->id}/profile", ['description' => 'Not mine.'])
        ->assertNotFound();
    $this->actingAs($mine)
        ->put("/sbo/listings/{$theirs->id}/services", ['services' => []])
        ->assertNotFound();

    expect($theirs->refresh()->description)->toBeNull();
});

test('one owner cannot delete another listing photo by guessing its id', function (): void {
    Storage::fake('public');

    $attacker = profileOwner();
    $mine = profileSbo($attacker);
    $victim = profileSbo(profileOwner());

    $victim->addMedia(UploadedFile::fake()->image('theirs.jpg'))
        ->toMediaCollection('gallery_office');
    $media = $victim->getFirstMedia('gallery_office');

    /*
     * The attacker owns $mine, so authorizeOwner() passes. The media id
     * belongs to $victim — route-model binding resolves it happily, which is
     * why the controller also checks the file hangs off THIS listing.
     */
    $this->actingAs($attacker)
        ->delete("/sbo/listings/{$mine->id}/gallery/{$media->id}")
        ->assertNotFound();

    expect($victim->refresh()->getMedia('gallery_office'))->toHaveCount(1);
});

test('a guest is sent to log in rather than shown the editor', function (): void {
    $sbo = profileSbo(profileOwner());

    $this->get("/sbo/listings/{$sbo->id}/edit")->assertRedirect(route('login'));
});
