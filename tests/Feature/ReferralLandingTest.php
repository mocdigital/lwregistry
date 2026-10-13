<?php

declare(strict_types=1);

use App\Http\Controllers\ReferralLandingController;
use App\Models\Zone;

it('stores the referral in session and lands on the join page', function (): void {
    $zone = Zone::create(['name' => 'Test Zone A', 'code' => 'TESTAA', 'is_active' => true]);

    $this->get('/join/' . $zone->code)
        ->assertRedirect(route('join'));

    expect(session(ReferralLandingController::SESSION_CODE))->toBe('TESTAA')
        ->and(session(ReferralLandingController::SESSION_NAME))->toBe('Test Zone A');
});

it('degrades gracefully for an unknown code (no session, plain home)', function (): void {
    $this->get('/join/NOPE99')
        ->assertRedirect(route('home'));

    expect(session(ReferralLandingController::SESSION_CODE))->toBeNull();
});

it('ignores an inactive zone code', function (): void {
    $zone = Zone::create(['name' => 'Inactive Zone', 'code' => 'INACTV', 'is_active' => false]);

    $this->get('/join/' . $zone->code)
        ->assertRedirect(route('home'));

    expect(session(ReferralLandingController::SESSION_NAME))->toBeNull();
});
