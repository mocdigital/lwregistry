<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Adds real-time (Reverb/Echo) delivery to a notification so the in-app bell
 * updates instantly. Reuses the notification's existing toArray() payload so the
 * broadcast and the stored database row carry the same data.
 */
trait BroadcastsToBell
{
    /**
     * The websocket payload — same shape as the stored notification.
     *
     * The broadcast is queued (the notification's default connection), so a
     * websocket/Reverb outage can never break the request that triggered the
     * notification: the database row is still written synchronously and the bell
     * still works via wire:poll. The broadcast simply retries on the queue.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
