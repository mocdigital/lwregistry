<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SboManagerAssignedNotification extends Notification implements ShouldQueue
{
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
        /*
         * In the bell as well as the inbox. This used to be mail only, so
         * something a member would open the app to check for existed nowhere
         * in it — and only ever arrived if the mail queue was running.
         */
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('You have been assigned to manage ' . $this->sbo->name . ' – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('You have been assigned as the manager of **' . $this->sbo->name . '** on the Loveworld Business Registry.')
            ->line('You can now manage this SBO, its procurement requests, and its details from your dashboard.')
            ->action('Go to Dashboard', route('login'))
            ->line('If you did not expect this, please contact the registry administrators.')
            ->salutation('— Loveworld Registry Team');
    }

    /**
     * The notification card's payload.
     *
     * `type` is the slug App\Models\Notification maps to a category, an icon
     * and an action label — the classification lives there rather than being
     * repeated per class.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sbo_manager_assigned',
            'title' => 'You now manage a listing',
            'message' => 'You have been made the manager of ' . $this->sbo->name . '.',
            'url' => route('sbo.applications.progress', $this->sbo),
        ];
    }
}
