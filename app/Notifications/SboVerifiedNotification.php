<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SboVerifiedNotification extends Notification implements ShouldQueue
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sbo_verified',
            'sbo_id' => $this->sbo->id,
            'sbo_name' => $this->sbo->name,
            'title' => 'SBO Verified',
            'message' => $this->sbo->name . ' is now fully verified.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $validationUrl = ! empty($this->sbo->moc_registration_number)
            ? route('sbo.verify', $this->sbo->moc_registration_number)
            : route('login');

        $message = (new MailMessage())
            ->subject('Welcome as a registered SBO – Loveworld Ministry of Commerce')
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line('Congratulations! **' . $this->sbo->name . '** is now fully verified and welcomed as a bonafide Strategic Business Organization registered with the Ministry of Commerce.')
            ->line('Your documents have been confirmed compliant and your registration fees have been received.')
            ->line('**Application ID:** ' . $this->sbo->application_id);

        if ( ! empty($this->sbo->moc_registration_number)) {
            $message
                ->line('**Ministry of Commerce Registration Number:** ' . $this->sbo->moc_registration_number)
                ->line('Your registration can be validated through the Loveworld Registry platform using your public validation URL.');
        }

        $seal = $this->sbo->seal()->first();

        if ($seal?->existsOnDisk()) {
            $message
                ->line('Your personalized Loveworld Registry digital seal is attached to this email and is also available for download from your profile.')
                ->attach($seal->absolutePath(), [
                    'as' => $seal->downloadName(),
                    'mime' => 'image/png',
                ]);
        }

        return $message
            ->action('Validate Registration', $validationUrl)
            ->line('Thank you for completing your registration.')
            ->salutation('— Loveworld Registry Team');
    }
}
