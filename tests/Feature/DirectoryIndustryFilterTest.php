<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\User;
use App\Support\SboIndustries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The directory's "All Industries" filter.
 *
 * It shipped disabled: the design has both a category and an industry select
 * and `sbos` carried `category` alone. Industry is the sector above category —
 * see App\Support\SboIndustries for why the two are a taxonomy rather than a
 * duplicate, and why registration asks for the category only.
 */

function industrySbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Industry SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'category' => 'Software Development',
        'city' => 'Lagos',
        'address' => 'Central Business District',
        'description' => 'SBO directory listing',
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

test('an sbo is classified into its industry when it is saved', function (): void {
    $sbo = industrySbo(['category' => 'Software Development']);

    expect($sbo->industry)->toBe('Technology');

    // And it follows the category when that changes, so an admin correcting a
    // listing does not leave it filed under the old sector.
    $sbo->update(['category' => 'Hotels & Hospitality']);

    expect($sbo->fresh()->industry)->toBe('Hospitality & Events');
});

test('an industry set explicitly is not overwritten by the category mapping', function (): void {
    // A business does not always sit where its category suggests, so an
    // explicit value wins.
    $sbo = industrySbo(['category' => 'Software Development', 'industry' => 'Healthcare']);

    expect($sbo->industry)->toBe('Healthcare');
});

test('an unmapped category classifies as null rather than guessing', function (): void {
    $sbo = industrySbo(['category' => 'Something Nobody Seeded']);

    expect($sbo->industry)->toBeNull();
});

test('the directory filters by industry across every category in that sector', function (): void {
    industrySbo(['name' => 'Kingdom Software', 'category' => 'Software Development']);
    industrySbo(['name' => 'Kingdom IT Support', 'category' => 'Technology Services']);
    industrySbo(['name' => 'Kingdom Bakery', 'category' => 'Restaurants & Cafés']);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'category' => 'Legal Consultants']))
            ->get(route('businesses.index', ['industry' => 'Technology']))
            ->assertOk()
    );

    // Two different categories, one sector — which is the point of the filter.
    expect($names)->toContain('Kingdom Software')
        ->and($names)->toContain('Kingdom IT Support')
        ->and($names)->not->toContain('Kingdom Bakery')
        ->and($names)->not->toContain('Viewer Own SBO');
});

test('industry and category compose rather than conflict', function (): void {
    industrySbo(['name' => 'Narrow Software', 'category' => 'Software Development']);
    industrySbo(['name' => 'Broad IT Support', 'category' => 'Technology Services']);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'category' => 'Legal Consultants']))
            ->get(route('businesses.index', [
                'industry' => 'Technology',
                'category' => 'Software Development',
            ]))
            ->assertOk()
    );

    expect($names)->toContain('Narrow Software')
        ->and($names)->not->toContain('Broad IT Support');
});

test('the select offers only industries that have a listing behind them', function (): void {
    industrySbo(['name' => 'Only Tech SBO', 'category' => 'Software Development']);

    $response = $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'category' => 'Legal Consultants']))
        ->get(route('businesses.index'))
        ->assertOk();

    $industries = collect(inertiaProp($response, 'industries'));

    // Never an empty result page: an industry is offered only once something
    // is filed under it.
    expect($industries)->toContain('Technology')
        ->and($industries)->toContain('Professional Services')
        ->and($industries)->not->toContain('Agriculture')
        ->and($industries->all())->toBe($industries->sort()->values()->all());
});

test('directory search matches the industry as well as the category', function (): void {
    industrySbo([
        'name' => 'Anonymous Codeshop',
        'category' => 'Software Development',
        'description' => 'We build things.',
    ]);

    $names = directoryNames(
        $this->actingAs(paidMember([], ['name' => 'Viewer Own SBO', 'category' => 'Legal Consultants']))
            ->get(route('businesses.index', ['search' => 'Technology']))
            ->assertOk()
    );

    // "Technology" appears nowhere in this listing's own fields — it is found
    // through the sector its category belongs to.
    expect($names)->toContain('Anonymous Codeshop');
});

test('every seeded category maps to an offered industry', function (): void {
    $categories = App\Models\Category::pluck('name');

    // The seeders and the taxonomy drifting apart is how the filter would
    // quietly start dropping listings, so it is asserted rather than assumed.
    $unmapped = $categories->reject(fn (string $name): bool => null !== SboIndustries::forCategory($name));

    expect($unmapped->all())->toBe([])
        ->and(SboIndustries::all())->toContain('Technology');
});
