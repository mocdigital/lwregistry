<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AdCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AdCampaignApprovedNotification extends Notification implements ShouldQueue
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
            ->subject('Your advertising campaign is approved – Loveworld Registry')
            ->greeting('Hello ' . ($notifiable->firstname ?? $notifiable->name ?? 'there') . ',')
            ->line('Your advertising campaign **' . $this->campaign->title . '** has been approved and scheduled.')
            ->line('Start: ' . ($this->campaign->starts_at?->format('d M Y') ?? 'Now'))
            ->line('End: ' . ($this->campaign->ends_at?->format('d M Y') ?? '—'))
            ->action('View Advertise Page', route('advertise'))
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
            'type' => 'ad_campaign_approved',
            'title' => 'Your advert is live',
            'message' => $this->campaign->title . ' has been approved and is now running.',
            'url' => route('advertise'),
        ];
    }
}
