<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SboApprovedNotification extends Notification implements ShouldQueue
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
            'type' => 'sbo_approved',
            'sbo_id' => $this->sbo->id,
            'sbo_name' => $this->sbo->name,
            'title' => 'Registration approved',
            'message' => 'Your registration for ' . $this->sbo->name . ' has been approved.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->subject('Your SBO has been approved – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Congratulations! Your registration for **' . $this->sbo->name . '** has been approved.')
            ->line('**Application ID:** ' . $this->sbo->application_id);

        if ( ! empty($this->sbo->moc_registration_number)) {
            $message->line('**Loveworld Kingdom (MOC) Registration Number:** ' . $this->sbo->moc_registration_number);
        }

        return $message
            ->action('Go to Dashboard', route('login'))
            ->line('You can now access all SBO features on the Loveworld Business Registry.')
            ->salutation('— Loveworld Registry Team');
    }
}
