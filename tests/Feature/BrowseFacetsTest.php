<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\User;
use App\Support\SboBrowseFacets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * "Explore the Global Directory" — the home page's five browse-by rows.
 *
 * All five linked to /categories whatever the label said, so By Business Size
 * and By Industry landed on the same page. Each is a facet now, with its own
 * index at /browse/{facet}.
 */

function browseSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Browse SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'category' => 'Software Development',
        'country' => 'Nigeria',
        'city' => 'Lagos',
        'address' => 'Central Business District',
        'staff_count' => 5,
        'created_by' => User::factory()->create()->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

function browseViewer(): User
{
    return paidMember([], ['name' => 'Browse Viewer SBO', 'category' => 'Legal Consultants']);
}

test('the home page sends all five rows, each to its own page', function (): void {
    $response = $this->get(route('home'))->assertOk();

    $facets = collect(inertiaProp($response, 'browseFacets'));

    expect($facets->pluck('key')->all())
        ->toBe(['industry', 'country', 'service', 'certification', 'size'])
        // The defect this replaces: every row had the same destination.
        ->and($facets->pluck('url')->unique())->toHaveCount(5)
        ->and($facets->pluck('url')->all())->not->toContain(route('categories.index'));

    // Labels are the design's own.
    expect($facets->pluck('label')->all())->toBe([
        'By Industry', 'By Country', 'By Service', 'By Certification', 'By Business Size',
    ]);
});

test('every row the home page offers resolves', function (): void {
    browseSbo();

    $viewer = browseViewer();

    foreach (collect(inertiaProp($this->get(route('home')), 'browseFacets'))->pluck('url') as $url) {
        $this->actingAs($viewer)->get($url)->assertOk();
    }
});

test('an unknown facet is a 404 rather than an empty page', function (): void {
    $this->actingAs(browseViewer())
        ->get('/browse/by-vibes')
        ->assertNotFound();
});

test('browse pages are behind the members-only paywall like the directory', function (): void {
    $this->get(route('browse.show', 'industry'))->assertRedirect(route('login'));

    // A signed-in member whose business is not yet approved gets the wall, not
    // the index — a browse page lists the membership.
    $unapproved = User::factory()->create(['email_verified_at' => now()]);
    browseSbo(['created_by' => $unapproved->id, 'verification_status' => 'pending']);

    $this->actingAs($unapproved)
        ->get(route('browse.show', 'industry'))
        ->assertRedirect(route('sbo.payment-required'));
});

test('browse by industry counts listings and links into the filtered directory', function (): void {
    browseSbo(['name' => 'Tech One', 'category' => 'Software Development']);
    browseSbo(['name' => 'Tech Two', 'category' => 'Technology Services']);
    browseSbo(['name' => 'Bakery', 'category' => 'Restaurants & Cafés']);

    $response = $this->actingAs(browseViewer())
        ->get(route('browse.show', 'industry'))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page->component('public/BrowseView'));

    $options = collect(inertiaProp($response, 'options'))->keyBy('label');

    // Two categories, one sector.
    expect($options['Technology']['count'])->toBe(2)
        ->and($options['Hospitality & Events']['count'])->toBe(1)
        ->and($options['Technology']['url'])->toBe(route('businesses.index', ['industry' => 'Technology']));

    // And the count is the size of the page it opens.
    $names = directoryNames($this->actingAs(browseViewer())->get($options['Technology']['url']));

    expect($names)->toContain('Tech One')->toContain('Tech Two')->not->toContain('Bakery');
});

test('browse by size bands headcount and the directory filters by the same bands', function (): void {
    browseSbo(['name' => 'Tiny Shop', 'staff_count' => 4]);
    browseSbo(['name' => 'Small Firm', 'staff_count' => 30]);
    browseSbo(['name' => 'Big Firm', 'staff_count' => 900]);

    $response = $this->actingAs(browseViewer())
        ->get(route('browse.show', 'size'))
        ->assertOk();

    $options = collect(inertiaProp($response, 'options'))->keyBy('value');

    expect($options->keys()->all())->toContain('1-10', '11-50', '201+')
        // 51–200 has nothing in it, so it is not offered.
        ->and($options->keys()->all())->not->toContain('51-200');

    $names = directoryNames($this->actingAs(browseViewer())->get($options['11-50']['url']));

    // The boundaries are inclusive and shared, so the band and the result set
    // agree — this is the assertion that catches them drifting apart.
    expect($names)->toContain('Small Firm')
        ->not->toContain('Tiny Shop')
        ->not->toContain('Big Firm');
});

test('browse by certification uses the same definition as the verified filter', function (): void {
    browseSbo(['name' => 'Fully Verified SBO']);
    browseSbo([
        'name' => 'Still Registering SBO',
        'documents_status' => 'pending',
        'documents_compliant_at' => null,
    ]);

    $response = $this->actingAs(browseViewer())
        ->get(route('browse.show', 'certification'))
        ->assertOk();

    $options = collect(inertiaProp($response, 'options'))->keyBy('value');

    expect($options['verified']['label'])->toBe('Fully Verified')
        ->and($options['verified']['url'])->toBe(route('businesses.index', ['status' => 'verified']));

    $verified = directoryNames($this->actingAs(browseViewer())->get($options['verified']['url']));

    expect($verified)->toContain('Fully Verified SBO')
        ->not->toContain('Still Registering SBO');
});

test('browse by country and by service read their own columns', function (): void {
    browseSbo(['name' => 'Ghana Listing', 'country' => 'Ghana', 'category' => 'Legal Consultants']);
    browseSbo(['name' => 'Nigeria Listing', 'country' => 'Nigeria']);

    $countries = collect(
        inertiaProp($this->actingAs(browseViewer())->get(route('browse.show', 'country')), 'options')
    )->pluck('label');

    expect($countries)->toContain('Ghana')->toContain('Nigeria');

    $services = collect(
        inertiaProp($this->actingAs(browseViewer())->get(route('browse.show', 'service')), 'options')
    )->pluck('label');

    // Service is the specific trade, not the sector above it.
    expect($services)->toContain('Legal Consultants')
        ->toContain('Software Development')
        ->not->toContain('Technology');
});

test('a browse page never offers a value with nothing behind it', function (): void {
    browseSbo(['name' => 'Only Listing', 'country' => 'Nigeria']);

    $options = collect(
        inertiaProp($this->actingAs(browseViewer())->get(route('browse.show', 'country')), 'options')
    );

    expect($options)->not->toBeEmpty()
        ->and($options->pluck('count')->filter(fn (int $c): bool => $c < 1)->all())->toBe([]);
});

test('declined listings are counted nowhere', function (): void {
    browseSbo(['name' => 'Declined Listing', 'country' => 'Kenya', 'verification_status' => 'declined']);

    $countries = collect(
        inertiaProp($this->actingAs(browseViewer())->get(route('browse.show', 'country')), 'options')
    )->pluck('label');

    expect($countries)->not->toContain('Kenya');
});

test('the facet menu and the routes it advertises cannot drift apart', function (): void {
    foreach (SboBrowseFacets::menu() as $row) {
        expect(SboBrowseFacets::exists($row['key']))->toBeTrue()
            ->and($row['url'])->toBe(route('browse.show', $row['key']));
    }
});
