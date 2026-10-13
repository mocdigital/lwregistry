<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * The Business Listings section on /profile.
 *
 * The tabs counted correctly and filtered nothing: the click set a ref nobody
 * read, and the empty state rendered unconditionally — so an owner with two
 * listings saw "All (2) · Verified (1) · Pending Verification (1)" directly
 * above the words "No listings found".
 *
 * The count and the badge also disagreed. The tab counted
 * `verification_status === 'approved'` while the card drew isFullyVerified(),
 * which additionally requires the documents and the fee. A listing approved but
 * unpaid was counted verified and shown pending.
 */

function profileListingsOwner(): User
{
    return User::factory()->create([
        'role' => App\Enums\UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

function profileListing(User $owner, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Listing ' . Str::random(5);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

function fullyVerified(User $owner, array $overrides = []): Sbo
{
    return profileListing($owner, array_merge([
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ], $overrides));
}

test('the listings are actually sent, not just counted', function (): void {
    $owner = profileListingsOwner();
    fullyVerified($owner, ['name' => 'Verified Co']);
    profileListing($owner, ['name' => 'Pending Co']);

    $this->actingAs($owner)
        ->get(route('account.profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.listings', 2)
            ->where('stats.verified', 1)
            ->where('stats.pending', 1)
            // The payload the section renders. It was sent and never read.
            ->count('listings', 2));
});

test('the count and the badge agree on what verified means', function (): void {
    $owner = profileListingsOwner();

    // The registry is members-only and paid, so reaching /profile at all needs
    // one settled listing; the gate is asserted separately below.
    fullyVerified($owner, ['name' => 'AAA Paid']);

    /*
     * Approved by the registry but the fee is unpaid and the documents are not
     * accepted. Not verified, by the definition the badge uses — the tab used
     * to count `verification_status === 'approved'` and disagree with the card
     * sitting under it.
     */
    profileListing($owner, [
        'name' => 'ZZZ Approved But Unpaid',
        'verification_status' => 'approved',
    ]);

    $this->actingAs($owner)
        ->get(route('account.profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.verified', 1)
            ->where('stats.pending', 1)
            // Ordered by name, so the unpaid one is second.
            ->where('listings.1.verified', false)
            ->where('listings.1.status', 'approved'));
});

test('each listing carries what the card needs', function (): void {
    $owner = profileListingsOwner();
    $sbo = fullyVerified($owner, ['name' => 'Radiant Logistics', 'category' => 'Technology Services']);

    $this->actingAs($owner)
        ->get(route('account.profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('listings.0.name', 'Radiant Logistics')
            ->where('listings.0.verified', true)
            // The raw state, which the tabs filter on. Never displayed as-is,
            // so translating the labels cannot break the filtering.
            ->where('listings.0.status', 'approved')
            ->where('listings.0.href', route('businesses.show', $sbo->slug))
            ->where('listings.0.manageUrl', route('sbo.applications.progress', $sbo)));
});

test('an owner with no settled listing never reaches the page', function (): void {
    /*
     * The registry is members-only and paid: EnsureSboPaymentApproved walls
     * anyone without a confirmed fee, including an owner with no listing at
     * all. So the "no listings" empty state is not reachable here — it belongs
     * to a member who has one paid listing and none in the current tab, which
     * is what emptyCopy distinguishes.
     */
    $this->actingAs(profileListingsOwner())
        ->get(route('account.profile'))
        ->assertRedirect();
});

test('the counts add up, whatever the mix of states', function (): void {
    $owner = profileListingsOwner();

    fullyVerified($owner);
    fullyVerified($owner);
    profileListing($owner, ['verification_status' => 'declined']);
    profileListing($owner, ['verification_status' => 'info_requested']);
    profileListing($owner);

    $this->actingAs($owner)
        ->get(route('account.profile'))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $stats = $page->toArray()['props']['stats'];

            // Every listing falls in exactly one of the two tabs, so they must
            // sum to the total or a listing is unreachable from the page.
            expect($stats['verified'] + $stats['pending'])->toBe($stats['listings'])
                ->and($stats['listings'])->toBe(5)
                ->and($stats['verified'])->toBe(2);
        });
});
