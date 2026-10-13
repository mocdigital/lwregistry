<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SboMessage;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class SboMessageReceivedNotification extends Notification implements ShouldQueue
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(private readonly SboMessage $message)
    {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $sender = $this->message->sbo->name ?? 'An SBO';

        return [
            'title' => 'New SBO network message',
            'message' => $sender . ': ' . str($this->message->body)->limit(80),
            // Straight to the thread the message is in, not the network landing
            // page — "open conversation" should open THIS conversation. The
            // conversation id rides as a query parameter, which the inbox reads
            // to select the thread.
            //
            // And to the right inbox: a message to the registry reaches an
            // administrator, whose thread lives on the admin dashboard, not in
            // the member workspace they have no reason to open.
            'url' => $this->destinationFor($notifiable),
            'type' => 'sbo_message_received',
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->id,
        ];
    }

    /**
     * Where clicking this notification should land the recipient.
     *
     * The admin inbox when the recipient is an administrator reading a thread
     * the registry is part of; the member workspace otherwise. Keyed on the
     * registry actually being in the conversation, so an administrator who also
     * owns a listing is still sent to the member workspace for their own
     * private threads — those do not appear in the admin inbox.
     */
    private function destinationFor(object $notifiable): string
    {
        $isRegistryThread = $this->message->conversation
            ?->participants()
            ->where('sbos.is_registry', true)
            ->exists();

        $toAdmin = $isRegistryThread
            && method_exists($notifiable, 'isAdmin')
            && $notifiable->isAdmin();

        if ($toAdmin) {
            return route('admin.messages.index', ['conversation' => $this->message->conversation_id]);
        }

        return route('sbo.network.index', [
            'tab' => 'messages',
            'conversation' => $this->message->conversation_id,
        ]);
    }
}
