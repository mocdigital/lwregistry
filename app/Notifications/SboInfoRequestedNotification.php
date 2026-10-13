<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SboInfoRequestedNotification extends Notification implements ShouldQueue
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
            'type' => 'sbo_info_requested',
            'sbo_id' => $this->sbo->id,
            'sbo_name' => $this->sbo->name,
            'title' => 'Information requested',
            'message' => 'More information is needed for ' . $this->sbo->name . '.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('More information needed for your SBO registration – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Your registration for **' . $this->sbo->name . '** is under review, and our team needs some additional information before it can be approved.')
            ->line('**What we need:**')
            ->line($this->sbo->info_request)
            ->action('Update & Resubmit', route('sbo.dashboard'))
            ->line('Please update your details from your dashboard and resubmit for review.')
            ->salutation('— Loveworld Registry Team');
    }
}
