<?php

declare(strict_types=1);

use App\Models\Country;
use App\Models\Sbo;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Support\Str;

/*
 * More than one address per listing.
 *
 * `sbos` carried one, while the card design drew a stack of country flags —
 * which had to be faked from a rotating list of assets, so a Lagos business
 * could be shown flying the flag of the UAE.
 *
 * Two rules are easy to get wrong and are asserted here: one flag per COUNTRY
 * rather than per address, and the first row staying in step with the columns
 * on `sbos` that the directory's country filter reads.
 */

beforeEach(function (): void {
    Countries::flush();
    cache()->flush();

    foreach ([['Nigeria', 'NG'], ['Ghana', 'GH'], ['United Kingdom', 'GB']] as [$name, $iso]) {
        Country::query()->firstOrCreate(['name' => $name], ['iso2' => $iso]);
    }

    Countries::flush();
});

function addressOwner(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

function addressSbo(User $owner, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Address Co ' . Str::random(4);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
        'city' => 'Lagos',
        'country' => 'Nigeria',
    ], $overrides));
}

test('an owner records several addresses, first one primary', function (): void {
    $owner = addressOwner();
    $sbo = addressSbo($owner);

    $this->actingAs($owner)
        ->put(route('sbo.listings.addresses.update', $sbo), [
            'addresses' => [
                ['label' => 'Head Office', 'address' => '12 Adeola Odeku', 'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria'],
                ['label' => 'Accra Branch', 'address' => '5 Oxford Street', 'city' => 'Accra', 'state' => null, 'country' => 'Ghana'],
            ],
        ])
        ->assertRedirect();

    $addresses = $sbo->fresh()->addresses;

    expect($addresses)->toHaveCount(2)
        ->and($addresses[0]->label)->toBe('Head Office')
        ->and($addresses[0]->is_primary)->toBeTrue()
        ->and($addresses[1]->is_primary)->toBeFalse()
        // Derived on save, never posted.
        ->and($addresses[0]->country_code)->toBe('NG')
        ->and($addresses[1]->country_code)->toBe('GH');
});

test('one flag per country, not per address', function (): void {
    $owner = addressOwner();
    $sbo = addressSbo($owner);

    $this->actingAs($owner)->put(route('sbo.listings.addresses.update', $sbo), [
        'addresses' => [
            ['city' => 'Lagos', 'country' => 'Nigeria'],
            ['city' => 'Abuja', 'country' => 'Nigeria'],
            ['city' => 'Kano', 'country' => 'Nigeria'],
            ['city' => 'Accra', 'country' => 'Ghana'],
        ],
    ]);

    expect($sbo->fresh()->countryCodes())->toBe(['NG', 'GH']);
});

test('a listing that has never opened the editor still flies its own flag', function (): void {
    $sbo = addressSbo(addressOwner(), ['country' => 'Nigeria']);

    expect($sbo->countryCodes())->toBe(['NG']);
});

test('moving the head office moves the listing, so the country filter follows', function (): void {
    $owner = addressOwner();
    $sbo = addressSbo($owner, ['city' => 'Lagos', 'country' => 'Nigeria']);

    // Cached from the old address; a pin left on a building they have left is
    // worse than no pin at all.
    $sbo->forceFill(['latitude' => 6.4281, 'longitude' => 3.4219])->save();

    $this->actingAs($owner)->put(route('sbo.listings.addresses.update', $sbo), [
        'addresses' => [
            ['label' => 'Head Office', 'address' => '1 Canada Square', 'city' => 'London', 'state' => null, 'country' => 'United Kingdom'],
        ],
    ]);

    $sbo->refresh();

    expect($sbo->country)->toBe('United Kingdom')
        ->and($sbo->city)->toBe('London')
        ->and($sbo->latitude)->toBeNull()
        ->and($sbo->longitude)->toBeNull();
});

test('an address without a country is refused — the flag comes from it', function (): void {
    $owner = addressOwner();
    $sbo = addressSbo($owner);

    $this->actingAs($owner)
        ->put(route('sbo.listings.addresses.update', $sbo), [
            'addresses' => [['city' => 'Lagos', 'country' => '']],
        ])
        ->assertSessionHasErrors('addresses.0.country');
});

test('someone else cannot edit a listing’s addresses', function (): void {
    $sbo = addressSbo(addressOwner());

    $this->actingAs(addressOwner())
        ->put(route('sbo.listings.addresses.update', $sbo), [
            'addresses' => [['city' => 'Lagos', 'country' => 'Nigeria']],
        ])
        ->assertNotFound();
});

test('the directory card carries the real country codes', function (): void {
    $owner = addressOwner();
    $sbo = addressSbo($owner, ['name' => 'Flagged Business']);

    $this->actingAs($owner)->put(route('sbo.listings.addresses.update', $sbo), [
        'addresses' => [
            ['city' => 'Lagos', 'country' => 'Nigeria'],
            ['city' => 'Accra', 'country' => 'Ghana'],
        ],
    ]);

    $this->actingAs(paidMember())
        ->get(route('businesses.index', ['search' => 'Flagged Business']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('listings.data.0.countryCodes', ['NG', 'GH']));
});
