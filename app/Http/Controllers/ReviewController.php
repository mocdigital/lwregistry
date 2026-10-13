<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreReviewRequest;
use App\Models\Review;
use App\Models\Sbo;
use Illuminate\Http\RedirectResponse;

/**
 * A review on a listing.
 *
 * It used to bind `Business`, a legacy model whose table holds no rows, from a
 * slug that only ever belongs to an `Sbo`. The route could not match anything a
 * visitor could reach, so "Submit Review" posted into nowhere.
 *
 * Returns a redirect rather than JSON: the form is an Inertia form on the
 * profile page, and JSON from an Inertia POST is an unhandled response, not a
 * result the screen can show.
 */
final class ReviewController extends Controller
{
    public function store(StoreReviewRequest $request, Sbo $business): RedirectResponse
    {
        // Reviewing your own listing is not a review.
        if ($business->created_by === $request->user()->id) {
            return back()->withErrors(['rating' => __('reviews.own_listing')]);
        }

        /*
         * One review per member per listing, updated rather than duplicated —
         * and now enforced by a unique index as well, so two submissions racing
         * each other cannot both pass this check and write two rows.
         */
        Review::updateOrCreate(
            ['sbo_id' => $business->id, 'user_id' => $request->user()->id],
            [
                'rating' => $request->validated('rating'),
                'comment' => $request->validated('comment'),
            ],
        );

        return back()->with('status', __('reviews.thanks'));
    }
}
