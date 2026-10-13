<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the owner right after their registration fee is confirmed, prompting
 * them to upload their country-specific compliance documents — the last step
 * before full verification.
 */
final class SboUploadDocumentsNotification extends Notification implements ShouldQueue
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
            ->subject('Next step: upload your compliance documents – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Your registration fee for **' . $this->sbo->name . '** has been confirmed. Thank you.')
            ->line('To complete your registration and become a fully verified member of the Loveworld Registry, please upload your country-specific compliance documents now.')
            ->line('As soon as your required documents are uploaded, your registration moves to full validation.')
            ->action('Upload documents', route('sbo.applications.progress', ['sbo' => $this->sbo, 'step' => 'documents']))
            ->salutation('— Loveworld Registry Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sbo_upload_documents',
            'sbo_id' => $this->sbo->id,
            'sbo_name' => $this->sbo->name,
            'title' => 'Upload your compliance documents',
            'message' => 'Fees confirmed for ' . $this->sbo->name . '. Upload your country-specific compliance documents to finish verification.',
        ];
    }
}
