<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\User;

test('authenticated user can clear all favorites', function (): void {
    $user = User::factory()->create();
    $businesses = Business::factory()->count(3)->create();

    // Add favorites
    foreach ($businesses as $business) {
        $user->favorites()->attach($business);
    }

    expect($user->favorites()->count())->toBe(3);

    // Clear favorites
    $this->actingAs($user)
        ->delete(route('favorites.clear'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success');

    expect($user->fresh()->favorites()->count())->toBe(0);
});

test('unauthenticated user cannot clear favorites', function (): void {
    $this->delete(route('favorites.clear'))
        ->assertRedirect(route('login'));
});

test('clearing favorites with no favorites does not error', function (): void {
    $user = User::factory()->create();

    expect($user->favorites()->count())->toBe(0);

    $this->actingAs($user)
        ->delete(route('favorites.clear'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success');

    expect($user->fresh()->favorites()->count())->toBe(0);
});
