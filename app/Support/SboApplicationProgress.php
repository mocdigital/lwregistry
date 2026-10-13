<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;

final class SboApplicationProgress
{
    /**
     * @return array{
     *     steps: array<int, array{key: string, label: string, done: bool, status: string, description: string}>,
     *     current_key: string,
     *     percent: int,
     *     badge: array{class: string, label: string},
     *     outstanding: array<int, string>,
     *     required_documents_count: int,
     *     approved_required_documents_count: int,
     *     pending_required_documents_count: int,
     *     rejected_required_documents_count: int
     * }
     */
    public static function for(Sbo $sbo): array
    {
        $requiredTypeIds = SboComplianceRequirements::requiredFor($sbo)->pluck('id');

        /*
         * Approved, awaiting review, and rejected — counted separately.
         *
         * Only the approved count existed, so "0 of 1 required documents
         * approved" was the whole story an admin got: identical whether the
         * applicant had uploaded nothing or had uploaded a document that was
         * sitting in the queue waiting for that admin to look at it. Nothing in
         * the app could say "someone is waiting on you".
         */
        $countByStatus = static fn (string $status): int => $requiredTypeIds->isEmpty()
            ? 0
            : $sbo->complianceDocuments()
                ->whereIn('compliance_document_type_id', $requiredTypeIds)
                ->where('status', $status)
                ->count();

        $approvedRequiredCount = $countByStatus('approved');
        $pendingRequiredCount = $countByStatus('pending');
        $rejectedRequiredCount = $countByStatus('rejected');

        $isDeclined = $sbo->isDeclined();
        $isInfoRequested = $sbo->isInfoRequested();
        $isApproved = $sbo->isApproved();
        $documentsCompliant = $sbo->documentsCompliant();
        $feesPaid = $sbo->feesPaid();
        $verified = $sbo->isFullyVerified();

        $steps = [
            [
                'key' => 'submitted',
                'label' => __('progress.step.submitted'),
                'done' => true,
                'status' => 'complete',
                'description' => __('progress.step.submitted_description'),
            ],
            [
                'key' => 'fees',
                'label' => __('progress.step.fees'),
                'done' => $feesPaid,
                'status' => $feesPaid ? 'complete' : ($isDeclined ? 'blocked' : 'pending'),
                'description' => $feesPaid
                    ? __('progress.step.fees_done')
                    : __('progress.step.fees_todo'),
            ],
            [
                'key' => 'documents',
                'label' => __('progress.step.documents'),
                'done' => $documentsCompliant,
                'status' => $documentsCompliant ? 'complete' : ($isDeclined ? 'blocked' : 'pending'),
                /*
                 * Reads truthfully for both audiences, which matters because
                 * this same line is rendered on the admin screen: telling a
                 * reviewer to "upload the required documents" described the
                 * wrong person's job, and hid the fact that a document was
                 * already waiting for them.
                 */
                'description' => match (true) {
                    $documentsCompliant => __('progress.step.documents_done'),
                    // trans_choice, not string concatenation: French and Spanish
                    // inflect the verb as well as the noun, so the sentence has
                    // to be translated whole rather than assembled from parts.
                    $pendingRequiredCount > 0 => trans_choice('progress.step.documents_pending', $pendingRequiredCount, ['count' => $pendingRequiredCount]),
                    $rejectedRequiredCount > 0 => trans_choice('progress.step.documents_rejected', $rejectedRequiredCount, ['count' => $rejectedRequiredCount]),
                    default => __('progress.step.documents_todo'),
                },
            ],
            [
                'key' => 'verified',
                'label' => __('progress.step.verified'),
                'done' => $verified,
                'status' => $verified ? 'complete' : ($isDeclined ? 'blocked' : 'pending'),
                'description' => $verified
                    ? __('progress.step.verified_done')
                    : __('progress.step.verified_todo'),
            ],
        ];

        $completed = collect($steps)->where('done', true)->count();
        $percent = (int) round(($completed / count($steps)) * 100);

        $firstIncomplete = collect($steps)->firstWhere('done', false);
        $currentKey = is_array($firstIncomplete) ? $firstIncomplete['key'] : 'verified';

        if ($isDeclined || $isInfoRequested) {
            $currentKey = 'submitted';
        } elseif ( ! $feesPaid) {
            $currentKey = 'fees';
        } elseif ( ! $documentsCompliant) {
            $currentKey = 'documents';
        } elseif ($verified) {
            $currentKey = 'verified';
        } else {
            $currentKey = 'verified';
        }

        return [
            'steps' => $steps,
            'current_key' => $currentKey,
            'percent' => $percent,
            'badge' => self::badgeFor($sbo),
            'outstanding' => self::outstandingFor($sbo, $requiredTypeIds->count()),
            'required_documents_count' => $requiredTypeIds->count(),
            'approved_required_documents_count' => $approvedRequiredCount,
            // Awaiting a reviewer. This is the number that tells an admin
            // there is work in their queue.
            'pending_required_documents_count' => $pendingRequiredCount,
            'rejected_required_documents_count' => $rejectedRequiredCount,
        ];
    }

    /**
     * @return array{class: string, label: string}
     */
    private static function badgeFor(Sbo $sbo): array
    {
        return match (true) {
            $sbo->isFullyVerified() => ['class' => 'bg-success-subtle text-success border-success-subtle', 'label' => __('data.status.verified')],
            $sbo->isDeclined() => ['class' => 'bg-danger-subtle text-danger border-danger-subtle', 'label' => __('data.status.declined')],
            $sbo->isInfoRequested() => ['class' => 'bg-info-subtle text-info border-info-subtle', 'label' => __('data.status.info_requested')],
            $sbo->isApproved() => ['class' => 'bg-primary-subtle text-primary border-primary-subtle', 'label' => __('data.status.approved')],
            default => ['class' => 'bg-warning-subtle text-warning border-warning-subtle', 'label' => __('data.status.pending_review')],
        };
    }

    /**
     * @return array<int, string>
     */
    private static function outstandingFor(Sbo $sbo, int $requiredDocumentCount): array
    {
        if ($sbo->isFullyVerified()) {
            return [__('progress.outstanding.verified')];
        }

        if ($sbo->isDeclined()) {
            return [__('progress.outstanding.declined')];
        }

        $items = [];

        if ($sbo->isInfoRequested()) {
            $items[] = __('progress.outstanding.info_requested');
        }

        if ( ! $sbo->feesPaid()) {
            $items[] = __('progress.outstanding.fees');
        }

        if ( ! $sbo->documentsCompliant()) {
            $items[] = $requiredDocumentCount > 0
                ? __('progress.outstanding.documents')
                : __('progress.outstanding.documents_unconfigured');
        }

        if ( ! $sbo->isApproved() && ! $sbo->isInfoRequested()) {
            $items[] = __('progress.outstanding.approval');
        }

        return $items;
    }
}
