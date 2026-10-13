<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ComplianceDocumentType;
use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Support\SboComplianceRequirements;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * "Manage Documents" — the profile's Quick Actions card.
 *
 * The card promised "Upload validation certificates" and linked to /dashboard,
 * which is the profile it sits on. Compliance documents were only reachable
 * from inside the application flow, so an owner whose certificate expired — or
 * who was asked for one after approval — had no way back to them.
 *
 * Nothing new is modelled: this reads the same `SboComplianceRequirements` the
 * application's document step uses, and uploads through the same endpoint
 * (`sbo.documents.store`), so a file added here is the same file the reviewer
 * sees there.
 */
final class AccountDocumentsController extends Controller
{
    public function __invoke(Request $request): InertiaResponse
    {
        $user = $request->user();
        $sbos = $user->sbos()->orderBy('name')->get();

        /*
         * `?? $sbos->first()` matters: an id that is not this owner's resolves
         * to null, and without the fallback the page would render empty rather
         * than showing them their own listing.
         */
        $selected = ($request->filled('sbo')
            ? $sbos->firstWhere('id', (int) $request->query('sbo'))
            : null) ?? $sbos->first();

        return Inertia::render('account/DocumentsView', [
            'sbos' => $sbos->map(fn (Sbo $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                // So the picker can show which listing still owes something.
                'outstanding' => $this->outstandingCount($s),
            ])->values(),
            'selected' => $selected ? [
                'id' => $selected->id,
                'name' => $selected->name,
                'documentsStatus' => $selected->documents_status,
                'verificationStatus' => $selected->verification_status,
            ] : null,
            'documents' => $selected ? $this->documents($selected) : [],
            'uploadUrl' => route('sbo.documents.store'),
        ]);
    }

    /**
     * Every document this SBO has to supply, with whatever it has submitted.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documents(Sbo $sbo): array
    {
        $submitted = $sbo->complianceDocuments()->get()->keyBy('compliance_document_type_id');

        return SboComplianceRequirements::activeFor($sbo)
            ->map(function (ComplianceDocumentType $type) use ($submitted): array {
                /** @var SboComplianceDocument|null $document */
                $document = $submitted->get($type->id);

                return [
                    'typeId' => $type->id,
                    'name' => $type->name,
                    'description' => $type->description,
                    'required' => (bool) $type->is_required,
                    // null = nothing uploaded yet; otherwise pending/approved/rejected.
                    'status' => $document?->status,
                    'rejectionReason' => $document?->rejection_reason,
                    'fileName' => $document?->file()?->file_name,
                    'uploadedAt' => $document?->updated_at?->format('M j, Y'),
                    'downloadUrl' => $document && $document->file()
                        ? route('compliance-documents.download', $document)
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /** Required documents this SBO has not had approved yet. */
    private function outstandingCount(Sbo $sbo): int
    {
        $submitted = $sbo->complianceDocuments()->get()->keyBy('compliance_document_type_id');

        return SboComplianceRequirements::activeFor($sbo)
            ->filter(fn (ComplianceDocumentType $type): bool => (bool) $type->is_required)
            ->filter(fn (ComplianceDocumentType $type): bool => 'approved' !== $submitted->get($type->id)?->status)
            ->count();
    }
}
