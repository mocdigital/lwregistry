<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\User;
use App\Support\Geocoder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * The location map on a business profile.
 *
 * It was a grey box reading "Map — pending embed": nothing on the model could
 * place a pin, because `address`, `city`, `state` and `country` are free text
 * and a map needs a point.
 *
 * Nominatim is faked here. A test that called it for real would be slow, would
 * breach their usage policy from CI, and would fail whenever OpenStreetMap was
 * having a bad day — none of which says anything about this code.
 */

function mapOwner(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

function mapSbo(array $overrides = []): Sbo
{
    return Sbo::create(array_merge([
        'name' => 'Map Co ' . Str::random(4),
        'slug' => 'map-co-' . Str::random(6),
        'created_by' => mapOwner()->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
        'address' => '12 Adeola Odeku Street',
        'city' => 'Lagos',
        'state' => 'Lagos State',
        'country' => 'Nigeria',
    ], $overrides));
}

function fakeGeocoder(array $hits): void
{
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response($hits),
    ]);
}

test('an address is resolved to a point and cached on the listing', function (): void {
    fakeGeocoder([['lat' => '6.4281', 'lon' => '3.4219']]);

    $sbo = Geocoder::ensure(mapSbo());

    expect($sbo->latitude)->toBe(6.4281)
        ->and($sbo->longitude)->toBe(3.4219)
        ->and($sbo->geocoded_address)->toBe('12 Adeola Odeku Street, Lagos, Lagos State, Nigeria');
});

test('a business is geocoded once, not on every page view', function (): void {
    fakeGeocoder([['lat' => '6.4281', 'lon' => '3.4219']]);

    $sbo = Geocoder::ensure(mapSbo());
    Geocoder::ensure($sbo->fresh());
    Geocoder::ensure($sbo->fresh());

    // Nominatim's policy is one request a second; a lookup per page view would
    // get the whole registry blocked.
    Http::assertSentCount(1);
});

test('an unrecognised street falls back to the city rather than losing the map', function (): void {
    /*
     * The demo data case: "1 Demo Way, Abuja" is a street OpenStreetMap has
     * never heard of. A pin on the right city beats a grey box, and the exact
     * address is printed beside it either way.
     */
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::sequence()
            ->push([])                                  // full address: no hit
            ->push([['lat' => '9.0643', 'lon' => '7.4893']]), // city, state, country
    ]);

    $sbo = Geocoder::ensure(mapSbo(['address' => '1 Demo Way', 'city' => 'Abuja']));

    expect($sbo->latitude)->toBe(9.0643)
        ->and($sbo->longitude)->toBe(7.4893);
});

test('an address that cannot be resolved at all leaves the map absent, not broken', function (): void {
    fakeGeocoder([]);

    $sbo = Geocoder::ensure(mapSbo());

    expect($sbo->latitude)->toBeNull()
        // The attempt is recorded, so the next view does not try again.
        ->and($sbo->geocoded_at)->not->toBeNull();
});

test('a geocoder outage does not break the profile', function (): void {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

    $owner = mapOwner();
    $sbo = mapSbo(['created_by' => $owner->id]);

    // The page still renders; the map block shows the address instead.
    $this->actingAs($owner)
        ->get(route('businesses.show', $sbo->slug))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $map = $page->toArray()['props']['map'];

            expect($map['embedUrl'])->toBeNull()
                ->and($map['address'])->toBe('12 Adeola Odeku Street, Lagos, Lagos State, Nigeria')
                // A search link still finds the place without a pin.
                ->and($map['linkUrl'])->toContain('openstreetmap.org/search');
        });
});

test('the profile sends an embed built around the resolved point', function (): void {
    fakeGeocoder([['lat' => '6.4281', 'lon' => '3.4219']]);

    $owner = mapOwner();
    $sbo = mapSbo(['created_by' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('businesses.show', $sbo->slug))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $map = $page->toArray()['props']['map'];

            expect($map['embedUrl'])->toContain('openstreetmap.org/export/embed.html')
                // The marker is what actually points at the business.
                ->and($map['embedUrl'])->toContain('marker=6.4281,3.4219')
                ->and($map['embedUrl'])->toContain('bbox=')
                ->and($map['latitude'])->toBe(6.4281);
        });
});

test('moving the address re-resolves rather than leaving the old pin', function (): void {
    /*
     * One sequence rather than two `Http::fake()` calls: fakes MERGE, so a
     * second stub for the same URL never gets a look in — the first keeps
     * answering and the test silently asserts nothing.
     */
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::sequence()
            ->push([['lat' => '6.4281', 'lon' => '3.4219']])
            ->push([['lat' => '9.0643', 'lon' => '7.4893']]),
    ]);

    $sbo = Geocoder::ensure(mapSbo());
    expect($sbo->latitude)->toBe(6.4281);

    $sbo->forceFill(['city' => 'Abuja', 'state' => 'FCT'])->saveQuietly();
    $moved = Geocoder::ensure($sbo->fresh());

    // The stored address no longer matches, so the old pin is not reused.
    expect($moved->latitude)->toBe(9.0643);
});

/* ------------------------------------------------ similar businesses --- */

test('the similar-businesses widget links to listings that actually exist', function (): void {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

    $owner = mapOwner();
    $subject = mapSbo(['created_by' => $owner->id, 'category' => 'Transport & Logistics']);
    $peer = mapSbo(['category' => 'Transport & Logistics']);

    $this->actingAs($owner)
        ->get(route('businesses.show', $subject->slug))
        ->assertOk()
        ->assertInertia(function ($page) use ($subject): void {
            $similar = collect($page->toArray()['props']['similar']);

            /*
             * The widget listed three invented companies — "cloudtrac",
             * "dma-shelvings", "strategic-workforce" — so every link 404'd.
             */
            expect($similar)->not->toBeEmpty()
                ->and($similar->pluck('slug'))->not->toContain('cloudtrac')
                // Never itself.
                ->and($similar->pluck('slug'))->not->toContain($subject->slug);
        });
});

test('every similar link resolves to a real profile', function (): void {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

    $owner = mapOwner();
    $subject = mapSbo(['created_by' => $owner->id]);
    mapSbo();
    mapSbo();

    $slugs = [];

    $this->actingAs($owner)
        ->get(route('businesses.show', $subject->slug))
        ->assertInertia(function ($page) use (&$slugs): void {
            $slugs = collect($page->toArray()['props']['similar'])->pluck('slug')->all();
        });

    foreach ($slugs as $slug) {
        $this->actingAs($owner)->get(route('businesses.show', $slug))->assertOk();
    }
});
