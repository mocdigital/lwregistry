<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\FeedbackSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

final class FeedbackSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected FeedbackSubmission $feedback
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
        return (new MailMessage())
            ->subject('New feedback submitted – Loveworld Registry')
            ->greeting('Hello Admin,')
            ->line('A signed-in user submitted feedback from the Loveworld Registry feedback page.')
            ->line('**Name:** ' . $this->feedback->name)
            ->line('**KC Handle:** ' . $this->feedback->kc_handle)
            ->line('**Email:** ' . $this->feedback->email)
            ->line('**Type:** ' . $this->feedback->typeLabel())
            ->line('**Priority:** ' . $this->feedback->priorityLabel())
            ->line('**Subject:** ' . $this->feedback->subject)
            ->line('**Related page:** ' . ($this->feedback->page_url ?: 'Not provided'))
            ->line('**Message:**')
            ->line(Str::limit($this->feedback->message, 1200))
            ->action('Review feedback', route('admin.support.feedback'))
            ->salutation('— Loveworld Registry System');
    }
}
