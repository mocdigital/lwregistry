<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\ComplianceDocumentType;
use App\Models\Sbo;
use App\Models\User;
use App\Notifications\SboDocumentsCompliantNotification;
use Illuminate\Support\Str;

final class SboValidationAdvancer
{
    public function confirmFeesPaid(Sbo $sbo, User $admin): ?Sbo
    {
        return app(SboPaymentConfirmer::class)->confirm(
            $sbo,
            $admin,
            'sbo_fees_paid_manual_override',
            'Registration fees manually confirmed for <strong>' . e($sbo->name) . '</strong> by <strong>' . e($admin->name ?? 'admin') . '</strong>.'
        );
    }

    public function confirmDocumentsCompliant(Sbo $sbo, User $admin): ?Sbo
    {
        if (
            $sbo->documentsCompliant()
            && 'compliant' === $sbo->documents_status
            && null !== $sbo->documents_compliant_at
        ) {
            return null;
        }

        $this->approveExistingDocumentRows($sbo, $admin);
        $this->approveMissingRequiredDocumentRows($sbo, $admin);

        $sbo->update([
            'documents_status' => 'compliant',
            'documents_compliant_at' => now(),
        ]);

        $sbo->refresh();

        $this->log(
            $sbo,
            $admin,
            'sbo_documents_compliant_manual_override',
            'Document compliance manually confirmed for <strong>' . e($sbo->name) . '</strong> by <strong>' . e($admin->name ?? 'admin') . '</strong>.'
        );

        $sbo->creator?->notify(new SboDocumentsCompliantNotification($sbo));
        app(SboVerificationFinalizer::class)->finalizeIfReady($sbo, $admin);

        return $sbo->fresh();
    }

    public function issueFinalVerification(Sbo $sbo, User $admin): ?Sbo
    {
        $sbo->refresh();

        if ( ! $sbo->isFullyVerified()) {
            return null;
        }

        $sbo->loadMissing('seal');

        if (
            null !== $sbo->verified_notified_at
            && ! empty($sbo->moc_registration_number)
            && null !== $sbo->seal
            && $sbo->seal->existsOnDisk()
        ) {
            return null;
        }

        app(SboVerificationFinalizer::class)->finalizeIfReady($sbo, $admin);

        return $sbo->fresh('seal');
    }

    private function approveExistingDocumentRows(Sbo $sbo, User $admin): void
    {
        $sbo->complianceDocuments()
            ->whereIn('status', ['pending', 'rejected'])
            ->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);
    }

    private function approveMissingRequiredDocumentRows(Sbo $sbo, User $admin): void
    {
        SboComplianceRequirements::requiredFor($sbo)
            ->each(function (ComplianceDocumentType $type) use ($sbo, $admin): void {
                $sbo->complianceDocuments()->updateOrCreate(
                    ['compliance_document_type_id' => $type->id],
                    [
                        'status' => 'approved',
                        'reviewed_by' => $admin->id,
                        'reviewed_at' => now(),
                        'rejection_reason' => null,
                    ]
                );
            });
    }

    private function log(Sbo $sbo, User $admin, string $type, string $description): void
    {
        ActivityLog::create([
            'type' => $type,
            'title' => $sbo->name,
            'description' => $description,
            'status' => 'approved',
            'icon' => Str::upper(Str::substr($sbo->name, 0, 2)),
            'user_id' => $admin->id,
        ]);
    }
}
