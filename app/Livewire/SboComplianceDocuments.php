<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\ActivityLog;
use App\Models\ComplianceDocumentType;
use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Notifications\ComplianceDocumentSubmittedNotification;
use App\Notifications\SboDocumentsCompliantNotification;
use App\Support\AdminRecipients;
use App\Support\SboComplianceRequirements;
use App\Support\SboVerificationFinalizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * @property-read Sbo $sbo
 */
final class SboComplianceDocuments extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $sboId;

    /**
     * Pending uploads keyed by document-type id.
     *
     * @var array<int, TemporaryUploadedFile|null>
     */
    public array $uploads = [];

    public function mount(int $sboId): void
    {
        $this->sboId = $sboId;
    }

    public function getSboProperty(): Sbo
    {
        return Sbo::findOrFail($this->sboId);
    }

    /**
     * Persist every selected required document when the parent workflow asks
     * the applicant to continue from the documents step.
     */
    #[On('submit-compliance-documents')]
    public function submit(?int $sboId = null): void
    {
        if (null !== $sboId && $sboId !== $this->sboId) {
            return;
        }

        $sbo = $this->sbo;
        abort_unless($sbo->created_by === Auth::id(), 403);

        $this->resetValidation();

        $requiredTypes = SboComplianceRequirements::requiredFor($sbo);
        $submissions = $sbo->complianceDocuments()
            ->get()
            ->keyBy('compliance_document_type_id');

        $requiredTypeIds = $requiredTypes->pluck('id')->map(fn (int|string $id): int => (int) $id);

        foreach (array_keys($this->uploads) as $typeId) {
            if ( ! $requiredTypeIds->contains((int) $typeId)) {
                unset($this->uploads[$typeId]);
            }
        }

        $pendingTypeIds = $requiredTypes
            ->filter(fn (ComplianceDocumentType $type): bool => $this->hasPendingUpload($type->id))
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->values();

        $missingTypes = $requiredTypes->filter(function (ComplianceDocumentType $type) use ($submissions, $pendingTypeIds): bool {
            $document = $submissions->get($type->id);

            return ! $document?->isApproved() && ! $pendingTypeIds->contains($type->id);
        });

        if ($missingTypes->isNotEmpty()) {
            foreach ($missingTypes as $type) {
                $this->addError(
                    "uploads.{$type->id}",
                    'Select this required document before continuing.',
                );
            }

            $this->dispatch(
                'ui-toast',
                type: 'error',
                message: 'Select every required document before continuing.',
                duration: 7500,
            );

            return;
        }

        foreach ($pendingTypeIds as $typeId) {
            try {
                $this->persistUpload($sbo, $typeId);
            } catch (ValidationException) {
                return;
            }
        }

        $becameCompliant = false;

        try {
            $becameCompliant = $this->markCompliantIfComplete($sbo->fresh());
        } catch (Throwable $exception) {
            report($exception);
        }

        if ( ! $becameCompliant) {
            $this->dispatch(
                'ui-toast',
                type: 'error',
                message: 'We could not complete the required documents. Please review the fields and try again.',
                duration: 8000,
            );

            return;
        }

        $this->dispatch('document-uploaded');
        $this->dispatch('compliance-documents-saved', sboId: $this->sboId);
        $this->dispatch(
            'ui-toast',
            type: 'success',
            message: 'Required documents submitted successfully. Moving to the next step.',
            duration: 5600,
        );
    }

    public function render()
    {
        $sbo = $this->sbo;

        $submissions = $sbo->complianceDocuments()
            ->with('media')
            ->get()
            ->keyBy('compliance_document_type_id');

        $rows = SboComplianceRequirements::requiredFor($sbo)
            ->map(fn (ComplianceDocumentType $type): array => [
                'type' => $type,
                'document' => $submissions->get($type->id),
            ]);

        return view('livewire.sbo-compliance-documents', [
            'rows' => $rows,
        ]);
    }

    private function persistUpload(Sbo $sbo, int $typeId): ?ComplianceDocumentType
    {
        abort_unless($sbo->created_by === Auth::id(), 403);

        // Open once a payment has been made — settled, or submitted and awaiting
        // an admin's confirmation. The admin reviews once every step is done.
        abort_unless(
            $sbo->feesPaid(),
            403,
            'Make your registration fee payment before uploading compliance documents.',
        );

        $type = ComplianceDocumentType::where('is_active', true)->findOrFail($typeId);
        abort_unless(SboComplianceRequirements::appliesTo($sbo, $type), 403);
        abort_unless(
            SboComplianceRequirements::requiredFor($sbo)->contains('id', $type->id),
            403,
        );

        try {
            $this->validate([
                "uploads.{$typeId}" => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            ], [], [
                "uploads.{$typeId}" => 'document',
            ]);
        } catch (ValidationException $exception) {
            $this->dispatch(
                'ui-toast',
                type: 'error',
                message: $exception->validator->errors()->first("uploads.{$typeId}") ?: 'Please choose a valid document and try again.',
                duration: 7500,
            );

            throw $exception;
        }

        $file = $this->pendingUpload($typeId);

        if ( ! $file instanceof TemporaryUploadedFile || ! $file->isValid() || ! is_file((string) $file->getRealPath())) {
            $message = 'We could not read that file. Please choose it again and retry the upload.';

            $this->addError("uploads.{$typeId}", $message);
            $this->dispatch('ui-toast', type: 'error', message: $message, duration: 7500);

            return null;
        }

        // Uploaded documents are accepted immediately (auto-approved) so the
        // owner moves toward full validation without waiting on manual review.
        // Admins retain the ability to revoke compliance/verification later.
        $document = SboComplianceDocument::firstOrNew([
            'sbo_id' => $sbo->id,
            'compliance_document_type_id' => $type->id,
        ]);
        $wasExisting = $document->exists;
        $originalAttributes = $wasExisting ? $document->getAttributes() : [];

        $document->fill([
            'status' => 'approved',
            'rejection_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => now(),
        ]);
        $document->save();

        try {
            $document->addMedia((string) $file->getRealPath())
                ->usingFileName(basename($file->getClientOriginalName()))
                ->toMediaCollection(SboComplianceDocument::COLLECTION);
        } catch (Throwable $exception) {
            report($exception);

            if ($wasExisting) {
                $document->forceFill($originalAttributes)->saveQuietly();
            } else {
                $document->delete();
            }

            $message = 'We could not save this document. Please try again. If the problem continues, contact support.';

            $this->addError("uploads.{$typeId}", $message);
            $this->dispatch('ui-toast', type: 'error', message: $message, duration: 8000);

            return null;
        }

        // Keep admins informed of the upload (for oversight / possible revoke).
        try {
            AdminRecipients::notify(new ComplianceDocumentSubmittedNotification($document->fresh('media')));
        } catch (Throwable $exception) {
            // An admin notification failure must not make a successfully stored
            // document look like an upload failure to the applicant.
            report($exception);
        }

        unset($this->uploads[$typeId]);

        return $type;
    }

    private function hasPendingUpload(int $typeId): bool
    {
        return $this->pendingUpload($typeId) instanceof TemporaryUploadedFile;
    }

    private function pendingUpload(int $typeId): ?TemporaryUploadedFile
    {
        $file = $this->uploads[$typeId] ?? $this->uploads[(string) $typeId] ?? null;

        return $file instanceof TemporaryUploadedFile ? $file : null;
    }

    /**
     * When every required document type now has a submission, mark the SBO
     * documents-compliant and run the verification finalizer. Returns whether
     * the SBO transitioned into (or already held) compliant status.
     */
    private function markCompliantIfComplete(Sbo $sbo): bool
    {
        $requiredTypeIds = SboComplianceRequirements::requiredFor($sbo)->pluck('id');

        // No configured requirements → nothing to auto-complete here; leave the
        // existing admin manual-flag flow untouched.
        if ($requiredTypeIds->isEmpty()) {
            return false;
        }

        $approvedTypeIds = $sbo->complianceDocuments()
            ->where('status', 'approved')
            ->pluck('compliance_document_type_id');

        $allPresent = $requiredTypeIds->every(fn (int $id): bool => $approvedTypeIds->contains($id));

        if ( ! $allPresent) {
            return false;
        }

        if ('compliant' !== $sbo->documents_status || null === $sbo->documents_compliant_at) {
            $sbo->update([
                'documents_status' => 'compliant',
                'documents_compliant_at' => now(),
            ]);
            $sbo->refresh();

            ActivityLog::create([
                'type' => 'sbo_documents_compliant',
                'title' => $sbo->name,
                'description' => 'Compliance documents for <strong>' . e($sbo->name) . '</strong> were uploaded and auto-accepted.',
                'status' => 'approved',
                'icon' => Str::upper(Str::substr($sbo->name, 0, 2)),
                'user_id' => Auth::id(),
            ]);

            $sbo->creator?->notify(new SboDocumentsCompliantNotification($sbo));
        }

        app(SboVerificationFinalizer::class)->finalizeIfReady($sbo);

        return true;
    }
}
