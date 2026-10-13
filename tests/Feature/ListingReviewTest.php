<?php

declare(strict_types=1);

use App\Models\Review;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Reviews on a listing.
 *
 * "Submit Review" posted to a route that bound `Business` — a legacy model
 * whose table holds no rows — from a slug that only ever belongs to an `Sbo`.
 * The endpoint had no reachable subject, and the form had no handler anyway:
 * `@submit.prevent` with three invented reviews above it reading "Awaiting
 * backend data" and a fixed "4.5/5.0 (12 reviews)".
 */

function listingReviewSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Review Co ' . Str::random(4);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => User::factory()->create(['email_verified_at' => now()])->id,
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('a verified member reviews a listing', function (): void {
    $sbo = listingReviewSbo();
    $viewer = paidMember();

    $this->actingAs($viewer)
        ->post(route('businesses.reviews.store', $sbo->slug), [
            'rating' => 4,
            'comment' => 'Delivered on time, and the paperwork was in order.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $review = Review::query()->where('sbo_id', $sbo->id)->firstOrFail();

    expect($review->rating)->toBe(4)
        ->and($review->user_id)->toBe($viewer->id);
});

test('posting again updates rather than duplicating', function (): void {
    $sbo = listingReviewSbo();
    $viewer = paidMember();

    foreach ([3, 5] as $rating) {
        $this->actingAs($viewer)->post(route('businesses.reviews.store', $sbo->slug), [
            'rating' => $rating,
            'comment' => "Rated {$rating}.",
        ]);
    }

    expect(Review::query()->where('sbo_id', $sbo->id)->count())->toBe(1)
        ->and(Review::query()->where('sbo_id', $sbo->id)->value('rating'))->toBe(5);
});

test('an owner cannot review their own listing', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $sbo = listingReviewSbo(['created_by' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('businesses.reviews.store', $sbo->slug), ['rating' => 5])
        ->assertSessionHasErrors('rating');

    expect(Review::query()->where('sbo_id', $sbo->id)->count())->toBe(0);
});

test('a rating outside one to five is refused', function (): void {
    $sbo = listingReviewSbo();

    foreach ([0, 6, 'five'] as $rating) {
        $this->actingAs(paidMember())
            ->post(route('businesses.reviews.store', $sbo->slug), ['rating' => $rating])
            ->assertSessionHasErrors('rating');
    }

    expect(Review::query()->where('sbo_id', $sbo->id)->count())->toBe(0);
});

test('a guest cannot post one', function (): void {
    $sbo = listingReviewSbo();

    $this->post(route('businesses.reviews.store', $sbo->slug), ['rating' => 5])
        ->assertRedirect(route('login'));

    expect(Review::query()->count())->toBe(0);
});

test('the profile serves the real reviews and their average', function (): void {
    $sbo = listingReviewSbo();

    foreach ([5, 4] as $rating) {
        Review::create([
            'sbo_id' => $sbo->id,
            'user_id' => User::factory()->create()->id,
            'rating' => $rating,
            'comment' => "Rated {$rating}.",
        ]);
    }

    $this->actingAs(paidMember())
        ->get(route('businesses.show', $sbo->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('reviews.count', 2)
            ->where('reviews.average', 4.5)
            ->count('reviews.items', 2)
            ->where('reviews.canReview', true)
            ->where('reviews.isOwnListing', false));
});

test('a listing with no reviews is not a listing rated zero', function (): void {
    $sbo = listingReviewSbo();

    $this->actingAs(paidMember())
        ->get(route('businesses.show', $sbo->slug))
        ->assertOk()
        // Null, so the hero and the section print nothing rather than "0/5.0".
        ->assertInertia(fn ($page) => $page
            ->where('reviews.average', null)
            ->where('reviews.count', 0));
});

test('the owner is told why the form is not offered', function (): void {
    $owner = paidMember();
    $sbo = $owner->sbos()->first();

    $this->actingAs($owner)
        ->get(route('businesses.show', $sbo->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('reviews.canReview', false)
            ->where('reviews.isOwnListing', true));
});
