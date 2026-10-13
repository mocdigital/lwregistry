<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AdminNewBusinessNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Business $business
    ) {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ownerName = optional($this->business->user)->name ?? 'Unknown';

        return (new MailMessage())
            ->subject('New business registered – action may be required')
            ->greeting('Hello Admin,')
            ->line('A new business has just been registered on the Loveworld Business Registry and may require review.')
            ->line('**Business:** ' . $this->business->name)
            ->line('**Owner:** ' . $ownerName)
            ->line('**Status:** ' . $this->business->status)
            ->action('Review in Admin Dashboard', route('login'))
            ->salutation('— Loveworld Registry System');
    }
}
