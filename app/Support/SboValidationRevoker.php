<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;

final class SboValidationRevoker
{
    public function revokeFeesPaid(Sbo $sbo, User $admin): ?Sbo
    {
        if ( ! $sbo->feesPaid()) {
            return null;
        }

        $sbo->update([
            'payment_status' => 'unpaid',
            'payment_confirmed_at' => null,
            'payment_submitted_at' => null,
        ]);

        $this->revokeVerifiedState($sbo);
        $sbo->refresh();

        $this->log(
            $sbo,
            $admin,
            'sbo_fees_revoked',
            'Paid status revoked for <strong>' . e($sbo->name) . '</strong> by <strong>' . e($admin->name ?? 'admin') . '</strong> - returned to the gated (unpaid) stage.'
        );

        return $sbo;
    }

    public function revokeDocumentsCompliant(Sbo $sbo, User $admin): ?Sbo
    {
        if ( ! $sbo->documentsCompliant() && 'compliant' !== $sbo->documents_status) {
            return null;
        }

        $sbo->complianceDocuments()
            ->where('status', 'approved')
            ->update([
                'status' => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
            ]);

        $sbo->update([
            'documents_status' => 'pending',
            'documents_compliant_at' => null,
        ]);

        $this->revokeVerifiedState($sbo);
        $sbo->refresh();

        $this->log(
            $sbo,
            $admin,
            'sbo_documents_revoked',
            'Document compliance revoked for <strong>' . e($sbo->name) . '</strong> by <strong>' . e($admin->name ?? 'admin') . '</strong> - approved document submissions returned to pending review.'
        );

        return $sbo;
    }

    public function revokeVerified(Sbo $sbo, User $admin): ?Sbo
    {
        $sbo->loadMissing('seal');

        if (
            ! $sbo->isFullyVerified()
            || (
                null === $sbo->verified_notified_at
                && empty($sbo->moc_registration_number)
                && null === $sbo->seal
            )
        ) {
            return null;
        }

        $sbo->update([
            'verification_status' => 'pending',
            'approved_by' => null,
            'date_approved' => null,
        ]);

        $this->revokeVerifiedState($sbo);
        $sbo->refresh();

        $this->log(
            $sbo,
            $admin,
            'sbo_verification_revoked',
            'Verification revoked for <strong>' . e($sbo->name) . '</strong> by <strong>' . e($admin->name ?? 'admin') . '</strong> - registry approval returned to pending.'
        );

        return $sbo;
    }

    private function revokeVerifiedState(Sbo $sbo): void
    {
        $sbo->loadMissing('seal');

        if ($seal = $sbo->seal) {
            $seal->deleteFile();
            $seal->delete();
        }

        $sbo->forceFill([
            'verified_notified_at' => null,
            'moc_registration_number' => null,
        ])->save();
    }

    private function log(Sbo $sbo, User $admin, string $type, string $description): void
    {
        ActivityLog::create([
            'type' => $type,
            'title' => $sbo->name,
            'description' => $description,
            'status' => 'declined',
            'icon' => Str::upper(Str::substr($sbo->name, 0, 2)),
            'user_id' => $admin->id,
        ]);
    }
}
