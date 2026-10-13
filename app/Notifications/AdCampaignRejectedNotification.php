<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AdCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AdCampaignRejectedNotification extends Notification implements ShouldQueue
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
            ->error()
            ->subject('Advertising campaign needs attention – Loveworld Registry')
            ->greeting('Hello ' . ($notifiable->firstname ?? $notifiable->name ?? 'there') . ',')
            ->line('Your advertising campaign **' . $this->campaign->title . '** was not approved.')
            ->line('Reason: ' . ($this->campaign->rejection_reason ?: 'Please contact support for details.'))
            ->line('Your payment is recorded. The registry team will handle the next step manually.')
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
        $reason = mb_trim((string) $this->campaign->rejection_reason);

        return [
            'type' => 'ad_campaign_rejected',
            'title' => 'Your advert was not approved',
            // The reason belongs in the bell too, not only the email — it is
            // what tells the advertiser what to fix before submitting again.
            'message' => '' !== $reason
                ? $this->campaign->title . ' was not approved. Reason: ' . $reason
                : $this->campaign->title . ' was not approved. Check the details and submit again.',
            'url' => route('advertise'),
        ];
    }
}
