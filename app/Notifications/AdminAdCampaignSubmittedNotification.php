<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AdCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AdminAdCampaignSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected AdCampaign $campaign
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
        $slot = $this->campaign->slotConfig();

        return (new MailMessage())
            ->subject('New paid ad campaign awaiting review – Loveworld Registry')
            ->greeting('Hello Admin,')
            ->line('A new self-service advertising campaign has been paid and is awaiting creative review.')
            ->line('**Campaign:** ' . $this->campaign->title)
            ->line('**Placement:** ' . ($slot['label'] ?? $this->campaign->slot))
            ->line('**Duration:** ' . $this->campaign->duration_days . ' days')
            ->line('**Amount:** ' . number_format($this->campaign->price) . ' ' . $this->campaign->currency)
            ->action('Review Campaign', route('admin.ads.index'))
            ->salutation('— Loveworld Registry System');
    }
}
