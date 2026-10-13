<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Directory search.
 *
 * These asserted on rendered HTML (`assertSee('Parallax Bank Nigeria')`), which
 * stopped meaning anything once `/businesses` became an Inertia screen: the
 * page body is a JSON blob in a data attribute, so a name could "appear"
 * because it was in the props of a component that never rendered it — or not
 * appear at all despite being in the result set. They assert the payload the
 * screen is actually given now.
 *
 * They also ran as a guest. The registry is members-only and paid, so the
 * viewer is a paid member; the listings being searched are other people's.
 */

function homePageSearchSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Home Search SBO ' . Str::random(6);
    $approved = (bool) ($overrides['approved'] ?? true);

    unset($overrides['approved']);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'category' => 'Technology',
        'city' => 'Lagos',
        'address' => 'Central Business District',
        'description' => 'SBO directory listing',
        'created_by' => User::factory()->create()->id,
        'verification_status' => $approved ? 'approved' : 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
    ], $overrides));
}

test('home page search only returns approved sbos', function (): void {
    homePageSearchSbo(['name' => 'Verified Tech Company', 'approved' => true]);
    homePageSearchSbo(['name' => 'Unverified Tech Company', 'approved' => false]);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))
            ->get(route('businesses.index', ['search' => 'Tech', 'from_home' => '1']))
            ->assertSuccessful()
    );

    expect($names)->toContain('Verified Tech Company')
        ->and($names)->not->toContain('Unverified Tech Company');
});

test('home page search finds businesses by name', function (): void {
    homePageSearchSbo(['name' => 'Parallax Bank Nigeria', 'description' => 'A banking service']);
    homePageSearchSbo(['name' => 'Other Business', 'description' => 'Something else']);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))
            ->get(route('businesses.index', ['search' => 'Parallax', 'from_home' => '1']))
            ->assertSuccessful()
    );

    expect($names)->toContain('Parallax Bank Nigeria')
        ->and($names)->not->toContain('Other Business');
});

test('home page search finds businesses by description', function (): void {
    homePageSearchSbo([
        'name' => 'Example Corp',
        'description' => 'We specialize in financial technology solutions',
    ]);
    homePageSearchSbo(['name' => 'Other Business', 'description' => 'General services']);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))
            ->get(route('businesses.index', ['search' => 'financial technology', 'from_home' => '1']))
            ->assertSuccessful()
    );

    expect($names)->toContain('Example Corp')
        ->and($names)->not->toContain('Other Business');
});

test('home page search finds businesses by address', function (): void {
    homePageSearchSbo(['name' => 'Tech Solutions Ltd', 'address' => 'Innovation Hub, Victoria Island']);
    homePageSearchSbo(['name' => 'Other Business', 'address' => 'Regular Street']);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))
            ->get(route('businesses.index', ['search' => 'Innovation Hub', 'from_home' => '1']))
            ->assertSuccessful()
    );

    expect($names)->toContain('Tech Solutions Ltd')
        ->and($names)->not->toContain('Other Business');
});

test('home page location search uses partial matching', function (): void {
    homePageSearchSbo(['name' => 'NYC Business', 'city' => 'New York']);
    homePageSearchSbo(['name' => 'New Haven Business', 'city' => 'New Haven']);
    homePageSearchSbo(['name' => 'LA Business', 'city' => 'Los Angeles']);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))
            ->get(route('businesses.index', ['location' => 'New', 'from_home' => '1']))
            ->assertSuccessful()
    );

    expect($names)->toContain('NYC Business')
        ->and($names)->toContain('New Haven Business')
        ->and($names)->not->toContain('LA Business');
});

test('home page search combines search and location filters', function (): void {
    homePageSearchSbo(['name' => 'Lagos Tech Company', 'description' => 'Technology services', 'city' => 'Lagos']);
    homePageSearchSbo(['name' => 'Abuja Tech Company', 'description' => 'Technology services', 'city' => 'Abuja']);
    // Its own category, because the fixture default is 'Technology' and the
    // search now covers the taxonomy — a restaurant filed under Technology
    // would match the term for a real reason and mask what is being tested.
    homePageSearchSbo([
        'name' => 'Lagos Restaurant',
        'category' => 'Restaurants & Cafés',
        'description' => 'Food and dining',
        'city' => 'Lagos',
    ]);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))
            ->get(route('businesses.index', [
                'search' => 'Technology',
                'location' => 'Lagos',
                'from_home' => '1',
            ]))
            ->assertSuccessful()
    );

    expect($names)->toContain('Lagos Tech Company')
        ->and($names)->not->toContain('Abuja Tech Company')
        ->and($names)->not->toContain('Lagos Restaurant');
});

test('home page search with empty query returns all approved businesses', function (): void {
    foreach (range(1, 3) as $index) {
        homePageSearchSbo(['name' => "Approved Search SBO {$index}"]);
    }

    foreach (range(1, 2) as $index) {
        homePageSearchSbo(['name' => "Pending Search SBO {$index}", 'approved' => false]);
    }

    $response = $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))
        ->get(route('businesses.index', ['from_home' => '1']))
        ->assertSuccessful();

    // Was `viewData('businesses')->total()`, which no longer exists — the
    // paginator's totals are serialised into the payload's meta.
    // Four, not three: the viewer's own approved listing is in the registry too.
    expect(inertiaProp($response, 'listings.meta.total'))->toBe(4);
});

test('businesses page search still works without from_home parameter', function (): void {
    homePageSearchSbo(['name' => 'Verified Business', 'approved' => true]);
    homePageSearchSbo(['name' => 'Unverified Business', 'approved' => false]);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))
            ->get(route('businesses.index', ['search' => 'Business']))
            ->assertSuccessful()
    );

    // Without from_home the directory shows unapproved listings too.
    expect($names)->toContain('Verified Business')
        ->and($names)->toContain('Unverified Business');
});

test('businesses page location search uses exact matching', function (): void {
    homePageSearchSbo(['name' => 'NYC Business', 'city' => 'New York']);
    homePageSearchSbo(['name' => 'New Haven Business', 'city' => 'New Haven']);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))
            ->get(route('businesses.index', ['location' => 'New York']))
            ->assertSuccessful()
    );

    expect($names)->toContain('NYC Business')
        ->and($names)->not->toContain('New Haven Business');
});

test('home page search only shows cities from approved businesses', function (): void {
    homePageSearchSbo(['name' => 'Approved City SBO', 'city' => 'Verified City']);
    homePageSearchSbo(['name' => 'Pending City SBO', 'city' => 'Unverified City', 'approved' => false]);

    $cities = inertiaProp(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))->get(route('businesses.index', ['from_home' => '1'])),
        'cities',
    );

    expect($cities)->toContain('Verified City')
        ->and($cities)->not->toContain('Unverified City');
});

/*
 * `fromHome` was asserted through `viewData('fromHome')`. The Inertia payload
 * carries no such prop — it is a query flag the controller reads, never
 * something the screen is told. So these assert the behaviour it controls,
 * which is what the two tests were really standing in for.
 */
test('from_home restricts the directory to approved listings', function (): void {
    homePageSearchSbo(['name' => 'Approved From Home', 'approved' => true]);
    homePageSearchSbo(['name' => 'Pending From Home', 'approved' => false]);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))
            ->get(route('businesses.index', ['from_home' => '1']))
            ->assertSuccessful()
    );

    expect($names)->toContain('Approved From Home')
        ->and($names)->not->toContain('Pending From Home');
});

test('without from_home the directory includes listings still under review', function (): void {
    homePageSearchSbo(['name' => 'Approved Default', 'approved' => true]);
    homePageSearchSbo(['name' => 'Pending Default', 'approved' => false]);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'city' => 'Viewerville', 'category' => 'Viewer Category', 'address' => 'Viewer Street', 'description' => 'The viewer of these tests']))->get(route('businesses.index'))->assertSuccessful()
    );

    expect($names)->toContain('Approved Default')
        ->and($names)->toContain('Pending Default');
});
