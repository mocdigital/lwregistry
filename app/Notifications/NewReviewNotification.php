<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class NewReviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Review $review
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
        $businessName = optional($this->review->business)->name ?? 'your business';
        $reviewerName = optional($this->review->user)->name ?? 'A customer';
        $rating = (int) $this->review->rating;

        $message = (new MailMessage())
            ->subject('New review on ' . $businessName . ' – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line($reviewerName . ' left a new review on **' . $businessName . '**.')
            ->line('**Rating:** ' . $rating . ' / 5');

        if ( ! empty($this->review->comment)) {
            $message->line('**Comment:** "' . $this->review->comment . '"');
        }

        return $message
            ->action('View Your Business', route('login'))
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
            'type' => 'review_received',
            'title' => 'New review on your listing',
            'message' => 'Somebody left a ' . $this->review->rating . '-star review on your listing.',
            'url' => null,
        ];
    }
}
