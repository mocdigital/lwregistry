<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\InventoryOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a store customer (by email) once their order has been checked out /
 * marked paid. It confirms the payment and lists the transaction details, with
 * a short thank-you personalised from the customer_name on the order.
 */
final class InventoryPaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected InventoryOrder $order)
    {
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
        $order = $this->order->loadMissing('items');
        $firstName = $this->firstName();

        $mail = (new MailMessage())
            ->subject('Payment Received – ' . config('app.name'))
            ->greeting($firstName ? "Hello {$firstName}," : 'Hello,')
            ->line('Thank you for your purchase — we have received your payment in full.')
            ->line('**Order reference:** ' . $order->reference)
            ->line('**Date:** ' . ($order->paid_at ?? now())->format('d M Y, H:i'));

        // Itemised lines.
        foreach ($order->items as $item) {
            $mail->line(
                '• ' . $item->product_name . ' — '
                . number_format($item->quantity) . ' × ₦' . number_format($item->unit_price)
                . ' = ₦' . number_format($item->line_total)
            );
        }

        return $mail
            ->line('**Total paid:** ₦' . number_format($order->total_amount))
            ->line('**Espees equivalent:** ' . number_format($order->totalInEspees(), 2) . ' Espees')
            ->line($this->thanks($firstName))
            ->salutation('— ' . config('app.name'));
    }

    /**
     * A short, warm thank-you personalised on the customer's first name.
     */
    private function thanks(?string $firstName): string
    {
        return $firstName
            ? "Thank you once again, {$firstName}. We truly appreciate your patronage and look forward to serving you next time."
            : 'Thank you once again. We truly appreciate your patronage and look forward to serving you next time.';
    }

    /**
     * First word of the customer name, if one was captured on the order.
     */
    private function firstName(): ?string
    {
        $name = mb_trim((string) $this->order->customer_name);

        if ('' === $name) {
            return null;
        }

        return explode(' ', $name)[0];
    }
}
