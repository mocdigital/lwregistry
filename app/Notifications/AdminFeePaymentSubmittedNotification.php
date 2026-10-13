<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sbo;
use App\Support\SboFeeCalculator;
use App\Support\SboFeeStructures;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails admins when an SBO submits a registration-fee payment by gateway or
 * manual proof. Does not confirm payment — an admin still reviews and marks it
 * paid.
 */
final class AdminFeePaymentSubmittedNotification extends Notification implements ShouldQueue
{
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
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $currency = SboFeeCalculator::currency($this->sbo->country_id);
        $ownerName = optional($this->sbo->creator)->name
            ?? mb_trim($this->sbo->owner_first_name . ' ' . $this->sbo->owner_surname)
            ?: 'Unknown';

        $structureLabel = SboFeeStructures::label((string) $this->sbo->share_structure)
            ?? ($this->sbo->share_structure ?? '—');

        $mail = (new MailMessage())
            ->subject('SBO registration fee payment submitted – Loveworld Registry')
            ->greeting('Hello Admin,')
            ->line('An SBO has submitted registration-fee payment details, pending your review.')
            ->line('**SBO:** ' . $this->sbo->name)
            ->line('**Application ID:** ' . ($this->sbo->application_id ?? '—'))
            ->line('**Owner:** ' . $ownerName)
            ->line('**Share structure:** ' . $structureLabel);

        // Share-capital breakdown only applies to share-bearing structures.
        if (SboFeeCalculator::isShareStructure((string) $this->sbo->share_structure)) {
            $equity = SboFeeCalculator::equity(
                (int) $this->sbo->issued_shares_volume,
                (float) $this->sbo->per_share_value,
            );

            $mail->line('**Share capital type:** ' . ($this->sbo->share_capital_type ?? '—'))
                ->line('**Issued shares:** ' . number_format((int) $this->sbo->issued_shares_volume))
                ->line('**Per-share value:** ' . number_format((float) $this->sbo->per_share_value, 2))
                ->line('**Estimated equity size:** ' . number_format($equity, 2));
        }

        return $mail
            ->line('**Fee paid:** ' . number_format((int) $this->sbo->calculated_fee) . ' ' . $currency)
            ->line('**Payment reference:** ' . ($this->sbo->payment_reference ?: '—'))
            ->action('Review Payment', route('admin.sbos.show', $this->sbo))
            ->salutation('— Loveworld Registry System');
    }
}
