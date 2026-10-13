<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SboDeclinedNotification extends Notification implements ShouldQueue
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(
        protected Sbo $sbo
    ) {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    /**
     * In-app notification delivers synchronously; email stays queued.
     *
     * @return array<string, string|null>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sbo_declined',
            'sbo_id' => $this->sbo->id,
            'sbo_name' => $this->sbo->name,
            'title' => 'Registration declined',
            'message' => 'Your registration for ' . $this->sbo->name . ' was declined.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->subject('Update on your SBO registration – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Thank you for registering **' . $this->sbo->name . '** (Application ID: ' . $this->sbo->application_id . ').')
            ->line('After review, your registration could not be approved at this time.');

        if ( ! empty($this->sbo->decline_reason)) {
            $message->line('**Reason:** ' . $this->sbo->decline_reason);
        }

        return $message
            ->line('If you believe this was in error or wish to provide more information, please contact the registry administrators.')
            ->salutation('— Loveworld Registry Team');
    }
}
