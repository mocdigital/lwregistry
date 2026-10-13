<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Notifications\NewProcurementRequestNotification;
use App\Support\LiveOpportunities;
use Illuminate\Support\Facades\Notification;

final class PurchaseOrderObserver
{
    public function created(PurchaseOrder $purchaseOrder): void
    {
        // The rail is cached for five minutes; a new request should not wait
        // that long to appear on it.
        LiveOpportunities::flush();

        if ( ! $purchaseOrder->canAcceptBids() || ! $purchaseOrder->isPublic()) {
            return;
        }

        $purchaseOrder->loadMissing(['category', 'sbo']);

        $eligibleUsers = User::query()
            ->whereKeyNot($purchaseOrder->sbo?->created_by)
            ->whereHas('sbos', function ($query): void {
                $query->where('verification_status', 'approved')
                    ->where('documents_status', 'compliant')
                    ->whereNotNull('documents_compliant_at')
                    ->where('payment_status', 'paid')
                    ->whereNotNull('payment_confirmed_at')
                    ->where('status', 'active')
                    ->where('is_active', true);
            })
            ->get();

        if ($eligibleUsers->isEmpty()) {
            return;
        }

        Notification::send($eligibleUsers, new NewProcurementRequestNotification($purchaseOrder));
    }

    /** Closing, awarding or hiding a request takes it off the rail. */
    public function updated(PurchaseOrder $purchaseOrder): void
    {
        if ($purchaseOrder->wasChanged(['status', 'visibility', 'order_deadline', 'title'])) {
            LiveOpportunities::flush();
        }
    }

    public function deleted(PurchaseOrder $purchaseOrder): void
    {
        LiveOpportunities::flush();
    }
}
