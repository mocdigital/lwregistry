<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SupportEnquiry;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SupportInquiryRespondedNotification extends Notification implements ShouldQueue
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
            ->subject('Response to your support enquiry – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Our support team has responded to your enquiry.')
            ->line('**Subject:** ' . $this->enquiry->subject)
            ->line('**Response:**')
            ->line($this->enquiry->admin_response ?? '')
            ->action('Open Support', route('support.index'))
            ->salutation('— Loveworld Registry Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_enquiry_responded',
            'support_enquiry_id' => $this->enquiry->id,
            'title' => 'Support response received',
            'message' => 'Support responded to: ' . $this->enquiry->subject,
        ];
    }
}
