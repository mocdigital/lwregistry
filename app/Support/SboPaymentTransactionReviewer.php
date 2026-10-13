<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Sbo;
use App\Models\SboPaymentTransaction;
use App\Models\User;
use Illuminate\Support\Str;

final class SboPaymentTransactionReviewer
{
    public function approve(SboPaymentTransaction $transaction, User $reviewer): ?Sbo
    {
        $transaction->loadMissing(['sbo.creator']);
        $sbo = $transaction->sbo;

        if ( ! $sbo instanceof Sbo || ! $transaction->isPendingReview() || $sbo->feesPaid()) {
            return null;
        }

        $transaction->update([
            'review_status' => 'approved',
            'rejection_reason' => null,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        return app(SboPaymentConfirmer::class)->confirm(
            $sbo,
            $reviewer,
            'sbo_espees_payment_approved',
            'Espees payment approved for <strong>' . e($sbo->name) . '</strong> by <strong>' . e($reviewer->name ?? 'admin') . '</strong>.'
        );
    }

    public function reject(SboPaymentTransaction $transaction, User $reviewer, string $reason): ?SboPaymentTransaction
    {
        $transaction->loadMissing(['sbo']);
        $sbo = $transaction->sbo;

        if ( ! $sbo instanceof Sbo || $sbo->feesPaid() || $transaction->isApproved()) {
            return null;
        }

        $transaction->update([
            'review_status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        ActivityLog::create([
            'type' => 'sbo_espees_payment_rejected',
            'title' => $sbo->name,
            'description' => 'Espees payment rejected for <strong>' . e($sbo->name) . '</strong> by <strong>' . e($reviewer->name ?? 'admin') . '</strong>.',
            'status' => 'declined',
            'icon' => Str::upper(Str::substr($sbo->name, 0, 2)),
            'user_id' => $reviewer->id,
        ]);

        return $transaction->fresh();
    }
}
