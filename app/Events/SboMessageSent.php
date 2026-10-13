<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SboMessage;
use App\Models\SboMessageAttachment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was posted into an SBO conversation.
 *
 * Broadcast on the conversation's own private channel so an open inbox appends
 * the bubble without a reload. `SboMessageReceivedNotification` fires alongside
 * it and drives the bell; this is the thread itself, which is a different
 * audience (whoever currently has the conversation open) and a different
 * payload (the message, not a summary).
 *
 * Deliberately NOT ShouldBroadcastNow / queued-by-default: the send already
 * wrote the row and redirected, so if Reverb is down or unconfigured the
 * conversation is still correct on the next request. Broadcasting is the
 * optimisation, never the source of truth.
 */
final class SboMessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly SboMessage $message) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('sbo.conversation.' . $this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * The same shape the conversation thread is rendered from, so the client
     * can append it directly rather than re-deriving anything.
     *
     * `mine` is deliberately absent: the channel is shared by both sides, so
     * whether a message is "mine" depends on who is listening. The client
     * compares `userId` against its own.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => (string) $this->message->id,
            'conversationId' => (string) $this->message->conversation_id,
            'userId' => (string) $this->message->user_id,
            'author' => $this->message->sbo?->name,
            'body' => $this->message->body,
            'at' => $this->message->created_at?->format('M j, g:ia'),
            /*
             * Files travel with the live message, not on the next page load.
             * Without them a photograph sent to someone who has the thread
             * open arrives as an empty bubble and stays that way until they
             * reload — which reads as a message that failed to send.
             */
            // The quote travels too, or a reply delivered live renders with
            // no context until the reader reloads.
            'replyTo' => null === $this->message->replyTo ? null : [
                'id' => (string) $this->message->replyTo->id,
                'author' => $this->message->replyTo->sbo?->name,
                'body' => str($this->message->replyTo->body)->limit(120)->toString(),
            ],
            'attachments' => $this->message->attachments
                ->map(fn (SboMessageAttachment $a): array => [
                    'id' => (string) $a->id,
                    'kind' => $a->kind,
                    'name' => $a->original_name,
                    'size' => $a->size,
                    'url' => route('sbo.message-attachments.show', $a),
                ])
                ->values()
                ->all(),
        ];
    }
}
