<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Zone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a zonal pastor their zone's unique referral link so they can drive
 * registrations within their zone. Sent to an arbitrary address (the pastor is
 * not necessarily a platform user) via an AnonymousNotifiable. Mail-only.
 */
final class ZoneReferralLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Zone $zone
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
        $greetingName = null !== $this->zone->pastor_name && '' !== $this->zone->pastor_name
            ? $this->zone->pastor_name
            : 'Pastor';

        return (new MailMessage())
            ->subject('Your zone registration link – Loveworld Registry')
            ->greeting('Hello ' . $greetingName . ',')
            ->line('Here is the unique registration link for **' . $this->zone->name . '**.')
            ->line('Share it with businesses in your zone. Anyone who registers through this link is automatically counted towards your zone, and their zone is pre-filled on the form.')
            ->action('Register a business in ' . $this->zone->name, $this->zone->referralUrl())
            ->line('You can reuse and share this link freely — it does not expire unless the registry regenerates it.')
            ->salutation('— Loveworld Registry Team');
    }
}
