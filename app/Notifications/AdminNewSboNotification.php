<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AdminNewSboNotification extends Notification implements ShouldQueue
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
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ownerName = optional($this->sbo->creator)->name
            ?? mb_trim($this->sbo->owner_first_name . ' ' . $this->sbo->owner_surname)
            ?: 'Unknown';

        return (new MailMessage())
            ->subject('New SBO registration pending review – Loveworld Registry')
            ->greeting('Hello Admin,')
            ->line('A new SBO has registered and is awaiting approval.')
            ->line('**SBO:** ' . $this->sbo->name)
            ->line('**Application ID:** ' . ($this->sbo->application_id ?? '—'))
            ->line('**Owner:** ' . $ownerName)
            ->line('**Status:** ' . ($this->sbo->verification_status ?? 'pending'))
            ->action('Review Application', route('admin.sbos.show', $this->sbo))
            ->salutation('— Loveworld Registry System');
    }
}
