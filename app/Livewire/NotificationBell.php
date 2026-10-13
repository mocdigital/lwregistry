<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class NotificationBell extends Component
{
    /** How many recent notifications to show in the dropdown. */
    private const RECENT_LIMIT = 8;

    /**
     * Unread notification count for the badge.
     */
    #[Computed]
    public function unreadCount(): int
    {
        $user = Auth::user();

        return $user ? $user->unreadNotifications()->count() : 0;
    }

    /**
     * Most recent notifications (read or unread) for the dropdown list.
     *
     * @return Collection<int, \Illuminate\Notifications\DatabaseNotification>
     */
    #[Computed]
    public function recent(): Collection
    {
        $user = Auth::user();

        if ( ! $user) {
            return collect();
        }

        return $user->notifications()
            ->latest()
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * Poll target: re-evaluate the unread count and recent list. The computed
     * properties recompute on render, so this just needs to bust their caches.
     */
    public function refresh(): void
    {
        unset($this->unreadCount, $this->recent);
    }

    /**
     * Mark every notification read (called when the dropdown opens).
     */
    public function markAllRead(): void
    {
        Auth::user()?->unreadNotifications->markAsRead();

        // Refresh the computed values.
        unset($this->unreadCount, $this->recent);
    }

    /**
     * Mark a single notification read.
     */
    public function markRead(string $id): void
    {
        $notification = Auth::user()?->notifications()->whereKey($id)->first();
        $notification?->markAsRead();

        unset($this->unreadCount, $this->recent);
    }

    /**
     * Open a notification: mark it read and go to what it is about.
     *
     * Notifications carry a `url` in their payload; clicking one should take
     * the reader straight there — the report queue, the SBO under review, the
     * conversation — not just clear the dot. A full redirect rather than
     * wire:navigate, because a notification can point at a page on a different
     * layout (the SBO workspace vs. the admin dashboard) and a client-side body
     * swap would leave the destination half-styled.
     */
    public function open(string $id)
    {
        $notification = Auth::user()?->notifications()->whereKey($id)->first();

        if (null === $notification) {
            return null;
        }

        $notification->markAsRead();
        unset($this->unreadCount, $this->recent);

        $url = $notification->data['url'] ?? null;

        return is_string($url) && '' !== $url ? $this->redirect($url) : null;
    }

    public function render()
    {
        return view('livewire.notification-bell');
    }
}
