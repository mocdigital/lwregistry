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
 * Sent to the SBO owner when a submitted document is approved.
 */
final class ComplianceDocumentApprovedNotification extends Notification implements ShouldQueue
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
        return (new MailMessage())
            ->subject('A compliance document was approved – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Your **' . $this->document->type->name . '** for **' . $this->document->sbo->name . '** has been approved.')
            ->action('View your dashboard', route('login'))
            ->salutation('— Loveworld Registry');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'compliance_document_approved',
            'sbo_id' => $this->document->sbo_id,
            'sbo_name' => $this->document->sbo->name,
            'document' => $this->document->type->name,
            'title' => 'Document approved',
            'message' => 'Your ' . $this->document->type->name . ' was approved.',
        ];
    }
}
