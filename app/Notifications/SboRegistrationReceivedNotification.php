<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use App\Notifications\Concerns\BroadcastsToBell;
use App\Support\SboComplianceRequirements;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SboRegistrationReceivedNotification extends Notification implements ShouldQueue
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
     * Deliver the in-app (database) notification synchronously so the bell
     * updates immediately; email stays on the queue.
     *
     * @return array<string, string|null>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $requiredDocuments = SboComplianceRequirements::requiredNamesForMail($this->sbo);

        $message = (new MailMessage())
            ->subject('Welcome to the SBO Registry – Loveworld Registry')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Welcome to the SBO Registry. Your email has been verified.')
            ->line('Next, complete your registration fee payment from your Application Progress page.')
            ->line('Full verification is completed after your registration fee is approved and your required documents are confirmed compliant.')
            ->line('We\'ll email you as each remaining step is completed.');

        if ($requiredDocuments->isNotEmpty()) {
            $message->line('Based on your business country, please prepare these required document(s):');

            foreach ($requiredDocuments as $documentName) {
                $message->line('- ' . $documentName);
            }
        } else {
            $message->line('No document requirements are configured yet for your business country. Your dashboard will update when the registry team adds them.');
        }

        return $message
            ->action('Continue to registration fees', route('sbo.applications.progress', ['sbo' => $this->sbo, 'step' => 'fees']))
            ->salutation('— Loveworld Registry Team');
    }

    /**
     * In-app notification payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sbo_registration_received',
            'sbo_id' => $this->sbo->id,
            'sbo_name' => $this->sbo->name,
            'title' => 'Welcome to the SBO Registry',
            'message' => 'Welcome to the SBO Registry — your email has been verified. Continue to registration fees for ' . $this->sbo->name . ' from Application Progress.',
        ];
    }
}
