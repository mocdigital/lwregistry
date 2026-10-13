<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SboComplianceDocument;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the SBO owner when a submitted document is rejected (with a reason).
 */
final class ComplianceDocumentRejectedNotification extends Notification implements ShouldQueue
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(
        protected SboComplianceDocument $document
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
        $message = (new MailMessage())
            ->subject('Action needed: a compliance document was rejected – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Your **' . $this->document->type->name . '** for **' . $this->document->sbo->name . '** was not accepted.');

        if ( ! empty($this->document->rejection_reason)) {
            $message->line('**Reason:** ' . $this->document->rejection_reason);
        }

        return $message
            ->line('Please upload a corrected document from your dashboard.')
            ->action('Re-upload document', route('login'))
            ->salutation('— Loveworld Registry');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'compliance_document_rejected',
            'sbo_id' => $this->document->sbo_id,
            'sbo_name' => $this->document->sbo->name,
            'document' => $this->document->type->name,
            'reason' => $this->document->rejection_reason,
            'title' => 'Document rejected',
            'message' => 'Your ' . $this->document->type->name . ' was rejected. Please re-upload.',
        ];
    }
}
