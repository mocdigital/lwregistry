<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Business;
use App\Models\Review;
use App\Notifications\NewReviewNotification;

final class ReviewObserver
{
    /**
     * Handle the Review "created" event.
     */
    public function created(Review $review): void
    {
        $this->updateBusinessRating($review->business_id);

        /*
         * The owner, whichever kind of listing this is. `businesses` is the
         * legacy table and holds nothing; a review written from a profile page
         * carries `sbo_id` and its owner is `sbos.created_by`.
         */
        $owner = $review->sbo?->creator ?? $review->business?->user;

        if ($owner && $owner->getKey() !== $review->user_id) {
            $owner->notify(new NewReviewNotification($review));
        }
    }

    /**
     * Handle the Review "updated" event.
     */
    public function updated(Review $review): void
    {
        $this->updateBusinessRating($review->business_id);
    }

    /**
     * Handle the Review "deleted" event.
     */
    public function deleted(Review $review): void
    {
        $this->updateBusinessRating($review->business_id);
    }

    /**
     * Recalculate and update the business's average rating and total reviews.
     */
    /**
     * Keep the denormalised rating on `businesses` in step.
     *
     * Nullable: a review on an Sbo has no `business_id` at all, and typing this
     * `int` meant the first one threw a TypeError from inside the model event —
     * a 500 raised after the row had already been written, so the review saved
     * and the member was shown an error.
     *
     * The Sbo side needs no equivalent: BusinessController averages the rows
     * directly, which cannot drift out of date the way a stored column can.
     */
    private function updateBusinessRating(?int $businessId): void
    {
        if (null === $businessId) {
            return;
        }

        $business = Business::find($businessId);

        if ( ! $business) {
            return;
        }

        $reviews = $business->reviews();
        $totalReviews = $reviews->count();
        $avgRating = $totalReviews > 0 ? (float) $reviews->avg('rating') : 0.0;

        $business->update([
            'avg_rating' => round($avgRating, 2),
            'total_reviews' => $totalReviews,
        ]);
    }
}
