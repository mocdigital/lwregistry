<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PurchaseOrderBid;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class NewBidReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PurchaseOrderBid $bid
    ) {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        /*
         * In the bell as well as the inbox. This used to be mail only, so
         * something a member would open the app to check for existed nowhere
         * in it — and only ever arrived if the mail queue was running.
         */
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $orderTitle = optional($this->bid->purchaseOrder)->title ?? 'your procurement request';
        $bidderName = optional($this->bid->bidderSbo)->name
            ?? optional($this->bid->business)->name
            ?? optional($this->bid->user)->name
            ?? 'An SBO';
        $actionUrl = method_exists($notifiable, 'isAdmin') && $notifiable->isAdmin()
            ? route('admin.purchase-orders.index')
            : route('sbo.rfqs.index');

        return (new MailMessage())
            ->subject('New bid received - Loveworld Registry')
            ->greeting('Hello,')
            ->line('A new bid has been submitted on **' . $orderTitle . '**.')
            ->line('**Bidder:** ' . $bidderName)
            ->action('Review the Bid', $actionUrl)
            ->line('Log in to review the proposal and respond.')
            ->salutation('- Loveworld Registry Team');
    }

    /**
     * The notification card's payload.
     *
     * `type` is the slug App\Models\Notification maps to a category, an icon
     * and an action label — the classification lives there rather than being
     * repeated per class.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bid_received',
            'title' => 'New bid received',
            /*
             * The bidder is named from the relation the model actually has.
             * `sbo` is not one of them, and the models run under
             * preventAccessingMissingAttributes(), so reaching for it threw
             * rather than quietly returning null.
             */
            'message' => 'A new bid was submitted on '
                . (optional($this->bid->purchaseOrder)->title ?? 'your request') . '.',
            'url' => route('procurement.show', $this->bid->purchase_order_id),
        ];
    }
}
