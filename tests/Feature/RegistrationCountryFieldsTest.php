<?php

declare(strict_types=1);

use App\Models\Country;
use App\Models\State;
use App\Support\PhoneNumbers;

/*
 * The registration wizard's country-dependent fields.
 *
 * The phone prefix was a fixed "+234" beside a Nigerian flag, and the state was
 * a free-text box that would take anything — on a registry that spans every
 * country in the `countries` table.
 */

test('the wizard ships the maps its flag and prefix need', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('dialCodes.Nigeria')
            ->where('dialCodes.Nigeria', '+234')
            ->where('countryIsoCodes.Nigeria', 'NG')
            // The reverse, so typing +233 selects Ghana rather than making
            // somebody hunt for it in a list of 250.
            ->where('countriesByDialCode.+233', 'Ghana'));
});

test('a shared dial code resolves to one country, not the alphabetical first', function (): void {
    $byCode = PhoneNumbers::countriesByDialCode();

    // +1 covers the United States, Canada and twenty Caribbean nations; the
    // flag has to be one of them and "American Samoa" is not the useful pick.
    expect($byCode['+1'])->toBe('United States')
        ->and($byCode['+7'])->toBe('Russia')
        ->and($byCode['+44'])->toBe('United Kingdom');
});

test('the states endpoint returns a country’s subdivisions', function (): void {
    $country = Country::query()->firstOrCreate(['name' => 'Testland'], ['iso2' => 'TL']);
    State::query()->create(['country_id' => $country->id, 'name' => 'North Province']);
    State::query()->create(['country_id' => $country->id, 'name' => 'South Province']);

    $this->getJson(route('states', ['country' => 'Testland']))
        ->assertOk()
        ->assertExactJson(['states' => ['North Province', 'South Province']]);
});

test('a country with no subdivisions returns an empty list rather than failing', function (): void {
    Country::query()->firstOrCreate(['name' => 'Cityland'], ['iso2' => 'CL']);

    // An empty list is a real answer — the wizard falls back to a free-text
    // box, which is better than a dropdown with nothing in it.
    $this->getJson(route('states', ['country' => 'Cityland']))
        ->assertOk()
        ->assertExactJson(['states' => []]);

    $this->getJson(route('states', ['country' => 'Nowhere At All']))
        ->assertOk()
        ->assertExactJson(['states' => []]);
});

test('the states endpoint is reachable without an account', function (): void {
    // The wizard is where an account is created; requiring one to read the
    // country list would be circular.
    $this->getJson(route('states', ['country' => 'Nigeria']))->assertOk();
});
