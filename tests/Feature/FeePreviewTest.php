<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The country-scoped fee schedule that feeds the inline Registration Fee
 * Calculator on the business-registration form, before any business exists.
 */

test('a verified member can preview the fee schedule for a country', function (): void {
    $member = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($member)
        ->getJson(route('fees.preview', ['country' => 'Nigeria']))
        ->assertOk()
        ->assertJson(['sboId' => null, 'confirmed' => false, 'paid' => false])
        ->assertJsonStructure(['currency', 'structures', 'flatFee', 'espees' => ['testAccount']]);
});

test('the fee preview works with no country yet (schedule defaults)', function (): void {
    $member = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($member)
        ->getJson(route('fees.preview'))
        ->assertOk()
        ->assertJson(['sboId' => null, 'confirmed' => false]);
});

test('the fee preview is closed to guests', function (): void {
    $this->getJson(route('fees.preview'))->assertUnauthorized();
});
