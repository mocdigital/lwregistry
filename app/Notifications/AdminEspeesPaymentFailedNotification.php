<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use App\Models\SboPaymentTransaction;
use App\Support\SboFeeCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails admins whenever an Espees registration-fee payment fails — whether the
 * gateway declined it (e.g. insufficient wallet balance), verification could not
 * be completed, or checkout could not be started.
 */
final class AdminEspeesPaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Sbo $sbo,
        protected string $kind,
        protected string $reason,
        protected ?SboPaymentTransaction $transaction = null,
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
        $currency = SboFeeCalculator::currency($this->sbo->country_id);
        $ownerName = optional($this->sbo->creator)->name
            ?? mb_trim($this->sbo->owner_first_name . ' ' . $this->sbo->owner_surname)
            ?: 'Unknown';

        $mail = (new MailMessage())
            ->error()
            ->subject('SBO Espees payment failed – Loveworld Registry')
            ->greeting('Hello Admin,')
            ->line('An Espees registration-fee payment did not complete.')
            ->line('**SBO:** ' . $this->sbo->name)
            ->line('**Application ID:** ' . ($this->sbo->application_id ?? '—'))
            ->line('**Owner:** ' . $ownerName)
            ->line('**Failure type:** ' . $this->kind)
            ->line('**Reason:** ' . $this->reason)
            ->line('**Amount:** ' . number_format((int) (null !== $this->transaction ? $this->transaction->amount : $this->sbo->calculated_fee)) . ' ' . $currency);

        if (null !== $this->transaction) {
            $mail->line('**Local reference:** ' . ($this->transaction->local_reference ?: '—'))
                ->line('**Provider reference:** ' . ($this->transaction->provider_reference ?: '—'));
        }

        return $mail
            ->action('Review SBO', route('admin.sbos.show', $this->sbo))
            ->salutation('— Loveworld Registry System');
    }
}
