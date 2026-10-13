<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PurchaseOrderBid;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class BidStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PurchaseOrderBid $bid,
        protected string $status
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
        $orderTitle = optional($this->bid->purchaseOrder)->title ?? 'a procurement request';
        $accepted = 'accepted' === $this->status;

        $message = (new MailMessage())
            ->subject(($accepted ? 'Your bid was accepted' : 'Update on your bid') . ' - Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',');

        if ($accepted) {
            $message
                ->line('Congratulations! Your bid on **' . $orderTitle . '** has been **accepted**.')
                ->line('Please log in to view the next steps and coordinate fulfilment.');
        } else {
            $message
                ->line('Thank you for your bid on **' . $orderTitle . '**.')
                ->line('On this occasion your bid was **not selected**. We encourage you to bid on future opportunities.');
        }

        return $message
            ->action('View in Dashboard', route('dashboard'))
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
            'type' => 'accepted' === $this->status ? 'bid_accepted' : 'bid_declined',
            'title' => 'accepted' === $this->status ? 'Your bid was accepted' : 'Update on your bid',
            'message' => ('accepted' === $this->status ? 'Your bid on ' : 'Your bid was not selected for ')
                . (optional($this->bid->purchaseOrder)->title ?? 'a procurement request')
                . ('accepted' === $this->status ? ' has been accepted.' : '.'),
            'url' => route('procurement.show', $this->bid->purchase_order_id),
        ];
    }
}
