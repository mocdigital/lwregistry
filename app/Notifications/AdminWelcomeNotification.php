<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AdminWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $plainPassword
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Welcome to Loveworld Registry – SBO Management')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('You have been granted SBO Management access to the Loveworld Business Registry.')
            ->line('Below are your login details:')
            ->line('**Email:** ' . $notifiable->email)
            ->line('**Temporary Password:** ' . $this->plainPassword)
            ->line('You can log in using the button below.')
            ->action('Login to SBO Dashboard', route('login'))
            ->line('For security reasons, we strongly recommend that you change your password after logging in.')
            ->line('If you did not expect this email, please contact the system administrator immediately.')
            ->salutation('— Loveworld Registry Team');
    }
}
