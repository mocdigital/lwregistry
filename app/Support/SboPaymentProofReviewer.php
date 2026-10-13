<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Sbo;
use App\Models\SboPaymentProof;
use App\Models\User;
use Illuminate\Support\Str;

final class SboPaymentProofReviewer
{
    public function approve(SboPaymentProof $proof, User $reviewer): ?Sbo
    {
        $proof->loadMissing(['sbo.creator', 'media']);
        $sbo = $proof->sbo;

        if ( ! $sbo instanceof Sbo || null === $proof->file() || $sbo->feesPaid()) {
            return null;
        }

        $proof->update([
            'status' => 'approved',
            'rejection_reason' => null,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        return app(SboPaymentConfirmer::class)->confirm(
            $sbo,
            $reviewer,
            'sbo_payment_proof_approved',
            'Payment proof approved for <strong>' . e($sbo->name) . '</strong> by <strong>' . e($reviewer->name ?? 'admin') . '</strong>.'
        );
    }

    public function reject(SboPaymentProof $proof, User $reviewer, string $reason): ?SboPaymentProof
    {
        $proof->loadMissing(['sbo', 'media']);
        $sbo = $proof->sbo;

        if ( ! $sbo instanceof Sbo || $sbo->feesPaid()) {
            return null;
        }

        $proof->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        ActivityLog::create([
            'type' => 'sbo_payment_proof_rejected',
            'title' => $sbo->name,
            'description' => 'Payment proof rejected for <strong>' . e($sbo->name) . '</strong> by <strong>' . e($reviewer->name ?? 'admin') . '</strong>.',
            'status' => 'declined',
            'icon' => Str::upper(Str::substr($sbo->name, 0, 2)),
            'user_id' => $reviewer->id,
        ]);

        return $proof->fresh();
    }
}
