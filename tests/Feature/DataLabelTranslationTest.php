<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Country;
use App\Models\Sbo;
use App\Models\User;
use App\Support\Countries;
use App\Support\DataLabels;
use App\Support\SboBrowseFacets;
use Illuminate\Support\Str;

/*
 * Stored values stay English; the labels on them do not.
 *
 * The distinction is the whole point of DataLabels, and it is easy to break in
 * either direction: translate too little and a French reader gets English
 * chips, translate too much and `?category=Librairies` filters on a string no
 * row holds. Both failures are asserted here.
 */

beforeEach(function (): void {
    Countries::flush();
    cache()->flush();
});

function labelSbo(array $overrides = []): Sbo
{
    return Sbo::create(array_merge([
        'name' => 'Label Co ' . Str::random(4),
        'slug' => 'label-co-' . Str::random(6),
        'created_by' => User::factory()->create()->id,
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('a category is labelled in the reader’s language', function (): void {
    app()->setLocale('fr');

    expect(DataLabels::get(DataLabels::CATEGORY, 'Bookstores & Publishers'))
        ->toBe('Librairies et maisons d\'édition');
});

test('a value with no translation is returned exactly as stored', function (): void {
    app()->setLocale('fr');

    expect(DataLabels::get(DataLabels::CATEGORY, 'Submarine Refitting'))
        ->toBe('Submarine Refitting');
});

test('accent and punctuation differences reach the same key', function (): void {
    expect(DataLabels::slug('Restaurants & Cafés'))
        ->toBe('restaurants_cafes')
        ->and(DataLabels::slug('Restaurants & Cafes'))
        ->toBe('restaurants_cafes');
});

test('country names come from ICU rather than a translation file', function (): void {
    Country::query()->firstOrCreate(['name' => 'South Africa'], ['iso2' => 'ZA']);
    Countries::flush();

    app()->setLocale('es');
    expect(DataLabels::country('South Africa'))->toBe('Sudáfrica');

    app()->setLocale('fr');
    expect(DataLabels::country('South Africa'))->toBe('Afrique du Sud');
});

test('a country the registry does not hold is left alone', function (): void {
    app()->setLocale('fr');

    expect(DataLabels::country('Wakanda'))->toBe('Wakanda');
});

test('a browse facet translates its label and leaves its filter value English', function (): void {
    $country = Country::query()->firstOrCreate(['name' => 'Nigeria'], ['iso2' => 'NG']);
    Countries::flush();

    labelSbo([
        'category' => 'Bookstores & Publishers',
        'industry' => 'Media & Creative',
        'country' => 'Nigeria',
        'country_id' => $country->id,
    ]);

    app()->setLocale('fr');

    $industry = SboBrowseFacets::options(SboBrowseFacets::INDUSTRY)->firstWhere('value', 'Media & Creative');

    expect($industry)->not->toBeNull()
        ->and($industry['label'])->toBe('Médias et création')
        // The value is what `?industry=` carries and what the query matches on.
        ->and($industry['value'])->toBe('Media & Creative');

    $service = SboBrowseFacets::options(SboBrowseFacets::SERVICE)->firstWhere('value', 'Bookstores & Publishers');

    expect($service['label'])->toBe('Librairies et maisons d\'édition')
        ->and($service['value'])->toBe('Bookstores & Publishers');

    $countryOption = SboBrowseFacets::options(SboBrowseFacets::COUNTRY)->firstWhere('value', 'Nigeria');

    expect($countryOption['value'])->toBe('Nigeria');
});

test('the directory still filters on the English value while the page is in French', function (): void {
    Category::query()->firstOrCreate(['name' => 'Bookstores & Publishers'], ['slug' => 'bookstores-publishers']);

    $match = labelSbo([
        'name' => 'Radiant Books',
        'slug' => 'radiant-books',
        'category' => 'Bookstores & Publishers',
    ]);

    labelSbo([
        'name' => 'Radiant Haulage',
        'slug' => 'radiant-haulage',
        'category' => 'Transport & Logistics',
    ]);

    // The registry is members-only and paid, so the reader has to be one; their
    // own listing is in neither category under test.
    $viewer = paidMember(['locale' => 'fr'], ['category' => 'Viewer Category']);

    $names = directoryNames(
        $this->actingAs($viewer)
            ->get(route('businesses.index', ['category' => 'Bookstores & Publishers']))
            ->assertSuccessful()
    );

    expect($names)->toContain($match->name)
        ->and($names)->not->toContain('Radiant Haulage');
});

test('country labels are shared with the client for every country in use', function (): void {
    $country = Country::query()->firstOrCreate(['name' => 'Nigeria'], ['iso2' => 'NG']);
    Countries::flush();

    labelSbo(['country' => 'Nigeria', 'country_id' => $country->id]);

    app()->setLocale('fr');

    expect(DataLabels::countryLabels())->toHaveKey('data.country.nigeria');
});
