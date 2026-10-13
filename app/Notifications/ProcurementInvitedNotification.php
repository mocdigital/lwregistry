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
 * You have been invited to a private procurement request.
 *
 * Sent to an invited member (in the app and by mail) and to a bare email
 * address (by mail only — there is no account to file a bell entry against).
 */
final class ProcurementInvitedNotification extends Notification implements ShouldQueue
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
        // A member gets the bell too; an email invite to a stranger has no
        // account, so it can only be mailed.
        return $notifiable instanceof User
            ? ['mail', 'database', 'broadcast']
            : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('You are invited to a procurement request – Loveworld Registry')
            ->line('You have been invited to a private procurement request: **' . $this->procurement->title . '**.')
            ->line('Sign in with this email address to view it and, if you are a verified business, to bid.')
            ->action('View Request', route('procurement.show', $this->procurement))
            ->salutation('— Loveworld Registry Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'procurement_invited',
            'title' => 'You are invited to a request',
            'message' => 'You have been invited to the request "' . $this->procurement->title . '".',
            'url' => route('procurement.show', $this->procurement),
        ];
    }
}
