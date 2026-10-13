<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Directory quick filters, asserted against the Inertia payload rather than
 * rendered HTML — see the note on the helpers in tests/Pest.php.
 *
 * The viewer is a paid member because the registry is members-only and paid;
 * their own listing is deliberately outside every filter used below.
 */
beforeEach(function (): void {
    $this->category = Category::factory()->create(['name' => 'Restaurants', 'slug' => 'restaurants']);
    $this->viewer = paidMember([], [
        'name' => 'Quick Filter Viewer',
        'category' => 'Viewer Category',
        'city' => 'Viewerville',
    ]);
});

function businessQuickFiltersSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Directory SBO ' . Str::random(6);
    $fullyVerified = (bool) ($overrides['fully_verified'] ?? false);

    unset($overrides['fully_verified']);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'category' => 'Restaurants',
        'city' => 'Lagos',
        'address' => 'Central Business District',
        'description' => 'SBO directory listing',
        'created_by' => User::factory()->create()->id,
        'verification_status' => $fullyVerified ? 'approved' : 'pending',
        'documents_status' => $fullyVerified ? 'compliant' : 'pending',
        'documents_compliant_at' => $fullyVerified ? now() : null,
        'payment_status' => $fullyVerified ? 'paid' : 'unpaid',
        'payment_confirmed_at' => $fullyVerified ? now() : null,
    ], $overrides));
}

test('can filter businesses by verified status', function (): void {
    businessQuickFiltersSbo(['name' => 'Verified Business', 'fully_verified' => true]);
    businessQuickFiltersSbo(['name' => 'Unverified Business']);

    $names = directoryNames(
        $this->actingAs($this->viewer)
            ->get(route('businesses.index', ['status' => 'verified']))
            ->assertSuccessful()
    );

    expect($names)->toContain('Verified Business')
        ->and($names)->not->toContain('Unverified Business');
});

test('can filter businesses by unverified status', function (): void {
    businessQuickFiltersSbo(['name' => 'Verified Business', 'fully_verified' => true]);
    businessQuickFiltersSbo(['name' => 'Unverified Business']);

    $names = directoryNames(
        $this->actingAs($this->viewer)
            ->get(route('businesses.index', ['status' => 'unverified']))
            ->assertSuccessful()
    );

    expect($names)->toContain('Unverified Business')
        ->and($names)->not->toContain('Verified Business');
});

test('open 24/7 query parameter does not break the sbo directory', function (): void {
    businessQuickFiltersSbo(['name' => 'Directory Listing']);

    // `open24` has no column behind it; the point is that an unknown query
    // parameter does not break the screen.
    $names = directoryNames(
        $this->actingAs($this->viewer)
            ->get(route('businesses.index', ['open24' => '1']))
            ->assertSuccessful()
    );

    expect($names)->toContain('Directory Listing');
});

test('quick filters work with category filter', function (): void {
    $otherCategory = Category::factory()->create(['name' => 'Hotels', 'slug' => 'hotels']);

    businessQuickFiltersSbo([
        'name' => 'Restaurant Verified',
        'category' => $this->category->name,
        'fully_verified' => true,
    ]);
    businessQuickFiltersSbo([
        'name' => 'Restaurant Unverified',
        'category' => $this->category->name,
    ]);
    businessQuickFiltersSbo([
        'name' => 'Hotel Verified',
        'category' => $otherCategory->name,
        'fully_verified' => true,
    ]);

    /*
     * `businesses.category` is still the legacy Blade directory
     * (BusinessController::indexByCategory returns a View), unlike every other
     * route on this screen — so assertSee is the correct assertion here, and
     * this is the one place in the file that keeps it. Noted in INTEGRATION.md.
     */
    $response = $this->actingAs($this->viewer)->get(route('businesses.category', [
        'category' => $this->category->slug,
        'status' => 'verified',
    ]));

    $response->assertSuccessful();
    $response->assertSee('Restaurant Verified');
    $response->assertDontSee('Restaurant Unverified');
    $response->assertDontSee('Hotel Verified');
});

test('quick filters work with location filter', function (): void {
    businessQuickFiltersSbo(['name' => 'NYC Verified', 'city' => 'New York', 'fully_verified' => true]);
    businessQuickFiltersSbo(['name' => 'NYC Unverified', 'city' => 'New York']);
    businessQuickFiltersSbo(['name' => 'LA Verified', 'city' => 'Los Angeles', 'fully_verified' => true]);

    $names = directoryNames(
        $this->actingAs($this->viewer)
            ->get(route('businesses.index', ['location' => 'New York', 'status' => 'verified']))
            ->assertSuccessful()
    );

    expect($names)->toContain('NYC Verified')
        ->and($names)->not->toContain('NYC Unverified')
        ->and($names)->not->toContain('LA Verified');
});

test('multiple quick filters can be combined', function (): void {
    businessQuickFiltersSbo(['name' => 'NYC Verified', 'city' => 'New York', 'fully_verified' => true]);
    businessQuickFiltersSbo(['name' => 'NYC Unverified', 'city' => 'New York']);
    businessQuickFiltersSbo(['name' => 'LA Verified', 'city' => 'Los Angeles', 'fully_verified' => true]);

    $names = directoryNames(
        $this->actingAs($this->viewer)
            ->get(route('businesses.index', ['status' => 'verified', 'location' => 'New York']))
            ->assertSuccessful()
    );

    expect($names)->toContain('NYC Verified')
        ->and($names)->not->toContain('NYC Unverified')
        ->and($names)->not->toContain('LA Verified');
});

test('all filter shows both verified and unverified businesses', function (): void {
    businessQuickFiltersSbo(['name' => 'Verified Business', 'fully_verified' => true]);
    businessQuickFiltersSbo(['name' => 'Unverified Business']);

    $names = directoryNames(
        $this->actingAs($this->viewer)->get(route('businesses.index'))->assertSuccessful()
    );

    expect($names)->toContain('Verified Business')
        ->and($names)->toContain('Unverified Business');
});

test('location dropdown displays distinct cities from businesses', function (): void {
    businessQuickFiltersSbo(['city' => 'New York']);
    businessQuickFiltersSbo(['city' => 'New York']);
    businessQuickFiltersSbo(['city' => 'Los Angeles']);
    businessQuickFiltersSbo(['city' => 'Chicago']);

    // "All locations" was the Blade select's own placeholder; the payload's
    // contract is the distinct city list itself.
    $cities = inertiaProp(
        $this->actingAs($this->viewer)->get(route('businesses.index'))->assertSuccessful(),
        'cities',
    );

    expect($cities)->toContain('New York')
        ->and($cities)->toContain('Los Angeles')
        ->and($cities)->toContain('Chicago')
        // Distinct: New York was seeded twice.
        ->and(collect($cities)->duplicates())->toBeEmpty();
});

test('location filter uses exact city match', function (): void {
    businessQuickFiltersSbo(['name' => 'NYC Business', 'city' => 'New York']);
    businessQuickFiltersSbo(['name' => 'New Haven Business', 'city' => 'New Haven']);

    $names = directoryNames(
        $this->actingAs($this->viewer)
            ->get(route('businesses.index', ['location' => 'New York']))
            ->assertSuccessful()
    );

    expect($names)->toContain('NYC Business')
        ->and($names)->not->toContain('New Haven Business');
});

test('location dropdown excludes null and empty cities', function (): void {
    businessQuickFiltersSbo(['name' => 'Valid City', 'city' => 'Boston']);
    businessQuickFiltersSbo(['name' => 'Null City', 'city' => null]);
    businessQuickFiltersSbo(['name' => 'Empty City', 'city' => '']);

    $response = $this->actingAs($this->viewer)
        ->get(route('businesses.index'))
        ->assertSuccessful();

    // All three listings are in the directory...
    $names = directoryNames($response);
    expect($names)->toContain('Valid City')
        ->and($names)->toContain('Null City')
        ->and($names)->toContain('Empty City');

    // ...but only a real city reaches the location dropdown.
    $cities = collect(inertiaProp($response, 'cities'));
    expect($cities)->toContain('Boston')
        ->and($cities->filter(fn ($c) => null === $c || '' === mb_trim((string) $c)))->toBeEmpty();
});
