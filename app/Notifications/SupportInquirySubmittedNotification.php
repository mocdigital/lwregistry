<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SupportEnquiry;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SupportInquirySubmittedNotification extends Notification implements ShouldQueue
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(
        protected SupportEnquiry $enquiry
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
     * @return array<string, string|null>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('New support enquiry – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('A new support enquiry has been submitted.')
            ->line('**From:** ' . $this->enquiry->first_name . ' ' . $this->enquiry->last_name)
            ->line('**SBO Number:** ' . ($this->enquiry->sbo_number ?: 'Not provided'))
            ->line('**Subject:** ' . $this->enquiry->subject)
            ->action('Review support enquiry', route('admin.support.index'))
            ->salutation('— Loveworld Registry');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_enquiry_submitted',
            'support_enquiry_id' => $this->enquiry->id,
            'title' => 'New support enquiry',
            'message' => $this->enquiry->first_name . ' ' . $this->enquiry->last_name . ' asked for support: ' . $this->enquiry->subject,
            'url' => route('admin.support.index', ['enquiry' => $this->enquiry->id]),
        ];
    }
}
