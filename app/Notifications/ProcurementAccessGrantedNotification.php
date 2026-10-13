<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Your request to see a private procurement request was approved.
 */
final class ProcurementAccessGrantedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected PurchaseOrder $procurement)
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
        return (new MailMessage())
            ->subject('You now have access to a procurement request – Loveworld Registry')
            ->line('Your request to view **' . $this->procurement->title . '** was approved.')
            ->action('View Request', route('procurement.show', $this->procurement))
            ->salutation('— Loveworld Registry Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'procurement_access_granted',
            'title' => 'Access granted',
            'message' => 'You can now view "' . $this->procurement->title . '".',
            'url' => route('procurement.show', $this->procurement),
        ];
    }
}
