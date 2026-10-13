<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PurchaseOrderInvite;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PurchaseOrderInviteNotification extends Notification implements ShouldQueue
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(private readonly PurchaseOrderInvite $invite)
    {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->invite->purchaseOrder;
        $inviter = $this->invite->invitedBySbo->name ?? 'An SBO';

        return (new MailMessage())
            ->subject('Private bid invitation - Loveworld Registry')
            ->greeting('Hello ' . ($notifiable->firstname ?? 'there') . ',')
            ->line($inviter . ' invited your SBO to bid on a private procurement request.')
            ->line('Request: ' . ($order->title ?? 'Procurement request'))
            ->action('View Procurement Requests', route('dashboard'))
            ->line('Only invited fully verified SBOs can see and respond to this request.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Private bid invitation',
            'message' => 'You were invited to bid on ' . ($this->invite->purchaseOrder->title ?? 'a procurement request') . '.',
            'url' => route('dashboard'),
            'type' => 'purchase_order_invite',
            'purchase_order_id' => $this->invite->purchase_order_id,
            'invite_id' => $this->invite->id,
        ];
    }
}
