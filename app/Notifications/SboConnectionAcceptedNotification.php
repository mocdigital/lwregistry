<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SboConnection;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SboConnectionAcceptedNotification extends Notification implements ShouldQueue
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
        $recipient = $this->sboConnection->recipient->name ?? 'An SBO';

        return (new MailMessage())
            ->subject('SBO connection accepted - Loveworld Registry')
            ->greeting('Hello ' . ($notifiable->firstname ?? 'there') . ',')
            ->line($recipient . ' accepted your connection request.')
            ->action('Open SBO Network', route('sbo.network.index'))
            ->line('You can now message each other from the network workspace.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        // Accepting opens a conversation, so the notification opens it too — the
        // thread if there is one, the inbox otherwise.
        $conversationId = $this->sboConnection->conversation?->id;

        return [
            'title' => 'SBO connection accepted',
            'message' => ($this->sboConnection->recipient->name ?? 'An SBO') . ' accepted your request.',
            'url' => route('sbo.network.index', array_filter([
                'tab' => 'messages',
                'conversation' => $conversationId,
            ])),
            'type' => 'sbo_connection_accepted',
            'connection_id' => $this->sboConnection->id,
        ];
    }
}
