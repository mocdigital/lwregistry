<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Someone has asked to see the owner's private procurement request.
 */
final class ProcurementAccessRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PurchaseOrder $procurement,
        protected User $requester,
    ) {
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
            ->subject('Someone requested access to your request – Loveworld Registry')
            ->line($this->requesterName() . ' has asked to view your private request **' . $this->procurement->title . '**.')
            ->action('Review Request', route('procurement.show', $this->procurement))
            ->salutation('— Loveworld Registry Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'procurement_access_requested',
            'title' => 'Access requested',
            'message' => $this->requesterName() . ' asked to view "' . $this->procurement->title . '".',
            'url' => route('procurement.show', $this->procurement),
        ];
    }

    private function requesterName(): string
    {
        $name = mb_trim(($this->requester->firstname ?? '') . ' ' . ($this->requester->lastname ?? ''));

        return '' !== $name ? $name : ($this->requester->email ?? 'A member');
    }
}
