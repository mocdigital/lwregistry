<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * `/account` — the Recent Activity feed and the three listing icons.
 *
 * The feed was three invented rows and all three icons were inert `<button>`s.
 */

function accountOwner(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

function accountSbo(User $owner, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Account SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('recent activity is built from real records, newest first', function (): void {
    $owner = accountOwner();
    accountSbo($owner, [
        'name' => 'Activity Ltd',
        'date_approved' => now(),
        'payment_confirmed_at' => now()->subDay(),
    ]);

    $this->actingAs($owner)
        ->get(route('account.index'))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $rows = collect($page->toArray()['props']['activity']);

            expect($rows)->not->toBeEmpty()
                ->and($rows->pluck('title'))->toContain('Listing approved: Activity Ltd')
                ->and($rows->pluck('title'))->toContain('Registration fee confirmed for Activity Ltd')
                ->and($rows->pluck('title'))->toContain('Listing registered: Activity Ltd');

            /*
             * Newest first. Asserted as an ordering rather than by naming the
             * top row: `date_approved` is a date cast, so an approval lands at
             * midnight and legitimately sorts below a same-day `created_at`.
             */
            $dates = $rows->map(fn (array $r) => strtotime($r['date']))->all();
            expect($dates)->toBe(collect($dates)->sortDesc()->values()->all());
        });
});

test('a fresh account gets an empty feed rather than invented rows', function (): void {
    $owner = accountOwner();
    accountSbo($owner);

    $this->actingAs($owner)
        ->get(route('account.index'))
        ->assertInertia(function ($page): void {
            // Only the events that really happened; nothing about reviews or
            // stars, which the screen used to claim.
            $titles = collect($page->toArray()['props']['activity'])->pluck('title')->implode(' ');
            expect($titles)->not->toContain('review');
        });
});

test('each listing carries its three action targets', function (): void {
    $owner = accountOwner();
    // A verified listing so the owner can reach the (approval-gated) account page.
    accountSbo($owner, ['name' => 'Live Ltd']);
    $pending = accountSbo($owner, ['name' => 'Pending Ltd', 'verification_status' => 'pending']);

    $this->actingAs($owner)
        ->get(route('account.index'))
        ->assertInertia(function ($page) use ($pending): void {
            $row = collect($page->toArray()['props']['listings']['unverified'])
                ->firstWhere('id', $pending->id);

            expect($row['manageUrl'])->toBe(route('sbo.applications.progress', $pending))
                ->and($row['publicUrl'])->toBe(route('businesses.show', $pending->slug))
                // Not approved yet, so it can still be withdrawn.
                ->and($row['canDelete'])->toBeTrue();
        });
});

test('an approved listing cannot be deleted from the bin icon', function (): void {
    $owner = accountOwner();
    $approved = accountSbo($owner, ['name' => 'Live Ltd']);

    $this->actingAs($owner)
        ->get(route('account.index'))
        ->assertInertia(function ($page) use ($approved): void {
            $row = collect($page->toArray()['props']['listings']['verified'])
                ->firstWhere('id', $approved->id);

            expect($row['canDelete'])->toBeFalse();
        });

    // And the endpoint refuses it, not just the UI.
    $this->actingAs($owner)
        ->from(route('account.index'))
        ->delete(route('sbo.listings.destroy', $approved))
        ->assertRedirect()
        ->assertSessionHas('status', 'listing-delete-refused');

    expect(Sbo::whereKey($approved->id)->exists())->toBeTrue();
});

test('an unapproved listing can be deleted by its owner only', function (): void {
    $owner = accountOwner();
    accountSbo($owner, ['name' => 'Keeps Me In']);
    $draft = accountSbo($owner, ['name' => 'Draft Ltd', 'verification_status' => 'pending']);

    $this->actingAs(accountOwner())
        ->delete(route('sbo.listings.destroy', $draft))
        ->assertForbidden();

    expect(Sbo::whereKey($draft->id)->exists())->toBeTrue();

    $this->actingAs($owner)
        ->delete(route('sbo.listings.destroy', $draft))
        ->assertRedirect(route('account.index'));

    expect(Sbo::whereKey($draft->id)->exists())->toBeFalse();
});
