<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class BusinessRegisteredNotification extends Notification implements ShouldQueue
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
            ->subject('Your business has been registered – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Your business **' . $this->business->name . '** has been successfully registered on the Loveworld Business Registry.')
            ->line('It is now pending review. We will notify you once it has been verified.')
            ->action('View Dashboard', route('login'))
            ->line('Thank you for being part of the Loveworld Business community.')
            ->salutation('— Loveworld Registry Team');
    }
}
