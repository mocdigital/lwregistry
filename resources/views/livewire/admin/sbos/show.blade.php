<?php

use App\Models\ActivityLog;
use App\Models\ComplianceDocumentType;
use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Models\SboPaymentProof;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\User;
use App\Notifications\ComplianceDocumentApprovedNotification;
use App\Notifications\ComplianceDocumentRejectedNotification;
use App\Notifications\SboApprovedNotification;
use App\Notifications\SboDeclinedNotification;
use App\Notifications\SboDocumentsCompliantNotification;
use App\Support\SboApplicationProgress;
use App\Support\SboComplianceRequirements;
use App\Support\SboManagerAssigner;
use App\Support\SboPaymentProofReviewer;
use App\Support\SboValidationAdvancer;
use App\Support\SboValidationRevoker;
use App\Support\SboVerificationFinalizer;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Organization Details – Admin Dashboard',
        'dataPage' => 'sbos',
        'pageTitle' => 'Organization Details',
    ]
)] class extends Component {

    public Sbo $sbo;

    public bool $editing = false;
    public int $totalRfqs = 0;
    public int $activeRfqs = 0;
    public int $completedRfqs = 0;
    public int $awardedRfqs = 0;
    public int $businessesEngaged = 0;
    public int $totalBids = 0;
    public int $uniqueBusinessesEngaged = 0;

    // form fields
    public string $name = '';
    public ?string $entity_type = null;
    public ?string $rc_number = null;
    public ?string $tax_number = null;
    public ?string $business_type = null;
    public ?string $category = null;
    public ?string $region = null;
    public ?string $established_at = null;
    public ?string $website = null;

    public string $primary_contact_name = '';
    public string $primary_contact_email = '';
    public ?string $phone = null;
    public ?string $kingschat_handle = null;

    public ?int $staff_count = null;
    public ?string $business_model = null;
    public ?string $description = null;

    public array $directors = [];

    /** Active edit-modal tab, persisted across re-renders. */
    public string $editTab = 'identity';

    // Approval workflow fields
    public string $mocNumber = '';
    public string $declineReason = '';
    public string $infoRequestMessage = '';

    // Manager reassignment
    public ?int $newManagerId = null;

    public function assignManager(): void
    {
        abort_unless(auth()->user()->hasPermission(\App\Support\AdminCapabilities::ASSIGN_SBO_MANAGERS), 403);

        $this->validate([
            'newManagerId' => 'required|exists:users,id',
        ], [
            'newManagerId.required' => 'Please select a user to assign as manager.',
        ]);

        $newManager = User::findOrFail($this->newManagerId);

        SboManagerAssigner::assign($this->sbo, $newManager, auth()->user());

        $this->sbo->refresh();
        $this->newManagerId = null;

        $this->dispatch('close-manager-modal');
        session()->flash('success', 'SBO manager updated successfully.');
    }

    public function approve(): void
    {
        abort_unless(auth()->user()->hasPermission(\App\Support\AdminCapabilities::APPROVE_SBOS), 403);

        $this->validate([
            'mocNumber' => 'nullable|string|max:255',
        ]);

        $this->sbo->update([
            'verification_status' => 'approved',
            'status' => 'active',
            'is_active' => true,
            'approved_by' => auth()->id(),
            'date_approved' => now(),
            'moc_registration_number' => $this->mocNumber ?: $this->sbo->moc_registration_number,
        ]);

        $this->sbo->refresh();

        if ($this->sbo->creator) {
            $this->sbo->creator->notify(new SboApprovedNotification($this->sbo));
        }

        ActivityLog::create([
            'type' => 'sbo_approved',
            'title' => $this->sbo->name,
            'description' => 'SBO approved by <strong>' . (auth()->user()->name ?? 'admin') . '</strong>.',
            'status' => 'approved',
            'icon' => \Str::upper(\Str::substr($this->sbo->name, 0, 2)),
            'user_id' => auth()->id(),
        ]);

        $this->notifyIfFullyVerified();

        $this->dispatch('close-decision-modal');
        $this->dispatch('sbo-updated');
        session()->flash('success', 'SBO approved successfully.');
    }

    public function markDocumentsCompliant(): void
    {
        abort_unless(auth()->user()->hasPermission(\App\Support\AdminCapabilities::APPROVE_SBOS), 403);

        $confirmed = app(SboValidationAdvancer::class)->confirmDocumentsCompliant($this->sbo, auth()->user());

        if (null === $confirmed) {
            return;
        }

        $this->sbo = $confirmed->fresh();

        session()->flash('success', 'Documents marked compliant.');
    }

    /** Reason captured in the reject modal, keyed by document id. */
    public array $rejectReason = [];
    public string $paymentProofRejectReason = '';

    public function approveComplianceDocument(int $documentId): void
    {
        abort_unless(auth()->user()->hasPermission(\App\Support\AdminCapabilities::APPROVE_SBOS), 403);

        $document = $this->complianceDocumentForSbo($documentId);

        $document->update([
            'status' => 'approved',
            'rejection_reason' => null,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        ActivityLog::create([
            'type' => 'compliance_document_approved',
            'title' => $this->sbo->name,
            'description' => 'Approved <strong>' . e($document->type->name) . '</strong> for <strong>' . e($this->sbo->name) . '</strong>.',
            'status' => 'approved',
            'icon' => \Str::upper(\Str::substr($this->sbo->name, 0, 2)),
            'user_id' => auth()->id(),
        ]);

        $this->sbo->creator?->notify(new ComplianceDocumentApprovedNotification($document));

        $this->syncDocumentsComplianceState();
        $this->sbo->refresh();

        session()->flash('success', $document->type->name . ' approved.');
    }

    public function rejectComplianceDocument(int $documentId): void
    {
        abort_unless(auth()->user()->hasPermission(\App\Support\AdminCapabilities::APPROVE_SBOS), 403);

        $reason = trim((string) ($this->rejectReason[$documentId] ?? ''));
        if ($reason === '') {
            $this->addError('rejectReason.' . $documentId, 'Please provide a reason for rejection.');

            return;
        }

        $document = $this->complianceDocumentForSbo($documentId);

        $document->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        ActivityLog::create([
            'type' => 'compliance_document_rejected',
            'title' => $this->sbo->name,
            'description' => 'Rejected <strong>' . e($document->type->name) . '</strong> for <strong>' . e($this->sbo->name) . '</strong>.',
            'status' => 'declined',
            'icon' => \Str::upper(\Str::substr($this->sbo->name, 0, 2)),
            'user_id' => auth()->id(),
        ]);

        $this->sbo->creator?->notify(new ComplianceDocumentRejectedNotification($document));

        unset($this->rejectReason[$documentId]);
        $this->syncDocumentsComplianceState();
        $this->sbo->refresh();

        session()->flash('success', $document->type->name . ' rejected.');
    }

    /** Resolve a document and guard that it belongs to this SBO. */
    private function complianceDocumentForSbo(int $documentId): SboComplianceDocument
    {
        return SboComplianceDocument::where('id', $documentId)
            ->where('sbo_id', $this->sbo->id)
            ->firstOrFail();
    }

    public function approvePaymentProof(int $proofId): void
    {
        abort_unless(auth()->user()->hasPermission(\App\Support\AdminCapabilities::APPROVE_SBOS), 403);

        $proof = $this->paymentProofForSbo($proofId);

        if (null === app(SboPaymentProofReviewer::class)->approve($proof, auth()->user())) {
            return;
        }

        $this->sbo->refresh();

        session()->flash('success', 'Payment proof approved and registration fees marked paid.');
    }

    public function rejectPaymentProof(int $proofId): void
    {
        abort_unless(auth()->user()->hasPermission(\App\Support\AdminCapabilities::APPROVE_SBOS), 403);

        $this->validate([
            'paymentProofRejectReason' => ['required', 'string', 'max:1000'],
        ], [
            'paymentProofRejectReason.required' => 'Please provide a reason for rejecting the payment proof.',
        ]);

        $proof = $this->paymentProofForSbo($proofId);

        if (null === app(SboPaymentProofReviewer::class)->reject($proof, auth()->user(), $this->paymentProofRejectReason)) {
            return;
        }

        $this->paymentProofRejectReason = '';
        $this->sbo->refresh();

        session()->flash('success', 'Payment proof rejected.');
    }

    private function paymentProofForSbo(int $proofId): SboPaymentProof
    {
        return SboPaymentProof::where('id', $proofId)
            ->where('sbo_id', $this->sbo->id)
            ->firstOrFail();
    }

    /**
     * Keep documents_status / documents_compliant_at in sync with the derived
     * state after a review, and fire the step + verified notifications when all
     * required documents have just been approved.
     */
    private function syncDocumentsComplianceState(): void
    {
        $this->sbo->refresh();
        $nowCompliant = $this->sbo->documentsCompliant();
        $wasCompliant = $this->sbo->documents_status === 'compliant';

        if ($nowCompliant && ! $wasCompliant) {
            $this->sbo->update([
                'documents_status' => 'compliant',
                'documents_compliant_at' => now(),
            ]);
            $this->sbo->refresh();
            $this->sbo->creator?->notify(new SboDocumentsCompliantNotification($this->sbo));
            $this->notifyIfFullyVerified();
        } elseif (! $nowCompliant && $wasCompliant) {
            // A rejection pulled it back below the bar.
            $this->sbo->update([
                'documents_status' => 'pending',
                'documents_compliant_at' => null,
            ]);
            $this->sbo->refresh();
        }
    }

    public function markFeesPaid(): void
    {
        abort_unless(auth()->user()->hasPermission(\App\Support\AdminCapabilities::APPROVE_SBOS), 403);

        $confirmed = app(SboValidationAdvancer::class)->confirmFeesPaid($this->sbo, auth()->user());

        if (null === $confirmed) {
            return;
        }

        $this->sbo = $confirmed->fresh();

        session()->flash('success', 'Registration fees marked paid.');
    }

    public function confirmFeesPaid(): void
    {
        $this->authorizeRevoke();

        $confirmed = app(SboValidationAdvancer::class)->confirmFeesPaid($this->sbo, auth()->user());

        if (null === $confirmed) {
            return;
        }

        $this->sbo = $confirmed->fresh();
        session()->flash('success', 'Payment confirmed for ' . $this->sbo->name . '.');
    }

    public function confirmDocumentsCompliant(): void
    {
        $this->authorizeRevoke();

        $confirmed = app(SboValidationAdvancer::class)->confirmDocumentsCompliant($this->sbo, auth()->user());

        if (null === $confirmed) {
            return;
        }

        $this->sbo = $confirmed->fresh();
        session()->flash('success', 'Document compliance confirmed for ' . $this->sbo->name . '.');
    }

    public function issueFinalVerification(): void
    {
        $this->authorizeRevoke();

        $confirmed = app(SboValidationAdvancer::class)->issueFinalVerification($this->sbo, auth()->user());

        if (null === $confirmed) {
            return;
        }

        $this->sbo = $confirmed->fresh('seal');
        session()->flash('success', 'Final verification issued for ' . $this->sbo->name . '.');
    }

    /**
     * Revoke the "fees paid" state: return the SBO to its gated (unpaid) stage.
     * Cascades to verification - a fully-verified SBO can't remain verified once
     * fees are unpaid. The payment proof / Espees transaction records are left
     * untouched. Super admins only; logged.
     */
    public function revokeFeesPaid(): void
    {
        $this->authorizeRevoke();

        $revoked = app(SboValidationRevoker::class)->revokeFeesPaid($this->sbo, auth()->user());

        if (null === $revoked) {
            return;
        }

        $this->sbo = $revoked->fresh();
        session()->flash('success', 'Paid status revoked. ' . $this->sbo->name . ' has returned to its gated (unpaid) stage.');
    }

    public function revokeDocumentsCompliant(): void
    {
        $this->authorizeRevoke();

        $revoked = app(SboValidationRevoker::class)->revokeDocumentsCompliant($this->sbo, auth()->user());

        if (null === $revoked) {
            return;
        }

        $this->sbo = $revoked->fresh();
        session()->flash('success', 'Document compliance revoked. ' . $this->sbo->name . ' has returned to document review.');
    }

    /**
     * Revoke final verification. "Verified" is derived (approved + documents +
     * fees), so un-verifying must break a gate: this returns registry approval
     * to pending (reversible by re-approving) and clears the verified artifacts.
     * Fees and documents stay done. Super admins only; logged.
     */
    public function revokeVerified(): void
    {
        $this->authorizeRevoke();

        $revoked = app(SboValidationRevoker::class)->revokeVerified($this->sbo, auth()->user());

        if (null === $revoked) {
            return;
        }

        $this->sbo = $revoked->fresh();
        session()->flash('success', 'Final verification revoked. ' . $this->sbo->name . ' has returned to registry approval review.');
    }

    private function authorizeRevoke(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }

    /**
     * Fire the verified notification exactly once, when the final compliance
     * step completes (approved + documents compliant + fees paid).
     */
    protected function notifyIfFullyVerified(): void
    {
        app(SboVerificationFinalizer::class)->finalizeIfReady($this->sbo, auth()->user());
        $this->sbo->refresh();
    }

    public function decline(): void
    {
        abort_unless(auth()->user()->hasPermission(\App\Support\AdminCapabilities::APPROVE_SBOS), 403);

        $this->validate([
            'declineReason' => 'required|string|max:1000',
        ]);

        $this->sbo->update([
            'verification_status' => 'declined',
            'status' => 'declined',
            'is_active' => false,
            'approved_by' => auth()->id(),
            'date_approved' => now(),
            'decline_reason' => $this->declineReason,
        ]);

        $this->sbo->refresh();

        if ($this->sbo->creator) {
            $this->sbo->creator->notify(new SboDeclinedNotification($this->sbo));
        }

        ActivityLog::create([
            'type' => 'sbo_declined',
            'title' => $this->sbo->name,
            'description' => 'SBO declined by <strong>' . (auth()->user()->name ?? 'admin') . '</strong>.',
            'status' => 'declined',
            'icon' => \Str::upper(\Str::substr($this->sbo->name, 0, 2)),
            'user_id' => auth()->id(),
        ]);

        $this->dispatch('close-decision-modal');
        $this->dispatch('sbo-updated');
        session()->flash('success', 'SBO registration declined.');
    }

    public function requestInfo(): void
    {
        abort_unless(auth()->user()->hasPermission(\App\Support\AdminCapabilities::APPROVE_SBOS), 403);

        $this->validate([
            'infoRequestMessage' => 'required|string|max:2000',
        ]);

        $this->sbo->update([
            'verification_status' => 'info_requested',
            'info_request' => $this->infoRequestMessage,
        ]);

        $this->sbo->refresh();

        if ($this->sbo->creator) {
            $this->sbo->creator->notify(new \App\Notifications\SboInfoRequestedNotification($this->sbo));
        }

        ActivityLog::create([
            'type' => 'sbo_info_requested',
            'title' => $this->sbo->name,
            'description' => 'More info requested by <strong>' . (auth()->user()->name ?? 'admin') . '</strong>.',
            'status' => 'info_requested',
            'icon' => \Str::upper(\Str::substr($this->sbo->name, 0, 2)),
            'user_id' => auth()->id(),
        ]);

        $this->dispatch('close-decision-modal');
        $this->dispatch('sbo-updated');
        session()->flash('success', 'Information request sent to the applicant.');
    }

    /** Fields shown on each edit-modal tab, in tab order. */
    protected const EDIT_TAB_FIELDS = [
        'identity' => ['name', 'entity_type', 'rc_number', 'tax_number', 'business_type', 'category', 'region', 'established_at', 'website'],
        'contact' => ['primary_contact_name', 'primary_contact_email', 'phone', 'kingschat_handle'],
        'profile' => ['staff_count', 'business_model', 'description', 'directors', 'directors.*'],
    ];

    public function setEditTab(string $tab): void
    {
        if (array_key_exists($tab, self::EDIT_TAB_FIELDS)) {
            $this->editTab = $tab;
        }
    }

    /**
     * First tab (in tab order) owning any of the given failed field keys.
     *
     * @param  list<string>  $failedKeys
     */
    protected function firstTabWithErrors(array $failedKeys): string
    {
        foreach (self::EDIT_TAB_FIELDS as $tab => $patterns) {
            foreach ($failedKeys as $key) {
                foreach ($patterns as $pattern) {
                    // Str::is handles the nested "directors.*" keys.
                    if ($key === $pattern || \Str::is($pattern, $key)) {
                        return $tab;
                    }
                }
            }
        }

        return $this->editTab;
    }

    public function edit(): void
    {
        $this->editing = true;
        $this->editTab = 'identity';
        $this->resetValidation();

        $this->fill([
            'name' => $this->sbo->name,
            'entity_type' => $this->sbo->entity_type ?: Sbo::ENTITY_SBO,
            'rc_number' => $this->sbo->rc_number,
            'tax_number' => $this->sbo->tax_number,
            'business_type' => $this->sbo->business_type,
            'category' => $this->sbo->category,
            'region' => $this->sbo->region,
            // Cast to a date, so format it for the <input type="date"> binding.
            'established_at' => $this->sbo->established_at?->format('Y-m-d'),
            'website' => $this->sbo->website,
            'primary_contact_name' => $this->sbo->primary_contact_name,
            'primary_contact_email' => $this->sbo->primary_contact_email,
            'phone' => $this->sbo->phone,
            'kingschat_handle' => $this->sbo->kingschat_handle,
            'staff_count' => $this->sbo->staff_count,
            'business_model' => $this->sbo->business_model,
            'description' => $this->sbo->description,
        ]);

        $this->directors = array_values(array_map(
            fn (array $director): array => [
                'name' => $director['name'] ?? '',
                'email' => $director['email'] ?? '',
                'phone' => $director['phone'] ?? '',
            ],
            $this->sbo->directors ?? []
        ));

        $this->dispatch('open-edit-org-modal');
    }

    public function addDirector(): void
    {
        $this->directors[] = ['name' => '', 'email' => '', 'phone' => ''];
    }

    public function removeDirector(int $index): void
    {
        unset($this->directors[$index]);

        $this->directors = array_values($this->directors);
    }

    protected function updateRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'entity_type' => 'required|in:' . Sbo::ENTITY_SBO . ',' . Sbo::ENTITY_COB,
            'rc_number' => 'nullable|string|max:100',
            'tax_number' => 'nullable|string|max:100',
            'business_type' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'region' => 'nullable|string|max:100',
            'established_at' => 'nullable|date',
            'website' => 'nullable|url',

            'primary_contact_name' => 'required|string|max:255',
            'primary_contact_email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'kingschat_handle' => 'nullable|string|max:255',

            'staff_count' => 'nullable|integer|min:0',
            'business_model' => 'nullable|string|max:50',
            'description' => 'nullable|string',

            'directors' => 'array',
            'directors.*.name' => 'required|string|max:255',
            'directors.*.email' => 'required|email|max:255',
            'directors.*.phone' => 'nullable|string|max:50',
        ];
    }

    public function updateSbo(): void
    {
        // Drop rows the admin left entirely blank so an empty placeholder row
        // can never block an otherwise valid save.
        $this->directors = array_values(array_filter(
            $this->directors,
            fn (array $director): bool => filled($director['name'] ?? null)
                || filled($director['email'] ?? null)
                || filled($director['phone'] ?? null)
        ));

        try {
            $this->validate($this->updateRules());
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Surface the failure where it lives rather than stranding the
            // admin on whichever tab they happened to be on.
            $this->editTab = $this->firstTabWithErrors(array_keys($e->validator->errors()->messages()));

            throw $e;
        }

        $this->sbo->update([
            'name' => $this->name,
            'slug' => \Str::slug($this->name),
            'entity_type' => $this->entity_type,
            'rc_number' => $this->rc_number,
            'tax_number' => $this->tax_number,
            'business_type' => $this->business_type,
            'category' => $this->category,
            'region' => $this->region,
            'established_at' => $this->established_at,
            'website' => $this->website,
            'primary_contact_name' => $this->primary_contact_name,
            'primary_contact_email' => $this->primary_contact_email,
            'phone' => $this->phone,
            'kingschat_handle' => $this->kingschat_handle,
            'staff_count' => $this->staff_count,
            'business_model' => $this->business_model,
            'description' => $this->description,
            'directors' => $this->directors,
        ]);

        $this->dispatch('close-edit-org-modal');
        $this->dispatch('sbo-updated');

        session()->flash('success', 'Organization updated successfully.');
    }

    public function mount(Sbo $sbo): void
    {
        $this->sbo = $sbo;

        $rfqsQuery = PurchaseOrder::where('sbo_id', $sbo->id);
        $rfqIds = $sbo->purchaseOrders()->pluck('id');

        $this->totalRfqs = (clone $rfqsQuery)->count();

        $this->activeRfqs = (clone $rfqsQuery)
            ->where('status', 'open')
            ->count();

        $this->awardedRfqs = (clone $rfqsQuery)
            ->where('status', 'awarded')
            ->count();

        $this->completedRfqs = (clone $rfqsQuery)
            ->where('status', 'completed')
            ->count();

        $engagedBidders = PurchaseOrderBid::whereIn('purchase_order_id', $rfqIds)
            ->get(['business_id', 'bidder_sbo_id'])
            ->map(fn (PurchaseOrderBid $bid): ?string => $bid->bidder_sbo_id
                ? 'sbo-' . $bid->bidder_sbo_id
                : ($bid->business_id ? 'business-' . $bid->business_id : null))
            ->filter()
            ->unique();

        $this->businessesEngaged = $engagedBidders->count();

        $this->totalBids = PurchaseOrderBid::whereIn('purchase_order_id', $rfqIds)->count();

        $this->uniqueBusinessesEngaged = $engagedBidders->count();

    }

    public function with(): array
    {
        // Active document types paired with this SBO's submission (if any).
        $submissions = $this->sbo->complianceDocuments()->with('media')->get()->keyBy('compliance_document_type_id');
        $paymentProof = $this->sbo->paymentProof()->with('media')->first();

        $complianceRows = SboComplianceRequirements::activeFor($this->sbo)
            ->map(fn (ComplianceDocumentType $type): array => [
                'type' => $type,
                'document' => $submissions->get($type->id),
            ]);

        return [
            'assignableUsers' => User::orderBy('lastname')->orderBy('firstname')->get(),
            'complianceRows' => $complianceRows,
            'paymentProof' => $paymentProof,
            'progress' => SboApplicationProgress::for($this->sbo),
            'canRevoke' => auth()->user()?->isSuperAdmin() ?? false,
        ];
    }

};
?>

<div>

    <style>
        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* Vertical validation timeline */
        .sbo-timeline {
            list-style: none;
            margin: 0;
            padding: 0;
            position: relative;
        }
        .sbo-timeline__item {
            position: relative;
            padding: 0 0 1.5rem 2.75rem;
        }
        .sbo-timeline__item:last-child { padding-bottom: 0; }
        /* connecting line */
        .sbo-timeline__item:not(:last-child)::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 32px;
            bottom: 0;
            width: 2px;
            background: #e5e7eb;
        }
        .sbo-timeline__dot {
            position: absolute;
            left: 0;
            top: 0;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            background: #f1f3f5;
            color: #868e96;
            border: 2px solid #e5e7eb;
        }
        .sbo-timeline__item--complete .sbo-timeline__dot {
            background: #d1e7dd; color: #0f5132; border-color: #badbcc;
        }
        .sbo-timeline__item--complete:not(:last-child)::before { background: #badbcc; }
        .sbo-timeline__item--current .sbo-timeline__dot {
            background: #fff3cd; color: #664d03; border-color: #ffe69c;
        }
        .sbo-timeline__item--blocked .sbo-timeline__dot {
            background: #f8d7da; color: #842029; border-color: #f5c2c7;
        }
    </style>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a
                href="{{ route('admin.sbos.index') }}"
                class="btn btn-sm btn-outline-secondary rounded-pill"
            >
                <i class="bi bi-arrow-left me-1"></i> Back to organizations
            </a>

            <h1 class="h4 mt-3 mb-0">{{ $sbo->name }}</h1>

            <div class="small text-muted-soft">
                @if($sbo->application_id)
                    <span class="me-2">App ID: <strong>{{ $sbo->application_id }}</strong></span>
                @endif
                @php
                    $vs = $sbo->verification_status ?? 'pending';
                    $badge = match($vs) {
                        'approved'      => ['bg-success-subtle text-success border-success-subtle', 'Approved'],
                        'declined'      => ['bg-danger-subtle text-danger border-danger-subtle', 'Declined'],
                        'info_requested'=> ['bg-info-subtle text-info border-info-subtle', 'Info Requested'],
                        default         => ['bg-warning-subtle text-warning border-warning-subtle', 'Pending'],
                    };
                @endphp
                <span class="badge {{ $badge[0] }} border">{{ $badge[1] }}</span>
            </div>
        </div>

        <div class="d-flex gap-2">
            @if($sbo->isPendingApproval() || $sbo->isInfoRequested())
                @can('approve_sbos')
                    <button class="btn btn-sm btn-success rounded-pill"
                        data-bs-toggle="modal" data-bs-target="#approveModal">
                        Approve
                    </button>
                    <button class="btn btn-sm btn-outline-info rounded-pill"
                        data-bs-toggle="modal" data-bs-target="#infoRequestModal">
                        Request Info
                    </button>
                    <button class="btn btn-sm btn-outline-danger rounded-pill"
                        data-bs-toggle="modal" data-bs-target="#declineModal">
                        Decline
                    </button>
                @endcan
            @endif
            <button
                class="btn btn-sm btn-outline-primary rounded-pill"
                wire:click="edit"
            >
                Edit organization
            </button>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    {{-- ============ VALIDATION TIMELINE ============ --}}
    @php
        // Per-step metadata for timestamps / actor / revoke, keyed by step key.
        $stepMeta = [
            'submitted' => [
                'icon' => 'bi-inbox',
                'meta' => $sbo->date_received ? 'Received ' . $sbo->date_received->format('d M Y') : null,
            ],
            'fees' => [
                'icon' => 'bi-cash-coin',
                'meta' => $sbo->feesPaid() && $sbo->payment_confirmed_at
                    ? 'Confirmed ' . $sbo->payment_confirmed_at->format('d M Y')
                    : ($sbo->hasSubmittedPayment() ? 'Proof submitted - awaiting confirmation' : null),
                'confirm' => ! $sbo->feesPaid() ? [
                    'method' => 'confirmFeesPaid',
                    'label' => 'Confirm payment',
                    'confirm' => 'Confirm payment for ' . $sbo->name . '? This manually marks registration fees as paid.',
                ] : null,
                'revoke' => $sbo->feesPaid() ? [
                    'method' => 'revokeFeesPaid',
                    'label' => 'Revoke paid status',
                    'confirm' => 'Revoke paid status? ' . $sbo->name . ' returns to its gated (unpaid) stage. If it was verified, that is undone too.',
                ] : null,
            ],
            'documents' => [
                'icon' => 'bi-file-earmark-check',
                /*
                 * Surfaces work waiting on the reviewer.
                 *
                 * This read "0 of 1 required documents approved" whether the
                 * applicant had uploaded nothing at all or had submitted a
                 * document that was sitting in the queue for this admin — so a
                 * document needing review looked identical to one that had
                 * never arrived.
                 */
                'meta' => match (true) {
                    $sbo->documentsCompliant() && $sbo->documents_compliant_at
                        => 'Compliant ' . $sbo->documents_compliant_at->format('d M Y'),
                    $progress['pending_required_documents_count'] > 0
                        => $progress['pending_required_documents_count'] . ' awaiting review | '
                            . $progress['approved_required_documents_count'] . ' of '
                            . $progress['required_documents_count'] . ' approved',
                    $progress['required_documents_count'] > 0
                        => $progress['approved_required_documents_count'] . ' of '
                            . $progress['required_documents_count'] . ' required documents approved',
                    default => null,
                },
                'confirm' => ! $sbo->documentsCompliant() ? [
                    'method' => 'confirmDocumentsCompliant',
                    'label' => 'Mark compliant',
                    'confirm' => 'Mark documents compliant for ' . $sbo->name . '? This approves required document rows for testing.',
                ] : null,
                'revoke' => $sbo->documentsCompliant() ? [
                    'method' => 'revokeDocumentsCompliant',
                    'label' => 'Revoke document compliance',
                    'confirm' => 'Revoke document compliance? ' . $sbo->name . ' returns to document review. If it was verified, that is undone too.',
                ] : null,
            ],
            'verified' => [
                'icon' => 'bi-patch-check',
                'meta' => $sbo->isFullyVerified() && $sbo->verified_notified_at
                    ? 'Verified ' . $sbo->verified_notified_at->format('d M Y') . ($sbo->moc_registration_number ? ' | MOC #' . $sbo->moc_registration_number : '')
                    : null,
                'confirm' => $sbo->isFullyVerified() && ! $sbo->verified_notified_at ? [
                    'method' => 'issueFinalVerification',
                    'label' => 'Issue final',
                    'confirm' => 'Issue final verification for ' . $sbo->name . '? This generates final verification artifacts if ready.',
                ] : null,
                'revoke' => $sbo->verified_notified_at ? [
                    'method' => 'revokeVerified',
                    'label' => 'Revoke final verification',
                    'confirm' => 'Revoke final verification? ' . $sbo->name . ' returns to registry approval review. Fees and documents stay as they are.',
                ] : null,
            ],
        ];
    @endphp

    <div class="card mb-4 sbo-timeline-card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h6 class="fw-bold mb-0">Validation timeline</h6>
                <div class="small text-muted-soft">{{ $progress['percent'] }}% complete</div>
            </div>

            <div class="progress mb-4" style="height: 6px;">
                <div class="progress-bar bg-success" role="progressbar"
                     style="width: {{ $progress['percent'] }}%;"
                     aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div>
            </div>

            <ol class="sbo-timeline">
                @foreach($progress['steps'] as $step)
                    @php $m = $stepMeta[$step['key']] ?? ['icon' => 'bi-circle', 'meta' => null]; @endphp
                    <li class="sbo-timeline__item sbo-timeline__item--{{ $step['status'] }} @if($step['key'] === $progress['current_key'] && ! $step['done']) sbo-timeline__item--current @endif">
                        <span class="sbo-timeline__dot">
                            @if($step['done'])
                                <i class="bi bi-check-lg"></i>
                            @elseif($step['status'] === 'blocked')
                                <i class="bi bi-x-lg"></i>
                            @else
                                <i class="bi {{ $m['icon'] }}"></i>
                            @endif
                        </span>
                        <div class="sbo-timeline__body">
                            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                <div>
                                    <div class="fw-semibold">
                                        {{ $step['label'] }}
                                        @if($step['done'])
                                            <span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Done</span>
                                        @elseif($step['key'] === $progress['current_key'])
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-1">Current</span>
                                        @endif
                                    </div>
                                    <div class="small text-muted-soft">{{ $step['description'] }}</div>
                                    @if(! empty($m['meta']))
                                        <div class="small text-muted mt-1"><i class="bi bi-clock-history me-1"></i>{{ $m['meta'] }}</div>
                                    @endif
                                </div>

                                @if($canRevoke)
                                    <div class="d-flex gap-2 flex-wrap justify-content-end">
                                        @if(! empty($m['confirm']) && empty($m['revoke']))
                                            <button type="button"
                                                class="btn btn-sm btn-outline-success rounded-pill"
                                                wire:click="{{ $m['confirm']['method'] }}"
                                                wire:confirm="{{ $m['confirm']['confirm'] }}">
                                                <i class="bi bi-check2-circle me-1"></i>{{ $m['confirm']['label'] }}
                                            </button>
                                        @endif

                                        @if(! empty($m['revoke']) && $step['done'])
                                            <button type="button"
                                                class="btn btn-sm btn-outline-danger rounded-pill"
                                                wire:click="{{ $m['revoke']['method'] }}"
                                                wire:confirm="{{ $m['revoke']['confirm'] }}">
                                                <i class="bi bi-arrow-counterclockwise me-1"></i>{{ $m['revoke']['label'] }}
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>

    <div class="row g-4">

        <!-- LEFT: Profile -->
        <div class="col-12 col-lg-6">
            <div class="card stat-card h-100">
                <div class="card-body">

                    <!-- Name -->
                    <div class="mb-3">
                        <div class="fw-semibold fs-5">
                            {{ $sbo->name }}
                        </div>

                        @if($sbo->website)
                            <div class="small text-muted-soft">
                                <a href="{{ $sbo->website }}" target="_blank">
                                    {{ parse_url($sbo->website, PHP_URL_HOST) }}
                                </a>
                            </div>
                        @endif

                        @if(!empty($sbo->social_links))
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                @foreach($sbo->social_links as $social)
                                    @if(!empty($social['url']))
                                        <a href="{{ $social['url'] }}" target="_blank" rel="noopener"
                                           class="badge bg-light text-decoration-none text-dark border">
                                            <i class="bi bi-link-45deg"></i> {{ $social['platform'] ?? 'Link' }}
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <!-- Meta -->
                    <div class="small text-muted-soft mb-3">
                        <strong>Type:</strong> {{ $sbo->business_type ?? '—' }}
                        ·
                        <strong>Category:</strong> {{ $sbo->category ?? '—' }}
                        {{--
                            Industry is the sector above category and drives the
                            directory facet, the certificate's Business Sector
                            line and industry group-chat membership — so a
                            reviewer needs to see which one this lands in.
                            See App\Support\SboIndustries.
                        --}}
                        ·
                        <strong>Industry:</strong> {{ $sbo->industry ?? '—' }}
                        <br>
                        <strong>Region:</strong> {{ $sbo->region ?? '—' }}
                    </div>

                    <!-- Registration -->
                    <div class="mb-3">
                        <div class="small text-muted-soft mb-1">Registration</div>

                        {{--
                            The applicant's own company registration number,
                            collected at registration and printed on the
                            certificate. It was never shown here, so a reviewer
                            approved a business without the one field that
                            identifies it in a companies register.

                            Not `moc_registration_number`, which is the number
                            this registry issues on approval.
                        --}}
                        <div class="small">
                            <strong>Registration No.:</strong>
                            {{ $sbo->registration_number ?? '—' }}
                        </div>

                        <div class="small">
                            <strong>RC Number:</strong>
                            {{ $sbo->rc_number ?? '—' }}
                        </div>

                        <div class="small">
                            <strong>TIN:</strong>
                            {{ $sbo->tax_number ?? '—' }}
                        </div>

                        @if($sbo->established_at)
                            <div class="small">
                                <strong>Established:</strong>
                                {{ \Carbon\Carbon::parse($sbo->established_at)->format('F Y') }}
                            </div>
                        @endif
                    </div>

                    <!-- Approval / Verification -->
                    <div class="mb-3">
                        <div class="small text-muted-soft mb-1">Approval</div>

                        @if($sbo->trading_name)
                            <div class="small"><strong>Trading As:</strong> {{ $sbo->trading_name }}</div>
                        @endif
                        <div class="small"><strong>Application ID:</strong> {{ $sbo->application_id ?? '—' }}</div>
                        <div class="small"><strong>Date Received:</strong>
                            {{ $sbo->date_received ? $sbo->date_received->format('d M Y, H:i') : '—' }}
                        </div>
                        <div class="small"><strong>Verification Status:</strong>
                            {{ ucfirst($sbo->verification_status ?? 'pending') }}
                        </div>
                        <div class="small"><strong>Approved/Reviewed By:</strong> {{ optional($sbo->approver)->name ?? '—' }}</div>
                        <div class="small"><strong>Date Approved:</strong>
                            {{ $sbo->date_approved ? $sbo->date_approved->format('d M Y, H:i') : '—' }}
                        </div>
                        <div class="small"><strong>MOC Registration Number:</strong> {{ $sbo->moc_registration_number ?? '—' }}</div>
                        @if($sbo->isDeclined() && $sbo->decline_reason)
                            <div class="small text-danger mt-1"><strong>Decline reason:</strong> {{ $sbo->decline_reason }}</div>
                        @endif
                        @if($sbo->info_request)
                            <div class="small text-info mt-1"><strong>Info requested:</strong> {{ $sbo->info_request }}</div>
                        @endif
                        @if($sbo->resubmitted_at)
                            <div class="small text-success mt-1"><strong>Resubmitted:</strong> {{ $sbo->resubmitted_at->format('d M Y, H:i') }}</div>
                        @endif
                    </div>

                    <!-- Compliance (documents + payment) -->
                    <div class="mb-3">
                        <div class="small text-muted-soft mb-1">Compliance</div>

                        <div class="small d-flex align-items-center gap-1">
                            <strong>Documents:</strong>
                            @if($sbo->documentsCompliant())
                                <span class="text-success">Compliant</span>
                                @if($sbo->documents_compliant_at)
                                    <span class="text-muted-soft">· {{ $sbo->documents_compliant_at->format('d M Y') }}</span>
                                @endif
                            @else
                                <span class="text-warning">Not yet compliant</span>
                            @endif
                        </div>

                        <div class="small d-flex align-items-center gap-1">
                            <strong>Registration fees:</strong>
                            @if($sbo->feesPaid())
                                <span class="text-success">Paid</span>
                                @if($sbo->payment_confirmed_at)
                                    <span class="text-muted-soft">· {{ $sbo->payment_confirmed_at->format('d M Y') }}</span>
                                @endif
                            @elseif($sbo->hasSubmittedPayment())
                                <span class="text-warning">Proof submitted — awaiting confirmation</span>
                            @else
                                <span class="text-warning">Unpaid</span>
                            @endif
                        </div>

                        {{-- Fee breakdown (share-capital-based), shown once the SBO has selected a fee. --}}
                        @if($sbo->calculated_fee !== null)
                            @php
                                $feeCurrency = \App\Support\SboFeeCalculator::currency($sbo->country_id);
                                $structureLabel = \App\Support\SboFeeStructures::label((string) $sbo->share_structure) ?? $sbo->share_structure;
                                $isShareStructure = \App\Support\SboFeeCalculator::isShareStructure((string) $sbo->share_structure);
                            @endphp
                            <div class="small text-muted-soft mt-1 ps-2 border-start">
                                <div><strong>Structure:</strong> {{ $structureLabel }}</div>
                                @if($isShareStructure)
                                    <div><strong>Capital type:</strong> {{ $sbo->share_capital_type ?? '—' }}</div>
                                    <div><strong>Issued shares:</strong> {{ number_format((int) $sbo->issued_shares_volume) }}</div>
                                    <div><strong>Per-share value:</strong> {{ number_format((float) $sbo->per_share_value, 2) }}</div>
                                    <div><strong>Equity size:</strong> {{ number_format((int) $sbo->issued_shares_volume * (float) $sbo->per_share_value, 2) }}</div>
                                @endif
                                <div><strong>Fee:</strong> {{ number_format((int) $sbo->calculated_fee) }} {{ $feeCurrency }}@if($sbo->fee_band) <span class="text-muted-soft">({{ $sbo->fee_band }})</span>@endif</div>
                                @if($sbo->payment_reference)
                                    <div><strong>Reference:</strong> {{ $sbo->payment_reference }}</div>
                                @endif
                            </div>
                        @endif

                        {{-- Payment-proof review: uploaded file + approve/reject workflow. --}}
                        @php $paymentProofHasFile = $paymentProof && $paymentProof->file(); @endphp
                        <div class="border rounded p-2 mt-2">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="small fw-semibold">Proof of payment</div>
                                    @if($paymentProofHasFile)
                                        <a href="{{ route('payment-proofs.download', $paymentProof) }}" target="_blank" class="small">
                                            <i class="bi bi-paperclip"></i> View proof
                                        </a>
                                    @else
                                        <div class="small text-muted-soft">No payment proof uploaded yet.</div>
                                    @endif
                                </div>
                                <div>
                                    @if(! $paymentProofHasFile)
                                        <span class="badge bg-secondary-subtle text-secondary">Not uploaded</span>
                                    @elseif($paymentProof->isApproved())
                                        <span class="badge bg-success-subtle text-success">Approved</span>
                                    @elseif($paymentProof->isRejected())
                                        <span class="badge bg-danger-subtle text-danger">Rejected</span>
                                    @else
                                        <span class="badge bg-warning-subtle text-warning">Pending review</span>
                                    @endif
                                </div>
                            </div>

                            @if($paymentProofHasFile && $paymentProof->isRejected() && $paymentProof->rejection_reason)
                                <div class="small text-danger mt-1">Reason: {{ $paymentProof->rejection_reason }}</div>
                            @endif

                            @if($paymentProofHasFile && ! $paymentProof->isApproved() && ! $sbo->feesPaid())
                                @can('approve_sbos')
                                    <div class="mt-2" x-data="{ rejectingPayment: false }">
                                        <div class="d-flex gap-2" x-show="!rejectingPayment">
                                            <button class="btn btn-sm btn-success"
                                                wire:click="approvePaymentProof({{ $paymentProof->id }})">Approve payment</button>
                                            <button class="btn btn-sm btn-outline-danger" type="button" @click="rejectingPayment = true">Reject</button>
                                        </div>
                                        <div x-show="rejectingPayment" x-cloak class="mt-1">
                                            <textarea wire:model="paymentProofRejectReason" class="form-control form-control-sm" rows="2" placeholder="Reason for rejection"></textarea>
                                            @error('paymentProofRejectReason') <span class="text-danger small">{{ $message }}</span> @enderror
                                            <div class="d-flex gap-2 mt-1">
                                                <button class="btn btn-sm btn-danger" wire:click="rejectPaymentProof({{ $paymentProof->id }})">Confirm reject</button>
                                                <button class="btn btn-sm btn-link text-muted" type="button" @click="rejectingPayment = false">Cancel</button>
                                            </div>
                                        </div>
                                    </div>
                                @endcan
                            @endif
                        </div>

                        <div class="small mt-1">
                            <strong>Overall:</strong>
                            @if($sbo->isFullyVerified())
                                <span class="text-success">Verified</span>
                            @else
                                <span class="text-warning">Provisional</span>
                            @endif
                        </div>

                        @unless($sbo->isDeclined())
                            @can('approve_sbos')
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    {{-- Manual flag is a fallback only when no document types are configured. --}}
                                    @if($complianceRows->isEmpty() && ! $sbo->documentsCompliant())
                                        <button class="btn btn-sm btn-outline-success rounded-pill"
                                            wire:click="markDocumentsCompliant"
                                            wire:confirm="No document types are configured. Mark documents compliant manually?">
                                            Mark documents compliant
                                        </button>
                                    @endif
                                    @if(! $sbo->feesPaid() && ! $paymentProofHasFile)
                                        <button class="btn btn-sm btn-outline-success rounded-pill"
                                            wire:click="markFeesPaid"
                                            wire:confirm="No proof of payment has been uploaded. Confirm the registration fees have been received manually?">
                                            Mark fees paid manually
                                        </button>
                                    @endif
                                </div>
                            @endcan
                        @endunless
                    </div>

                    <!-- Compliance documents review -->
                    @if($complianceRows->isNotEmpty())
                        <div class="mb-3">
                            <div class="small text-muted-soft mb-2">Submitted documents</div>
                            @foreach($complianceRows as $row)
                                @php $doc = $row['document']; $type = $row['type']; @endphp
                                <div class="border rounded p-2 mb-2">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <div class="small fw-semibold">
                                                {{ $type->name }}
                                                @if($type->is_required)
                                                    <span class="badge bg-danger-subtle text-danger ms-1">Required</span>
                                                @else
                                                    <span class="badge bg-secondary-subtle text-secondary ms-1">Optional</span>
                                                @endif
                                                @if($type->country)
                                                    <span class="badge bg-info-subtle text-info ms-1">{{ $type->country->name }}</span>
                                                @endif
                                            </div>
                                            @if($doc && $doc->file())
                                                <a href="{{ route('compliance-documents.download', $doc) }}" target="_blank" class="small">
                                                    <i class="bi bi-paperclip"></i> View file
                                                </a>
                                            @endif
                                        </div>
                                        <div>
                                            @if(! $doc || ! $doc->file())
                                                <span class="badge bg-secondary-subtle text-secondary">Not uploaded</span>
                                            @elseif($doc->isApproved())
                                                <span class="badge bg-success-subtle text-success">Approved</span>
                                            @elseif($doc->isRejected())
                                                <span class="badge bg-danger-subtle text-danger">Rejected</span>
                                            @else
                                                <span class="badge bg-warning-subtle text-warning">Pending review</span>
                                            @endif
                                        </div>
                                    </div>

                                    @if($doc && $doc->isRejected() && $doc->rejection_reason)
                                        <div class="small text-danger mt-1">Reason: {{ $doc->rejection_reason }}</div>
                                    @endif

                                    @if($doc && $doc->file() && ! $doc->isApproved())
                                        @can('approve_sbos')
                                            <div class="mt-2" x-data="{ rejecting: false }">
                                                <div class="d-flex gap-2" x-show="!rejecting">
                                                    <button class="btn btn-sm btn-success"
                                                        wire:click="approveComplianceDocument({{ $doc->id }})">Approve</button>
                                                    <button class="btn btn-sm btn-outline-danger" type="button" @click="rejecting = true">Reject</button>
                                                </div>
                                                <div x-show="rejecting" x-cloak class="mt-1">
                                                    <textarea wire:model="rejectReason.{{ $doc->id }}" class="form-control form-control-sm" rows="2" placeholder="Reason for rejection"></textarea>
                                                    @error('rejectReason.' . $doc->id) <span class="text-danger small">{{ $message }}</span> @enderror
                                                    <div class="d-flex gap-2 mt-1">
                                                        <button class="btn btn-sm btn-danger" wire:click="rejectComplianceDocument({{ $doc->id }})">Confirm reject</button>
                                                        <button class="btn btn-sm btn-link text-muted" type="button" @click="rejecting = false">Cancel</button>
                                                    </div>
                                                </div>
                                            </div>
                                        @endcan
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <!-- Manager -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            {{-- Named for what this business actually is: a COB
                                 owner was being called an SBO Manager. --}}
                            <div class="small text-muted-soft">{{ $sbo->entity_type ?: 'SBO' }} Manager</div>
                            <button class="btn btn-sm btn-outline-primary rounded-pill py-0"
                                data-bs-toggle="modal" data-bs-target="#managerModal">
                                Change
                            </button>
                        </div>
                        @if($sbo->creator)
                            <div class="fw-semibold small">{{ $sbo->creator->name }}</div>
                            <div class="small text-muted-soft">{{ $sbo->creator->email }}</div>
                        @else
                            <div class="small text-muted-soft">No manager assigned.</div>
                        @endif
                    </div>

                    <!-- Primary contact -->
                    <div class="mb-3">
                        <div class="small text-muted-soft mb-1">Primary contact</div>

                        <div class="fw-semibold">
                            {{ $sbo->primary_contact_name }}
                        </div>

                        <div class="small text-muted-soft">
                            {{ $sbo->primary_contact_email }}
                            @if($sbo->phone)
                                · {{ $sbo->phone }}
                            @endif
                        </div>

                        @if($sbo->kingschat_handle)
                            <div class="small text-muted-soft">
                                KingsChat:
                                <strong>{{ $sbo->kingschat_handle }}</strong>
                            </div>
                        @endif
                    </div>

                    <!-- Organization info -->
                    <div class="mb-3">
                        <div class="small text-muted-soft mb-1">Organization details</div>

                        <div class="small">
                            <strong>Staff count:</strong>
                            {{ $sbo->staff_count ?? '—' }}
                        </div>

                        <div class="small">
                            <strong>Business model:</strong>
                            {{ $sbo->business_model ?? '—' }}
                        </div>
                    </div>

                    <hr class="my-3">

                    <!-- About -->
                    <div class="mb-3">
                        <div class="small text-muted-soft mb-1">About</div>
                        <div class="small text-muted">
                            {{ $sbo->description ?: 'No description provided.' }}
                        </div>
                    </div>

                    <!-- Directors -->
                    <div>
                        <div class="small text-muted-soft mb-2">Directors</div>

                        @forelse($sbo->directors ?? [] as $director)
                            <div class="mb-2">
                                <div class="fw-semibold small">
                                    {{ $director['name'] ?? '—' }}
                                </div>
                                <div class="small text-muted-soft">
                                    {{ $director['email'] ?? '' }}
                                    @if(!empty($director['phone']))
                                        · {{ $director['phone'] }}
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="small text-muted-soft">
                                No directors recorded.
                            </div>
                        @endforelse
                    </div>

                </div>
            </div>
        </div>

        <!-- RIGHT: Metrics (STATIC FOR NOW) -->
        <div class="col-12 col-lg-6">

            <div class="row g-3 mb-4">

                <!-- Active RFQs -->
                <div class="col-12 col-lg-6">
                    <div class="card stat-card stat-card-accent h-100" style="min-height:140px;">
                        <div class="card-body d-flex align-items-center gap-3">

                            <div class="stat-icon bg-success-subtle text-success">
                                <!-- Clipboard check SVG -->
                                <svg width="28" height="28" fill="none" viewBox="0 0 24 24">
                                    <path d="M9 11l3 3L22 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke="currentColor" stroke-width="2"/>
                                </svg>
                            </div>

                            <div>
                                <div class="small text-muted-soft mb-1">Active Requests</div>
                                <div class="h2 mb-0 fw-bold">{{ $activeRfqs }}</div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Completed RFQs -->
                <div class="col-12 col-lg-6">
                    <div class="card stat-card h-100" style="min-height:140px;">
                        <div class="card-body d-flex align-items-center gap-3">

                            <div class="stat-icon bg-primary-subtle text-primary">
                                <!-- Check circle SVG -->
                                <svg width="28" height="28" fill="none" viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/>
                                    <path d="M8 12l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                            </div>

                            <div>
                                <div class="small text-muted-soft mb-1">Completed Requests</div>
                                <div class="h2 mb-0 fw-bold">{{ $completedRfqs }}</div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Businesses Engaged -->
                <div class="col-12 col-lg-6">
                    <div class="card stat-card h-100" style="min-height:140px;">
                        <div class="card-body d-flex align-items-center gap-3">

                            <div class="stat-icon bg-warning-subtle text-warning">
                                <!-- Users SVG -->
                                <svg width="28" height="28" fill="none" viewBox="0 0 24 24">
                                    <path d="M17 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2"/>
                                    <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                                    <path d="M23 20v-2a4 4 0 0 0-3-3.87" stroke="currentColor" stroke-width="2"/>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2"/>
                                </svg>
                            </div>

                            <div>
                                <div class="small text-muted-soft mb-1">Businesses Engaged</div>
                                <div class="h2 mb-0 fw-bold">{{ $businessesEngaged }}</div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Total RFQs -->
                <div class="col-12 col-lg-6">
                    <div class="card stat-card h-100" style="min-height:140px;">
                        <div class="card-body d-flex align-items-center gap-3">

                            <div class="stat-icon bg-secondary-subtle text-secondary">
                                <!-- Layers SVG -->
                                <svg width="28" height="28" fill="none" viewBox="0 0 24 24">
                                    <polygon points="12 2 2 7 12 12 22 7 12 2" stroke="currentColor" stroke-width="2"/>
                                    <polyline points="2 17 12 22 22 17" stroke="currentColor" stroke-width="2"/>
                                    <polyline points="2 12 12 17 22 12" stroke="currentColor" stroke-width="2"/>
                                </svg>
                            </div>

                            <div>
                                <div class="small text-muted-soft mb-1">Total Requests</div>
                                <div class="h2 mb-0 fw-bold">{{ $totalRfqs }}</div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Total Bids Received -->
                <div class="col-12 col-lg-6">
                    <div class="card stat-card h-100" style="min-height:140px;">
                        <div class="card-body d-flex align-items-center gap-3">

                            <div class="stat-icon bg-info-subtle text-info">
                                <!-- Inbox / bids SVG -->
                                <svg width="28" height="28" fill="none" viewBox="0 0 24 24">
                                    <path d="M4 4h16v10H4z" stroke="currentColor" stroke-width="2"/>
                                    <path d="M4 14h4l2 3h4l2-3h4" stroke="currentColor" stroke-width="2"/>
                                </svg>
                            </div>

                            <div>
                                <div class="small text-muted-soft mb-1">Total Bids Received</div>
                                <div class="h2 mb-0 fw-bold">{{ $totalBids }}</div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Unique Businesses Engaged -->
                <div class="col-12 col-lg-6">
                    <div class="card stat-card h-100" style="min-height:140px;">
                        <div class="card-body d-flex align-items-center gap-3">

                            <div class="stat-icon bg-dark-subtle text-dark">
                                <!-- Briefcase / business SVG -->
                                <svg width="28" height="28" fill="none" viewBox="0 0 24 24">
                                    <rect x="3" y="7" width="18" height="13" rx="2" stroke="currentColor" stroke-width="2"/>
                                    <path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" stroke="currentColor" stroke-width="2"/>
                                </svg>
                            </div>

                            <div>
                                <div class="small text-muted-soft mb-1">Unique Businesses Engaged</div>
                                <div class="h2 mb-0 fw-bold">{{ $uniqueBusinessesEngaged }}</div>
                            </div>

                        </div>
                    </div>
                </div>


            </div>
 
        </div>

    </div>

    <!-- EDIT ORGANIZATION MODAL -->
    <div
    class="modal fade"
    id="editOrgModal"
    tabindex="-1"
    aria-labelledby="editOrgModalLabel"
    aria-hidden="true"
    wire:ignore.self
    >
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

        <form wire:submit.prevent="updateSbo">
            <div class="modal-header">
            <h5 class="modal-title" id="editOrgModalLabel">Edit organization</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
            @php
                // Error counts per tab, so a failure on a hidden tab is still findable.
                $identityErrorCount = collect(['name', 'entity_type', 'rc_number', 'tax_number', 'business_type', 'category', 'region', 'established_at', 'website'])
                    ->sum(fn (string $field): int => $errors->has($field) ? 1 : 0);
                $contactErrorCount = collect(['primary_contact_name', 'primary_contact_email', 'phone', 'kingschat_handle'])
                    ->sum(fn (string $field): int => $errors->has($field) ? 1 : 0);
                $profileErrorCount = $errors->has('staff_count') + $errors->has('business_model') + $errors->has('description')
                    + ($errors->hasAny(['directors', 'directors.*']) ? 1 : 0);
            @endphp

            @if ($errors->any())
                <div class="alert alert-danger py-2">
                    <ul class="mb-0 ps-3 small">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <ul class="nav nav-tabs mb-3" id="editOrgTabs" role="tablist">
                @foreach ([
                    'identity' => ['label' => 'Identity', 'errors' => $identityErrorCount],
                    'contact' => ['label' => 'Contact', 'errors' => $contactErrorCount],
                    'profile' => ['label' => 'Profile &amp; directors', 'errors' => $profileErrorCount],
                ] as $tabKey => $tab)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $editTab === $tabKey ? 'active' : '' }}"
                            id="edit-org-{{ $tabKey }}-tab" type="button" role="tab"
                            wire:click="setEditTab('{{ $tabKey }}')"
                            aria-controls="edit-org-{{ $tabKey }}"
                            aria-selected="{{ $editTab === $tabKey ? 'true' : 'false' }}">
                            {!! $tab['label'] !!}
                            @if ($tab['errors'])
                                <span class="badge rounded-pill text-bg-danger ms-1">{{ $tab['errors'] }}</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content">

            {{-- ---------- IDENTITY ---------- --}}
            <div class="tab-pane fade {{ $editTab === 'identity' ? 'show active' : '' }}" id="edit-org-identity" role="tabpanel" aria-labelledby="edit-org-identity-tab">
            <div class="row g-3">

                <div class="col-md-6">
                <label class="form-label">Entity type</label>
                <select class="form-select @error('entity_type') is-invalid @enderror" wire:model.defer="entity_type">
                    <option value="{{ \App\Models\Sbo::ENTITY_SBO }}">{{ \App\Models\Sbo::ENTITY_TYPES[\App\Models\Sbo::ENTITY_SBO] }}</option>
                    <option value="{{ \App\Models\Sbo::ENTITY_COB }}">{{ \App\Models\Sbo::ENTITY_TYPES[\App\Models\Sbo::ENTITY_COB] }}</option>
                </select>
                @error('entity_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">Organization name</label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" wire:model.defer="name">
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">Registration number</label>
                <input type="text" class="form-control @error('rc_number') is-invalid @enderror" wire:model.defer="rc_number">
                @error('rc_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">TIN</label>
                <input type="text" class="form-control @error('tax_number') is-invalid @enderror" wire:model.defer="tax_number">
                @error('tax_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">Business type</label>
                <select class="form-select @error('business_type') is-invalid @enderror" wire:model.defer="business_type">
                    <option value="">Choose…</option>
                    <option>Limited Liability Company (LLC)</option>
                    <option>Sole Proprietorship</option>
                    <option>Partnership</option>
                    <option>Cooperative</option>
                    <option>Non-profit / NGO</option>
                </select>
                @error('business_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">Category</label>
                <select class="form-select @error('category') is-invalid @enderror" wire:model.defer="category">
                    <option value="">Choose…</option>
                    <option>Ministry</option>
                    <option>Business</option>
                    <option>Education</option>
                    <option>NGO</option>
                </select>
                @error('category') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">Region</label>
                <select class="form-select @error('region') is-invalid @enderror" wire:model.defer="region">
                    <option value="">Choose…</option>
                    <option>West Africa</option>
                    <option>East Africa</option>
                    <option>South Africa</option>
                    <option>North Africa</option>
                    <option>Middle East</option>
                    <option>Asia</option>
                    <option>Eastern Europe</option>
                    <option>Western Europe</option>
                </select>
                @error('region') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">Establishment date</label>
                <input type="date" class="form-control @error('established_at') is-invalid @enderror" wire:model.defer="established_at">
                @error('established_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">Website</label>
                <input type="url" class="form-control @error('website') is-invalid @enderror" wire:model.defer="website">
                @error('website') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

            </div>
            </div>

            {{-- ---------- CONTACT ---------- --}}
            <div class="tab-pane fade {{ $editTab === 'contact' ? 'show active' : '' }}" id="edit-org-contact" role="tabpanel" aria-labelledby="edit-org-contact-tab">
            <div class="row g-3">

                <div class="col-md-6">
                <label class="form-label">Primary contact name</label>
                <input type="text" class="form-control @error('primary_contact_name') is-invalid @enderror" wire:model.defer="primary_contact_name">
                @error('primary_contact_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">Primary contact email</label>
                <input type="email" class="form-control @error('primary_contact_email') is-invalid @enderror" wire:model.defer="primary_contact_email">
                @error('primary_contact_email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control @error('phone') is-invalid @enderror" wire:model.defer="phone">
                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">KingsChat handle / URL</label>
                <input type="text" class="form-control @error('kingschat_handle') is-invalid @enderror" wire:model.defer="kingschat_handle">
                @error('kingschat_handle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

            </div>
            </div>

            {{-- ---------- PROFILE & DIRECTORS ---------- --}}
            <div class="tab-pane fade {{ $editTab === 'profile' ? 'show active' : '' }}" id="edit-org-profile" role="tabpanel" aria-labelledby="edit-org-profile-tab">
            <div class="row g-3">

                <div class="col-md-6">
                <label class="form-label">Number of staff</label>
                <input type="number" min="0" class="form-control @error('staff_count') is-invalid @enderror" wire:model.defer="staff_count">
                @error('staff_count') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                <label class="form-label">Business model</label>
                <select class="form-select @error('business_model') is-invalid @enderror" wire:model.defer="business_model">
                    <option value="">Choose…</option>
                    <option>B2B</option>
                    <option>B2C</option>
                    <option>B2G</option>
                    <option>Hybrid</option>
                </select>
                @error('business_model') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                <label class="form-label">Description</label>
                <textarea class="form-control @error('description') is-invalid @enderror" rows="3" wire:model.defer="description"></textarea>
                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <!-- Directors -->
                <div class="col-12">
                <label class="form-label">Directors</label>

                @forelse($directors as $index => $director)
                    <div class="row g-2 mb-2" wire:key="director-{{ $index }}">
                    <div class="col-md-4">
                        <input class="form-control @error('directors.'.$index.'.name') is-invalid @enderror" placeholder="Name"
                        wire:model.defer="directors.{{ $index }}.name">
                        @error('directors.'.$index.'.name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <input class="form-control @error('directors.'.$index.'.email') is-invalid @enderror" placeholder="Email"
                        wire:model.defer="directors.{{ $index }}.email">
                        @error('directors.'.$index.'.email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <input class="form-control" placeholder="Phone"
                        wire:model.defer="directors.{{ $index }}.phone">
                    </div>
                    <div class="col-md-1 text-end">
                        <button
                        type="button"
                        class="btn btn-sm btn-outline-danger rounded-pill"
                        wire:click="removeDirector({{ $index }})"
                        >
                        ×
                        </button>
                    </div>
                    </div>
                @empty
                    <p class="small text-muted-soft mb-2">No directors on record.</p>
                @endforelse

                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" wire:click="addDirector">
                    <i class="bi bi-plus-lg"></i> Add director
                </button>
                </div>

            </div>
            </div>

            </div>{{-- /.tab-content --}}
            </div>{{-- /.modal-body --}}

            <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">
                Cancel
            </button>
            <button class="btn btn-primary rounded-pill" type="submit">
                Save changes
            </button>
            </div>
        </form>

        </div>
    </div>
    </div>

    <!-- APPROVE MODAL -->
    <div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="approve">
                    <div class="modal-header">
                        <h5 class="modal-title" id="approveModalLabel">Approve SBO</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted-soft">Approving <strong>{{ $sbo->name }}</strong> will activate it and notify the owner.</p>
                        <label class="form-label">Loveworld Kingdom (MOC) Registration Number <small class="text-muted">(optional)</small></label>
                        <input class="form-control" wire:model.defer="mocNumber" placeholder="e.g. MOC-2026-00123">
                        @error('mocNumber') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success rounded-pill">Confirm Approval</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DECLINE MODAL -->
    <div class="modal fade" id="declineModal" tabindex="-1" aria-labelledby="declineModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="decline">
                    <div class="modal-header">
                        <h5 class="modal-title" id="declineModalLabel">Decline SBO</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted-soft">Declining <strong>{{ $sbo->name }}</strong> will notify the owner with the reason below.</p>
                        <label class="form-label">Reason for decline</label>
                        <textarea class="form-control" rows="3" wire:model.defer="declineReason" placeholder="Explain why this registration is being declined"></textarea>
                        @error('declineReason') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill">Confirm Decline</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- REQUEST INFO MODAL -->
    <div class="modal fade" id="infoRequestModal" tabindex="-1" aria-labelledby="infoRequestModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="requestInfo">
                    <div class="modal-header">
                        <h5 class="modal-title" id="infoRequestModalLabel">Request More Information</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted-soft">Ask <strong>{{ $sbo->name }}</strong> for the additional details needed. The applicant will be emailed and can update &amp; resubmit.</p>
                        <label class="form-label">What information is needed?</label>
                        <textarea class="form-control" rows="3" wire:model.defer="infoRequestMessage" placeholder="e.g. Please provide a valid RC number and a clearer business address."></textarea>
                        @error('infoRequestMessage') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info rounded-pill">Send Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- CHANGE MANAGER MODAL -->
    <div class="modal fade" id="managerModal" tabindex="-1" aria-labelledby="managerModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="assignManager">
                    <div class="modal-header">
                        <h5 class="modal-title" id="managerModalLabel">Change {{ $sbo->entity_type ?: 'SBO' }} Manager</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted-soft">Assign a user as the manager of <strong>{{ $sbo->name }}</strong>. They will be given the business-manager role and notified by email.</p>
                        <label class="form-label">Select user</label>
                        <select class="form-select" wire:model.defer="newManagerId">
                            <option value="">Choose a user…</option>
                            @foreach($assignableUsers as $u)
                                <option value="{{ $u->id }}" @selected($sbo->created_by === $u->id)>
                                    {{ $u->name }} — {{ $u->email }}{{ $sbo->created_by === $u->id ? ' (current)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('newManagerId') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill">Assign Manager</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @script
    <script>
        const closeSboModal = (id) => {
            const el = document.getElementById(id);
            if (el) {
                const inst = bootstrap.Modal.getInstance(el);
                if (inst) inst.hide();
            }
        };
        window.addEventListener('close-decision-modal', () => {
            ['approveModal', 'declineModal', 'infoRequestModal'].forEach(closeSboModal);
        });
        window.addEventListener('close-manager-modal', () => closeSboModal('managerModal'));
    </script>
    @endscript

</div>
