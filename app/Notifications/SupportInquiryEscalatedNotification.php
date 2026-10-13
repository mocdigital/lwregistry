<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SupportEnquiry;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SupportInquiryEscalatedNotification extends Notification implements ShouldQueue
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
            ->subject('Support enquiry escalated – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('A support enquiry has been escalated to super admin review.')
            ->line('**From:** ' . $this->enquiry->first_name . ' ' . $this->enquiry->last_name)
            ->line('**Subject:** ' . $this->enquiry->subject)
            ->action('Review escalation', route('admin.support.index'))
            ->salutation('— Loveworld Registry');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_enquiry_escalated',
            'support_enquiry_id' => $this->enquiry->id,
            'title' => 'Support enquiry escalated',
            'message' => $this->enquiry->subject . ' was escalated for super admin review.',
            'url' => route('admin.support.index', ['enquiry' => $this->enquiry->id]),
        ];
    }
}
