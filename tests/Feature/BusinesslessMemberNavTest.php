<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;

/*
 * A signed-in member who has not added a business yet.
 *
 * Clicking Dashboard in the header returned a bare 403. Owning no business is
 * not "forbidden" — it is the state before the workspace exists, and the answer
 * is the form that creates one. It became easy to reach once adding a business
 * stopped saving anything until the fee is paid: a member can be signed in,
 * verified, and own nothing for as long as they take to pay.
 */

/** Verified, and owning nothing. */
function businesslessMember(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ])->fresh();
}

test('the workspace sends a member with no business to add one, not to a 403', function (): void {
    $user = businesslessMember();

    expect($user->sbos()->exists())->toBeFalse();

    $this->actingAs($user)
        ->get(route('sbo.dashboard'))
        ->assertRedirect(route('sbo.listings.create'));

    $this->actingAs($user)
        ->get(route('sbo.network.index'))
        ->assertRedirect(route('sbo.listings.create'));
});

test('a write against a workspace that does not exist is still refused', function (): void {
    // A redirect here would swallow the action rather than report it.
    $this->actingAs(businesslessMember())
        ->post(route('sbo.groups.store'), ['name' => 'Nope'])
        ->assertForbidden();
});

test('the shared props tell the nav whether there is a business to link to', function (): void {
    $this->actingAs(businesslessMember())
        ->get(route('sbo.listings.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.hasBusiness', false));

    $this->actingAs(paidMember())
        ->get(route('sbo.listings.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.hasBusiness', true));
});
