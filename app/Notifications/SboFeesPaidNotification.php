<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use App\Notifications\Concerns\BroadcastsToBell;
use App\Support\SboFeeCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SboFeesPaidNotification extends Notification implements ShouldQueue
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(
        protected Sbo $sbo,
        protected ?int $confirmedAmount = null,
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
     * In-app notification delivers synchronously; email stays queued.
     *
     * @return array<string, string|null>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your registration fee payment has been approved – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('We have confirmed the registration fee payment for **' . $this->sbo->name . '**. Thank you.')
            ->line('Application ID: ' . $this->sbo->application_id)
            ->line('Amount confirmed: ' . number_format($this->confirmedAmount ?? (int) $this->sbo->calculated_fee) . ' ' . SboFeeCalculator::currency($this->sbo->country_id))
            ->line('Please log in to your dashboard to set up your business listings and keep your organization profile current.')
            ->line($this->outstandingLine())
            ->action('Set up business listings', route('dashboard'))
            ->salutation('— Loveworld Registry Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sbo_fees_paid',
            'sbo_id' => $this->sbo->id,
            'sbo_name' => $this->sbo->name,
            'title' => 'Registration payment approved',
            'message' => 'Registration fee payment for ' . $this->sbo->name . ' was approved. Log in to set up your business listings.',
        ];
    }

    /**
     * Tell the owner what still stands between them and Verified status.
     */
    private function outstandingLine(): string
    {
        $outstanding = [];

        if ( ! $this->sbo->isApproved()) {
            $outstanding[] = 'administrator approval of your registration';
        }

        if ( ! $this->sbo->documentsCompliant()) {
            $outstanding[] = 'confirmation that your documents are compliant';
        }

        if ([] === $outstanding) {
            return 'All compliance steps are now complete.';
        }

        return 'Still outstanding for full verification: ' . implode(' and ', $outstanding) . '.';
    }
}
