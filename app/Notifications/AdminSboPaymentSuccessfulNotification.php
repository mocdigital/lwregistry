<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use App\Support\SboFeeCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails super admins after an SBO registration-fee payment is confirmed.
 */
final class AdminSboPaymentSuccessfulNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Sbo $sbo,
        protected ?int $confirmedAmount = null,
        protected string $paymentMethod = 'Manual confirmation',
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

        return (new MailMessage())
            ->subject('SBO payment successful – Loveworld Registry')
            ->greeting('Hello Super Admin,')
            ->line('An SBO registration-fee payment has been successfully confirmed on the platform.')
            ->line('**SBO:** ' . $this->sbo->name)
            ->line('**Application ID:** ' . ($this->sbo->application_id ?? '—'))
            ->line('**Owner:** ' . $ownerName)
            ->line('**Payment method:** ' . $this->paymentMethod)
            ->line('**Amount:** ' . number_format($this->confirmedAmount ?? (int) $this->sbo->calculated_fee) . ' ' . $currency)
            ->line('**Payment reference:** ' . ($this->sbo->payment_reference ?? '—'))
            ->line('**Confirmed:** ' . ($this->sbo->payment_confirmed_at?->format('d M Y, H:i') ?? '—'))
            ->action('Review SBO', route('admin.sbos.show', $this->sbo))
            ->salutation('— Loveworld Registry System');
    }
}
