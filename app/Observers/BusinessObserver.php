<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Category;
use App\Notifications\AdminNewBusinessNotification;
use App\Notifications\BusinessRegisteredNotification;
use App\Notifications\BusinessVerifiedNotification;
use App\Support\AdminRecipients;
use Illuminate\Support\Str;

final class BusinessObserver
{
    /**
     * Handle the Business "created" event.
     */
    public function created(Business $business): void
    {
        if ($business->category_id) {
            Category::where('id', $business->category_id)->increment('business_count');
        }

        // LOG EVENT
        ActivityLog::create([
            'type' => 'business_created',
            'title' => $business->name,
            'description' => 'New Business registered by <strong>' . (optional(auth()->user())->name ?? 'system') . '</strong>.',
            'status' => 'pending',
            'icon' => Str::upper(Str::substr($business->name, 0, 2)),
            'user_id' => auth()->id(),
            'business_id' => $business->id,
        ]);

        // Notify the business owner that their registration was received.
        if ($business->user) {
            $business->user->notify(new BusinessRegisteredNotification($business));
        }

        // Alert admins that a new business needs review.
        AdminRecipients::notify(new AdminNewBusinessNotification($business));
    }

    /**
     * Handle the Business "updated" event.
     */
    public function updated(Business $business): void
    {
        // Notify the owner when the business transitions to verified.
        if ($business->wasChanged('is_verified') && $business->is_verified && $business->user) {
            $business->user->notify(new BusinessVerifiedNotification($business));
        }

        if ($business->isDirty('category_id')) {
            // Decrement old category
            if ($business->getOriginal('category_id')) {
                Category::where('id', $business->getOriginal('category_id'))->decrement('business_count');
            }

            // Increment new category
            if ($business->category_id) {
                Category::where('id', $business->category_id)->increment('business_count');
            }

            // Avoid noise: only log if something meaningful changed
            if ($business->wasChanged()) {
                ActivityLog::create([
                    'type' => 'business_updated',
                    'title' => $business->name,
                    'description' => 'Business updated by <strong>' . (optional(auth()->user())->name ?? 'system') . '</strong>.',
                    'status' => 'updated',
                    'icon' => Str::upper(Str::substr($business->name, 0, 2)),
                    'user_id' => auth()->id(),
                    'business_id' => $business->id,
                ]);
            }

        }
    }

    /**
     * Handle the Business "deleted" event.
     */
    public function deleted(Business $business): void
    {
        if ($business->category_id) {
            Category::where('id', $business->category_id)->decrement('business_count');
        }

        // LOG EVENT
        ActivityLog::create([
            'type' => 'business_deleted',
            'title' => $business->name,
            'description' => 'Business deleted by <strong>' . (optional(auth()->user())->name ?? 'system') . '</strong>.',
            'status' => 'deleted',
            'icon' => Str::upper(Str::substr($business->name, 0, 2)),
            'user_id' => auth()->id(),
        ]);

    }

    /**
     * Handle the Business "restored" event.
     */
    public function restored(Business $business): void {}

    /**
     * Handle the Business "force deleted" event.
     */
    public function forceDeleted(Business $business): void {}
}
