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
 * Sent to compliance admins when an SBO uploads/re-uploads a document.
 */
final class ComplianceDocumentSubmittedNotification extends Notification implements ShouldQueue
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
        $sbo = $this->document->sbo;

        return (new MailMessage())
            ->subject('New compliance document submitted – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('**' . $sbo->name . '** has submitted a document for review:')
            ->line('**Document:** ' . $this->document->type->name)
            ->action('Review submission', route('admin.sbos.show', $sbo))
            ->salutation('— Loveworld Registry');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'compliance_document_submitted',
            'sbo_id' => $this->document->sbo_id,
            'sbo_name' => $this->document->sbo->name,
            'document' => $this->document->type->name,
            'title' => 'Document submitted',
            'message' => $this->document->sbo->name . ' submitted ' . $this->document->type->name . ' for review.',
            'url' => route('admin.sbos.show', $this->document->sbo_id),
        ];
    }
}
