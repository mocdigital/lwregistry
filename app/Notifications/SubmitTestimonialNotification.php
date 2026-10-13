<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PurchaseOrderBid;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invite the business that just won a bid to share a testimonial.
 *
 * Winning is the moment to ask: the engagement is fresh and the outcome is
 * good. The link opens the won request with the testimonial form ready, and
 * the form itself asks whether they are happy to have it shown publicly.
 */
final class SubmitTestimonialNotification extends Notification implements ShouldQueue
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(private readonly PurchaseOrderBid $bid)
    {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = optional($this->bid->purchaseOrder)->title ?? 'a procurement request';

        return (new MailMessage())
            ->subject('Share your success – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Congratulations on winning **' . $title . '**.')
            ->line('Would you share a short testimonial about the engagement? You choose whether it is shown on the platform.')
            ->action('Write a testimonial', $this->url())
            ->salutation('- Loveworld Registry Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'testimonial_requested',
            'title' => 'Share your success',
            'message' => 'You won '
                . (optional($this->bid->purchaseOrder)->title ?? 'a procurement request')
                . ' — write a short testimonial.',
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        // The detail page opens its testimonial form when it sees this flag.
        return route('procurement.show', $this->bid->purchase_order_id) . '?testimonial=1';
    }
}
