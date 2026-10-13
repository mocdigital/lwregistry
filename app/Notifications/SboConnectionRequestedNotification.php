<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SboConnection;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SboConnectionRequestedNotification extends Notification implements ShouldQueue
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(private readonly SboConnection $sboConnection)
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
        $requester = $this->sboConnection->requester->name ?? 'An SBO';

        return (new MailMessage())
            ->subject('New SBO connection request - Loveworld Registry')
            ->greeting('Hello ' . ($notifiable->firstname ?? 'there') . ',')
            ->line($requester . ' would like to connect with your SBO on the Loveworld Registry.')
            ->action('Review Request', route('sbo.network.index'))
            ->line('Accept the request before messaging or collaborating directly.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New SBO connection request',
            'message' => ($this->sboConnection->requester->name ?? 'An SBO') . ' wants to connect.',
            'url' => route('sbo.network.index'),
            'type' => 'sbo_connection_requested',
            'connection_id' => $this->sboConnection->id,
        ];
    }
}
