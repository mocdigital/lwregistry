<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PurchaseOrderBid;
use App\Notifications\BidStatusNotification;
use App\Notifications\NewBidReceivedNotification;
use App\Support\AdminRecipients;

final class PurchaseOrderBidObserver
{
    /**
     * Handle the PurchaseOrderBid "created" event.
     */
    public function created(PurchaseOrderBid $bid): void
    {
        // Notify the SBO that owns the purchase order.
        $sboOwner = optional(optional($bid->purchaseOrder)->sbo)->creator;

        if ($sboOwner) {
            $sboOwner->notify(new NewBidReceivedNotification($bid));
        }

        // Alert admins as well so they can oversee the bidding process.
        AdminRecipients::notify(new NewBidReceivedNotification($bid));
    }

    /**
     * Handle the PurchaseOrderBid "updated" event.
     */
    public function updated(PurchaseOrderBid $bid): void
    {
        if ( ! $bid->wasChanged('status')) {
            return;
        }

        if ( ! in_array($bid->status, ['accepted', 'rejected'], true)) {
            return;
        }

        // Notify the bidder of the outcome.
        if ($bid->user) {
            $bid->user->notify(new BidStatusNotification($bid, $bid->status));
        }
    }
}
