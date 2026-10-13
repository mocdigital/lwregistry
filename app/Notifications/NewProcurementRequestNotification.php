<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class NewProcurementRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PurchaseOrder $purchaseOrder
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
        $organization = $this->purchaseOrder->sbo->name ?? 'an SBO';
        $category = $this->purchaseOrder->category->name ?? 'your category';

        return (new MailMessage())
            ->subject('New procurement request available - Loveworld Registry')
            ->greeting('Hello ' . ($notifiable->firstname ?? 'there') . ',')
            ->line($organization . ' has posted a new public procurement request for fully verified SBOs.')
            ->line('Request: ' . $this->purchaseOrder->title)
            ->line('Category: ' . $category)
            ->action('View Procurement Requests', route('dashboard'))
            ->line('Submit your response before the request deadline if it is a fit.')
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
            'type' => 'procurement_posted',
            'title' => 'New opportunity posted',
            'message' => $this->purchaseOrder->title . ' is open for bids.',
            'url' => route('procurement.show', $this->purchaseOrder),
        ];
    }
}
