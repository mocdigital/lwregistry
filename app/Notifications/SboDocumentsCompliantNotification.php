<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SboDocumentsCompliantNotification extends Notification implements ShouldQueue
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(
        protected Sbo $sbo
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
            ->subject('Your documents are compliant – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('The documents submitted for **' . $this->sbo->name . '** have been reviewed and confirmed **compliant**.')
            ->line($this->outstandingLine())
            ->action('View your dashboard', route('login'))
            ->salutation('— Loveworld Registry Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sbo_documents_compliant',
            'sbo_id' => $this->sbo->id,
            'sbo_name' => $this->sbo->name,
            'title' => 'Documents compliant',
            'message' => 'Documents for ' . $this->sbo->name . ' were confirmed compliant.',
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

        if ( ! $this->sbo->feesPaid()) {
            $outstanding[] = 'payment of your registration fees';
        }

        if ([] === $outstanding) {
            return 'All compliance steps are now complete.';
        }

        return 'Still outstanding for full verification: ' . implode(' and ', $outstanding) . '.';
    }
}
