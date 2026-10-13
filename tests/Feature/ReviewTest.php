<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\Category;
use App\Models\Review;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create();
    $this->business = Business::factory()->create([
        'category_id' => $this->category->id,
        'avg_rating' => 0,
        'total_reviews' => 0,
    ]);
});

/*
 * The submission tests that were here posted to
 * `businesses.reviews.store` expecting JSON and a `Business` binding.
 *
 * That route now binds an Sbo and returns a redirect, because `businesses`
 * holds no rows and no page renders one — the endpoint had no reachable
 * subject, which is why "Submit Review" on a listing did nothing. Submission is
 * covered by tests/Feature/ListingReviewTest.php against the model the profile
 * actually shows.
 *
 * What remains below is still worth keeping: the observer that maintains
 * `businesses.avg_rating`, and the two assertions that legacy business reviews
 * never leak into the SBO routes.
 */

describe('average rating calculation', function (): void {
    it('calculates average rating when review is created', function (): void {
        Review::factory()->create([
            'business_id' => $this->business->id,
            'rating' => 5,
        ]);

        $this->business->refresh();
        expect($this->business->avg_rating)->toBe('5.00');
        expect($this->business->total_reviews)->toBe(1);
    });

    it('recalculates average when multiple reviews exist', function (): void {
        Review::factory()->create([
            'business_id' => $this->business->id,
            'rating' => 5,
        ]);

        Review::factory()->create([
            'business_id' => $this->business->id,
            'rating' => 3,
        ]);

        $this->business->refresh();
        expect($this->business->avg_rating)->toBe('4.00');
        expect($this->business->total_reviews)->toBe(2);
    });

    it('recalculates average when review is updated', function (): void {
        $review = Review::factory()->create([
            'business_id' => $this->business->id,
            'rating' => 5,
        ]);

        $review->update(['rating' => 2]);

        $this->business->refresh();
        expect($this->business->avg_rating)->toBe('2.00');
    });

    it('recalculates average when review is deleted', function (): void {
        $review1 = Review::factory()->create([
            'business_id' => $this->business->id,
            'rating' => 5,
        ]);

        Review::factory()->create([
            'business_id' => $this->business->id,
            'rating' => 3,
        ]);

        $this->business->refresh();
        expect($this->business->avg_rating)->toBe('4.00');
        expect($this->business->total_reviews)->toBe(2);

        $review1->delete();

        $this->business->refresh();
        expect($this->business->avg_rating)->toBe('3.00');
        expect($this->business->total_reviews)->toBe(1);
    });

    it('resets to zero when all reviews are deleted', function (): void {
        $review = Review::factory()->create([
            'business_id' => $this->business->id,
            'rating' => 5,
        ]);

        $review->delete();

        $this->business->refresh();
        expect($this->business->avg_rating)->toBe('0.00');
        expect($this->business->total_reviews)->toBe(0);
    });
});

describe('review display', function (): void {
    it('does not expose legacy business reviews through the sbo public detail route', function (): void {
        Review::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'rating' => 4,
            'comment' => 'This is a test review comment',
        ]);

        // `businesses.show` resolves an Sbo, not a legacy Business, so a
        // Business slug is simply not found there. Members-only, so the
        // request is made as one — otherwise the redirect masks the 404.
        $this->actingAs(paidMember())
            ->get(route('businesses.show', $this->business->slug))
            ->assertNotFound();
    });

    it('does not show legacy business review counts on the sbo directory', function (): void {
        Review::factory()->count(3)->create([
            'business_id' => $this->business->id,
        ]);

        $this->business->refresh();

        /*
         * The directory lists SBOs, and `Review` belongs to the legacy
         * Business model — the card's review count is a design placeholder
         * fixed at zero. Asserted on the payload: the count is not merely
         * absent from the markup, it is not sent at all.
         */
        $response = $this->actingAs(paidMember())
            ->get(route('businesses.index'))
            ->assertOk();

        $listings = collect(inertiaProp($response, 'listings.data'));

        expect($listings)->not->toBeEmpty()
            ->and($listings->first())->not->toHaveKey('reviews')
            ->and($listings->first())->not->toHaveKey('reviewCount');
    });
});
