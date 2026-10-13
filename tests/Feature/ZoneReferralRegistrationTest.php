<?php

declare(strict_types=1);

use App\Http\Controllers\ReferralLandingController;
use App\Models\Sbo;
use App\Models\Zone;
use Livewire\Livewire;

it('opens the join form in register mode with the referred zone pre-selected', function (): void {
    $zone = Zone::create(['name' => 'Referred Zone', 'code' => 'REFZ01', 'is_active' => true]);

    session([
        ReferralLandingController::SESSION_CODE => $zone->code,
        ReferralLandingController::SESSION_NAME => $zone->name,
    ]);

    Livewire::test('auth-modal')
        ->assertSet('mode', 'register')
        ->assertSet('ownerChurchZone', 'Referred Zone');
});

it('does not pre-select a zone when there is no referral in session', function (): void {
    Zone::create(['name' => 'Some Zone', 'code' => 'SOMEZ1', 'is_active' => true]);

    Livewire::test('auth-modal')
        ->assertSet('mode', 'login')
        ->assertSet('ownerChurchZone', '');
});

it('ignores a session referral for an inactive/unknown zone name', function (): void {
    // Session name that does not match any active zone must not pre-select.
    session([ReferralLandingController::SESSION_NAME => 'Ghost Zone']);

    Livewire::test('auth-modal')
        ->assertSet('mode', 'login')
        ->assertSet('ownerChurchZone', '');
});

it('tags a created SBO with the zone via the referred_via_code + zone_id columns', function (): void {
    // Guards the schema + relation wiring the registration flow depends on.
    $zone = Zone::create(['name' => 'Tag Zone', 'code' => 'TAGZ01', 'is_active' => true]);

    $sbo = Sbo::create([
        'name' => 'Tagged Co',
        'slug' => 'tagged-co',
        'church_zone' => $zone->name,
        'zone_id' => $zone->id,
        'referred_via_code' => $zone->code,
    ]);

    expect($sbo->zone_id)->toBe($zone->id)
        ->and($sbo->referred_via_code)->toBe('TAGZ01')
        ->and($sbo->zone->is($zone))->toBeTrue()
        ->and($zone->sbos()->count())->toBe(1);
});
