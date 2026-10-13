<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AdvertisingInquirySubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{name: string, kc_handle: string, email: string, ad_location: string, message: string}  $inquiry
     * @param  array<string, mixed>  $slot
     */
    public function __construct(
        protected array $inquiry,
        protected array $slot,
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
        $location = (string) ($this->slot['label'] ?? $this->inquiry['ad_location']);
        $dimensions = (string) ($this->slot['width'] ?? '—') . '×' . (string) ($this->slot['height'] ?? '—') . ' px';

        return (new MailMessage())
            ->subject('New advertising inquiry – Loveworld Registry')
            ->greeting('Hello Admin,')
            ->line('A new advertising inquiry has been submitted from the public advertising page.')
            ->line('**Name:** ' . $this->inquiry['name'])
            ->line('**KC Handle:** ' . $this->inquiry['kc_handle'])
            ->line('**Email:** ' . $this->inquiry['email'])
            ->line('**Preferred ad location:** ' . $location . ' (' . $dimensions . ')')
            ->line('**Message:**')
            ->line($this->inquiry['message'])
            ->salutation('— Loveworld Registry System');
    }
}
