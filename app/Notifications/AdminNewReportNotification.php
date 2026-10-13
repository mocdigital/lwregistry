<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AdminNewReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Report $report
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
        $sboName = optional($this->report->sbo)->name ?? 'an SBO';

        return (new MailMessage())
            ->subject('New report filed – Loveworld Registry')
            ->greeting('Hello Admin,')
            ->line('A new report has been filed on the Loveworld Business Registry.')
            ->line('**Title:** ' . $this->report->title)
            ->line('**SBO:** ' . $sboName)
            ->line('**Status:** ' . ($this->report->status ?? 'pending'))
            ->action('Review in Admin Dashboard', route('login'))
            ->salutation('— Loveworld Registry System');
    }
}
