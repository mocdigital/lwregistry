<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class BusinessVerifiedNotification extends Notification implements ShouldQueue
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
        return (new MailMessage())
            ->subject('Your business is now verified – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Great news! Your business **' . $this->business->name . '** has been verified on the Loveworld Business Registry.')
            ->line('Verified businesses gain a trust badge and improved visibility across the registry.')
            ->action('View Your Business', route('login'))
            ->salutation('— Loveworld Registry Team');
    }
}
