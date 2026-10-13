<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Sbo;
use App\Models\User;
use App\Notifications\AdminSboPaymentSuccessfulNotification;
use App\Notifications\SboFeesPaidNotification;
use App\Notifications\SboUploadDocumentsNotification;
use Illuminate\Support\Str;
use Throwable;

final class SboPaymentConfirmer
{
    public function __construct(
        private readonly SboVerificationFinalizer $finalizer = new SboVerificationFinalizer(),
    ) {}

    public function confirm(
        Sbo $sbo,
        ?User $reviewer,
        string $activityType,
        string $description,
        ?int $confirmedAmount = null,
    ): ?Sbo {
        if ($sbo->feesPaid()) {
            return null;
        }

        $sbo->update([
            'payment_status' => 'paid',
            'payment_confirmed_at' => now(),
        ]);

        $sbo->refresh();

        /*
         * Paying earns the seal. It is provisional until approval and document
         * compliance land — SboSealIssuer decides which — but the member has
         * something real to download from this moment rather than waiting out
         * the whole review with nothing.
         *
         * Never allowed to fail the payment: the fee IS confirmed, and a seal
         * that could not be drawn (missing GD, unwritable disk) is issued on
         * the next visit by DashboardController.
         */
        try {
            app(SboSealIssuer::class)->issueFor($sbo);
        } catch (Throwable $exception) {
            report($exception);
        }

        ActivityLog::create([
            'type' => $activityType,
            'title' => $sbo->name,
            'description' => $description,
            'status' => 'approved',
            'icon' => Str::upper(Str::substr($sbo->name, 0, 2)),
            'user_id' => $reviewer?->id,
        ]);

        $creator = $sbo->creator;

        if ($creator instanceof User) {
            $creator->notify(new SboFeesPaidNotification($sbo, $confirmedAmount));
        }

        $paymentMethod = str_contains(mb_strtolower($activityType), 'espees')
            ? 'Espees'
            : 'Manual confirmation';

        AdminRecipients::notifySuperAdmins(new AdminSboPaymentSuccessfulNotification(
            $sbo->fresh() ?? $sbo,
            $confirmedAmount,
            $paymentMethod,
        ));

        // Prompt the owner to upload their country-specific compliance documents,
        // unless they are already compliant (nothing left to ask for).
        if ( ! $sbo->documentsCompliant()) {
            if ($creator instanceof User) {
                $creator->notify(new SboUploadDocumentsNotification($sbo));
            }
        }

        $this->finalizer->finalizeIfReady($sbo, $reviewer);

        return $sbo->fresh();
    }
}
