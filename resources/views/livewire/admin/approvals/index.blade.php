<?php

use App\Models\Sbo;
use App\Models\SboPaymentProof;
use App\Models\SboPaymentTransaction;
use App\Support\AdminCapabilities;
use App\Support\SboPaymentProofReviewer;
use App\Support\SboPaymentTransactionReviewer;
use App\Support\SboValidationAdvancer;
use App\Support\SboValidationRevoker;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Validation Approvals - Admin Dashboard', 'dataPage' => 'approvals-workflow', 'pageTitle' => 'Validation Approvals'])] class extends Component
{
    use WithPagination;

    public string $stageFilter = 'active';

    public string $search = '';

    public ?int $selectedPaymentId = null;

    public string $selectedPaymentType = 'proof';

    public string $rejectionReason = '';

    protected string $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        $this->authorizeReview();
    }

    public function updatingStageFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->reset('search');
        $this->resetPage();
    }

    public function openApproveModal(int $paymentId, string $type = 'proof'): void
    {
        $this->authorizeReview();
        $this->selectedPaymentId = $paymentId;
        $this->selectedPaymentType = $type;
        $this->dispatch('open-payment-approval-modal');
    }

    public function approveSelectedPayment(): void
    {
        $this->authorizeReview();

        $approved = match ($this->selectedPaymentType) {
            'transaction' => app(SboPaymentTransactionReviewer::class)->approve($this->selectedTransactionOrFail(), auth()->user()),
            default => app(SboPaymentProofReviewer::class)->approve($this->selectedProofOrFail(), auth()->user()),
        };

        $this->selectedPaymentId = null;
        $this->dispatch('close-payment-approval-modal');

        if (null === $approved) {
            session()->flash('warning', 'Payment could not be approved. It may already be approved or no longer needs review.');

            return;
        }

        session()->flash('success', 'Payment approved and registration fees marked paid.');
    }

    public function openRejectModal(int $paymentId, string $type = 'proof'): void
    {
        $this->authorizeReview();
        $this->selectedPaymentId = $paymentId;
        $this->selectedPaymentType = $type;
        $this->rejectionReason = '';
        $this->dispatch('open-payment-reject-modal');
    }

    public function rejectSelectedPayment(): void
    {
        $this->authorizeReview();

        $this->validate([
            'rejectionReason' => ['required', 'string', 'max:1000'],
        ], [
            'rejectionReason.required' => 'Please provide a reason for rejecting the payment.',
        ]);

        $rejected = match ($this->selectedPaymentType) {
            'transaction' => app(SboPaymentTransactionReviewer::class)->reject($this->selectedTransactionOrFail(), auth()->user(), $this->rejectionReason),
            default => app(SboPaymentProofReviewer::class)->reject($this->selectedProofOrFail(), auth()->user(), $this->rejectionReason),
        };

        $this->selectedPaymentId = null;
        $this->rejectionReason = '';
        $this->dispatch('close-payment-reject-modal');

        if (null === $rejected) {
            session()->flash('warning', 'Payment could not be rejected because the fees are already paid.');

            return;
        }

        session()->flash('success', 'Payment rejected.');
    }

    public function revokeStep(int $sboId, string $step): void
    {
        $this->authorizeAdjust();

        $sbo = Sbo::query()->with(['seal', 'complianceDocuments'])->findOrFail($sboId);
        $revoker = app(SboValidationRevoker::class);

        $revoked = match ($step) {
            'fees' => $revoker->revokeFeesPaid($sbo, auth()->user()),
            'documents' => $revoker->revokeDocumentsCompliant($sbo, auth()->user()),
            'verified' => $revoker->revokeVerified($sbo, auth()->user()),
            default => null,
        };

        if (null === $revoked) {
            session()->flash('warning', 'That validation step could not be revoked. It may already be pending.');

            return;
        }

        session()->flash('success', match ($step) {
            'fees' => 'Paid status revoked for ' . $revoked->name . '.',
            'documents' => 'Document compliance revoked for ' . $revoked->name . '.',
            'verified' => 'Final verification revoked for ' . $revoked->name . '.',
            default => 'Validation step revoked for ' . $revoked->name . '.',
        });
    }

    public function confirmStep(int $sboId, string $step): void
    {
        $this->authorizeAdjust();

        $sbo = Sbo::query()->with(['seal', 'complianceDocuments'])->findOrFail($sboId);
        $advancer = app(SboValidationAdvancer::class);

        $confirmed = match ($step) {
            'fees' => $advancer->confirmFeesPaid($sbo, auth()->user()),
            'documents' => $advancer->confirmDocumentsCompliant($sbo, auth()->user()),
            'verified' => $advancer->issueFinalVerification($sbo, auth()->user()),
            default => null,
        };

        if (null === $confirmed) {
            session()->flash('warning', 'That validation step could not be confirmed. It may already be complete or still depends on an earlier step.');

            return;
        }

        session()->flash('success', match ($step) {
            'fees' => 'Payment confirmed for ' . $confirmed->name . '.',
            'documents' => 'Document compliance confirmed for ' . $confirmed->name . '.',
            'verified' => 'Final verification issued for ' . $confirmed->name . '.',
            default => 'Validation step confirmed for ' . $confirmed->name . '.',
        });
    }

    public function with(): array
    {
        $rows = $this->rows();

        return [
            'approvalRows' => $this->paginateRows($this->filterRows($rows)),
            'counts' => $this->counts($rows),
            'selectedPayment' => $this->selectedPayment(),
            'canAdjust' => auth()->user()?->isSuperAdmin() ?? false,
        ];
    }

    private function authorizeReview(): void
    {
        abort_unless(auth()->user()?->hasPermission(AdminCapabilities::APPROVE_SBOS), 403);
    }

    private function authorizeAdjust(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }

    private function selectedPayment(): ?array
    {
        if (null === $this->selectedPaymentId) {
            return null;
        }

        if ('transaction' === $this->selectedPaymentType) {
            $transaction = SboPaymentTransaction::query()
                ->with(['sbo.creator', 'reviewer'])
                ->whereKey($this->selectedPaymentId)
                ->first();

            return $transaction ? $this->paymentRowForTransaction($transaction) : null;
        }

        $proof = SboPaymentProof::query()
            ->with(['sbo.creator', 'reviewer', 'media'])
            ->whereKey($this->selectedPaymentId)
            ->first();

        return $proof ? $this->paymentRowForProof($proof) : null;
    }

    private function selectedProofOrFail(): SboPaymentProof
    {
        return SboPaymentProof::query()
            ->with(['sbo.creator', 'media'])
            ->whereKey($this->selectedPaymentId)
            ->firstOrFail();
    }

    private function selectedTransactionOrFail(): SboPaymentTransaction
    {
        return SboPaymentTransaction::query()
            ->with(['sbo.creator'])
            ->whereKey($this->selectedPaymentId)
            ->firstOrFail();
    }

    private function rows(): Collection
    {
        $search = mb_trim($this->search);

        return Sbo::query()
            ->with([
                'creator',
                'approver',
                'paymentProof.media',
                'paymentProof.reviewer',
                'paymentTransactions.reviewer',
                'complianceDocuments.media',
                'complianceDocuments.type.country',
                'complianceDocuments.reviewer',
            ])
            ->where('verification_status', 'approved')
            ->when('' !== $search, function ($query) use ($search): void {
                $term = '%' . $search . '%';
                $query->where(function ($q) use ($term): void {
                    $q->where('name', 'like', $term)
                        ->orWhere('application_id', 'like', $term)
                        ->orWhere('moc_registration_number', 'like', $term)
                        // Reviewers look a business up by the number on its
                        // paperwork, which is this one, not the registry's.
                        ->orWhere('registration_number', 'like', $term)
                        ->orWhere('trading_name', 'like', $term)
                        ->orWhereHas('creator', fn ($owner) => $owner
                            ->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term));
                });
            })
            ->latest('updated_at')
            ->get()
            ->map(fn (Sbo $sbo): array => $this->rowFor($sbo))
            ->toBase();
    }

    private function rowFor(Sbo $sbo): array
    {
        $payment = $this->pendingPaymentFor($sbo);
        $stage = $this->stageFor($sbo);
        $pendingDocuments = $sbo->complianceDocuments->where('status', 'pending')->count();
        $latestDocument = $sbo->complianceDocuments->sortByDesc('updated_at')->first();

        return [
            'sbo' => $sbo,
            'owner' => $sbo->creator,
            'stage' => $stage,
            'stage_label' => $this->stageLabel($stage),
            'stage_class' => $this->stageClass($stage),
            'payment' => $payment,
            // The payment queue only shows owners who have actually paid — a
            // pending proof/transaction to review, or a submitted manual
            // payment — not everyone who has yet to pay.
            'has_payment' => null !== $payment || $sbo->hasSubmittedPayment(),
            // The documents queue only shows owners who have uploaded their
            // required documents, not those with nothing to review yet.
            'documents_submitted' => $sbo->documentsSubmitted(),
            'pending_documents' => $pendingDocuments,
            'latest_document' => $latestDocument,
            'timeline' => $this->timelineFor($sbo, $pendingDocuments),
            'sort_at' => $this->sortDateFor($sbo, $payment, $latestDocument),
        ];
    }

    private function filterRows(Collection $rows): Collection
    {
        return match ($this->stageFilter) {
            // Payment queue: only owners who have actually paid (a payment to
            // review). Documents queue: only owners who have submitted docs.
            'payment' => $rows->where('stage', 'payment')->where('has_payment', true)->values(),
            'documents' => $rows->where('stage', 'documents')->where('documents_submitted', true)->values(),
            'verified' => $rows->where('stage', 'verified')->values(),
            'all' => $rows->values(),
            default => $rows->filter(fn (array $r): bool => ('payment' === $r['stage'] && $r['has_payment'])
                || ('documents' === $r['stage'] && $r['documents_submitted']))->values(),
        };
    }

    private function paginateRows(Collection $rows): LengthAwarePaginator
    {
        $pageName = 'page';
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);
        $perPage = 12;

        $rows = $rows
            ->sortByDesc(fn (array $row) => $row['sort_at']?->timestamp ?? 0)
            ->values();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => $pageName],
        );
    }

    private function counts(Collection $rows): array
    {
        $payment = $rows->where('stage', 'payment')->where('has_payment', true)->count();
        $documents = $rows->where('stage', 'documents')->where('documents_submitted', true)->count();

        return [
            'active' => $payment + $documents,
            'payment' => $payment,
            'documents' => $documents,
            'verified' => $rows->where('stage', 'verified')->count(),
            'all' => $rows->count(),
        ];
    }

    private function stageFor(Sbo $sbo): string
    {
        if ( ! $sbo->isApproved()) {
            return 'registry';
        }

        if ( ! $sbo->feesPaid()) {
            return 'payment';
        }

        if ( ! $sbo->documentsCompliant()) {
            return 'documents';
        }

        // "Final" and "Verified" were the same stage split in two; a fully
        // verified business — whether the final verification is still to be
        // issued or already notified — is simply Verified.
        return 'verified';
    }

    private function stageLabel(string $stage): string
    {
        return match ($stage) {
            'registry' => 'Registry review',
            'payment' => 'Payment approval',
            'documents' => 'Document approval',
            'final' => 'Final verification',
            'verified' => 'Verified',
            default => 'Validation',
        };
    }

    private function stageClass(string $stage): string
    {
        return match ($stage) {
            'verified' => 'bg-success-subtle text-success border-success-subtle',
            'final' => 'bg-primary-subtle text-primary border-primary-subtle',
            'documents' => 'bg-info-subtle text-info border-info-subtle',
            'payment' => 'bg-warning-subtle text-warning border-warning-subtle',
            default => 'bg-light text-dark border',
        };
    }

    private function pendingPaymentFor(Sbo $sbo): ?array
    {
        if ($sbo->feesPaid()) {
            return null;
        }

        $proof = $sbo->paymentProof;

        if ($proof && $proof->isPending() && $proof->file()) {
            return $this->paymentRowForProof($proof);
        }

        $transaction = $sbo->paymentTransactions
            ->where('provider', 'espees')
            ->where('review_status', 'pending')
            ->sortByDesc(fn (SboPaymentTransaction $item) => $item->submitted_at ?? $item->updated_at)
            ->first();

        return $transaction ? $this->paymentRowForTransaction($transaction) : null;
    }

    private function paymentRowForProof(SboPaymentProof $proof): array
    {
        $sbo = $proof->sbo;

        return [
            'type' => 'proof',
            'id' => $proof->id,
            'source' => 'Manual proof',
            'sbo' => $sbo,
            'amount' => (int) ($sbo?->calculated_fee ?? 0),
            'currency' => '',
            'reference' => $sbo?->payment_reference,
            'status' => $proof->status,
            'submitted_at' => $sbo?->payment_submitted_at ?? $proof->created_at,
            'proof_url' => route('payment-proofs.download', $proof),
            'can_review' => $sbo && ! $sbo->feesPaid() && $proof->isPending(),
        ];
    }

    private function paymentRowForTransaction(SboPaymentTransaction $transaction): array
    {
        $sbo = $transaction->sbo;

        return [
            'type' => 'transaction',
            'id' => $transaction->id,
            'source' => 'Espees',
            'sbo' => $sbo,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'reference' => $transaction->provider_reference ?: $transaction->local_reference,
            'status' => $transaction->review_status,
            'submitted_at' => $transaction->submitted_at ?? $transaction->updated_at,
            'proof_url' => null,
            'can_review' => $sbo && ! $sbo->feesPaid() && $transaction->isPendingReview(),
        ];
    }

    private function timelineFor(Sbo $sbo, int $pendingDocuments): array
    {
        return [
            [
                'key' => 'registry',
                'label' => 'Registry',
                'done' => $sbo->isApproved(),
                'current' => ! $sbo->isApproved(),
                'meta' => $sbo->date_approved ? 'Approved ' . $sbo->date_approved->format('d M Y') : 'Awaiting registry approval',
                'revoke' => null,
            ],
            [
                'key' => 'fees',
                'label' => 'Payment',
                'done' => $sbo->feesPaid(),
                'current' => $sbo->isApproved() && ! $sbo->feesPaid(),
                'meta' => $sbo->feesPaid()
                    ? 'Confirmed ' . $sbo->payment_confirmed_at?->format('d M Y')
                    : ($sbo->hasSubmittedPayment() ? 'Submitted, awaiting review' : 'Not submitted'),
                'confirm' => ! $sbo->feesPaid() ? 'fees' : null,
                'confirm_label' => 'Confirm payment',
                'revoke' => $sbo->feesPaid() ? 'fees' : null,
                'revoke_label' => 'Revoke payment',
            ],
            [
                'key' => 'documents',
                'label' => 'Documents',
                'done' => $sbo->documentsCompliant(),
                'current' => $sbo->feesPaid() && ! $sbo->documentsCompliant(),
                'meta' => $sbo->documentsCompliant()
                    ? 'Compliant ' . $sbo->documents_compliant_at?->format('d M Y')
                    : ($pendingDocuments > 0 ? $pendingDocuments . ' document(s) pending' : 'Awaiting compliant documents'),
                'confirm' => ! $sbo->documentsCompliant() ? 'documents' : null,
                'confirm_label' => 'Mark compliant',
                'revoke' => $sbo->documentsCompliant() ? 'documents' : null,
                'revoke_label' => 'Revoke documents',
            ],
            [
                'key' => 'verified',
                'label' => 'Verified',
                'done' => null !== $sbo->verified_notified_at,
                'current' => $sbo->isFullyVerified() && null === $sbo->verified_notified_at,
                'meta' => $sbo->verified_notified_at
                    ? 'Verified ' . $sbo->verified_notified_at->format('d M Y')
                    : ($sbo->isFullyVerified() ? 'Ready to issue final verification' : 'Waiting for prior stages'),
                'confirm' => $sbo->isFullyVerified() && null === $sbo->verified_notified_at ? 'verified' : null,
                'confirm_label' => 'Issue final',
                'revoke' => $sbo->verified_notified_at ? 'verified' : null,
                'revoke_label' => 'Revoke final',
            ],
        ];
    }

    private function sortDateFor(Sbo $sbo, ?array $payment, mixed $latestDocument): mixed
    {
        return $payment['submitted_at']
            ?? $latestDocument?->updated_at
            ?? $sbo->payment_confirmed_at
            ?? $sbo->documents_compliant_at
            ?? $sbo->verified_notified_at
            ?? $sbo->updated_at;
    }
}; ?>

<div class="admin-page admin-approvals">
    <style>
        .approval-flow-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
        }
        .approval-flow-steps {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .75rem;
        }
        .approval-flow-step {
            min-height: 104px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: .75rem;
            background: #f8fafc;
        }
        .approval-flow-step--done {
            background: #ecfdf3;
            border-color: #bbf7d0;
        }
        .approval-flow-step--current {
            background: #fff8e5;
            border-color: #fde68a;
        }
        .approval-flow-step__dot {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid currentColor;
            font-size: .8rem;
        }
        @media (max-width: 767.98px) {
            .approval-flow-steps {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <x-ui.page-header
        title="Validation approvals"
        eyebrow="SBO operations"
        description="Track SBO validation across payment, documents, and final verification from one workflow view."
    >
        <x-slot:meta>
            <span>Active queue: <strong>{{ $counts['active'] ?? 0 }}</strong></span>
            <span>Use the stage filters to focus the review.</span>
        </x-slot:meta>
        <x-slot:actions>
            <x-live-search
                model="search"
                :value="$search"
                label="Search validation approvals"
                placeholder="Search SBO, owner, application ID"
                min-width="260px"
                class="mb-0" />
        </x-slot:actions>
    </x-ui.page-header>
    <x-ui.flash />

    <div class="btn-group btn-group-sm flex-wrap mb-4" role="group" aria-label="Validation approval filters">
        @foreach([
            'active' => 'Active',
            'payment' => 'Payments',
            'documents' => 'Documents',
            'verified' => 'Verified',
            'all' => 'All',
        ] as $key => $label)
            <button
                type="button"
                class="btn {{ $stageFilter === $key ? 'btn-primary' : 'btn-outline-primary' }}"
                wire:click="$set('stageFilter', '{{ $key }}')">
                {{ $label }} <span class="badge text-bg-light ms-1">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="vstack gap-3" wire:loading.class.delay="opacity-50" wire:target="search,clearSearch,stageFilter,gotoPage,nextPage,previousPage">
        @forelse($approvalRows as $row)
            @php
                $sbo = $row['sbo'];
                $payment = $row['payment'];
            @endphp
            <article class="approval-flow-card p-3">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h2 class="h6 mb-0">{{ $sbo->name }}</h2>
                            <span class="badge border {{ $row['stage_class'] }}">{{ $row['stage_label'] }}</span>
                        </div>
                        <div class="small text-muted">
                            App ID: {{ $sbo->application_id ?? '-' }}
                            {{--
                                The applicant's own company registration number.
                                A reviewer was being asked to approve a business
                                without the one field that identifies it in a
                                companies register — it is collected at
                                registration and printed on the certificate, but
                                was never shown in the queue.
                            --}}
                            @if($sbo->registration_number)
                                <span class="mx-1">&middot;</span>Reg No: {{ $sbo->registration_number }}
                            @endif
                            @if($sbo->moc_registration_number)
                                <span class="mx-1">&middot;</span>MOC: {{ $sbo->moc_registration_number }}
                            @endif
                        </div>
                        @if($sbo->trading_name || $sbo->industry)
                            <div class="small text-muted">
                                @if($sbo->trading_name)Trading as {{ $sbo->trading_name }}@endif
                                @if($sbo->trading_name && $sbo->industry)<span class="mx-1">&middot;</span>@endif
                                @if($sbo->industry){{ $sbo->industry }}@endif
                            </div>
                        @endif
                        <div class="small text-muted">
                            {{ $row['owner']?->name ?? 'No owner' }} <span class="mx-1">&middot;</span> {{ $row['owner']?->email ?? $sbo->primary_contact_email ?? '-' }}
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="{{ route('admin.sbos.show', $sbo) }}" class="btn btn-sm btn-outline-primary rounded-pill">
                            Review SBO
                        </a>
                    </div>
                </div>

                <div class="approval-flow-steps">
                    @foreach($row['timeline'] as $step)
                        <div @class([
                            'approval-flow-step',
                            'approval-flow-step--done' => $step['done'],
                            'approval-flow-step--current' => $step['current'],
                        ])>
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <span class="approval-flow-step__dot">
                                        @if($step['done'])
                                            <i class="bi bi-check-lg"></i>
                                        @elseif($step['current'])
                                            <i class="bi bi-hourglass-split"></i>
                                        @else
                                            <i class="bi bi-circle"></i>
                                        @endif
                                    </span>
                                </div>
                                @if($canAdjust)
                                    <div class="d-flex gap-1 flex-wrap justify-content-end">
                                        @if($step['confirm'] ?? null)
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-success rounded-pill"
                                                wire:click="confirmStep({{ $sbo->id }}, '{{ $step['confirm'] }}')"
                                                wire:confirm="{{ $step['confirm_label'] ?? 'Confirm step' }} for {{ $sbo->name }}?">
                                                {{ $step['confirm_label'] ?? 'Confirm step' }}
                                            </button>
                                        @endif
                                        @if($step['revoke'] ?? null)
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger rounded-pill"
                                                wire:click="revokeStep({{ $sbo->id }}, '{{ $step['revoke'] }}')"
                                                wire:confirm="Revoke {{ strtolower($step['label']) }} for {{ $sbo->name }}?">
                                                {{ $step['revoke_label'] ?? 'Revoke step' }}
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <div class="fw-semibold small mt-2">{{ $step['label'] }}</div>
                            <div class="small text-muted">{{ $step['meta'] }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mt-3 pt-3 border-top">
                    <div class="small text-muted">
                        @if($payment)
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle me-1">Payment pending</span>
                            {{ $payment['source'] }} <span class="mx-1">&middot;</span> {{ number_format((int) $payment['amount']) }} {{ $payment['currency'] }}
                            @if($payment['reference'])
                                <span class="mx-1">&middot;</span> Ref: {{ $payment['reference'] }}
                            @endif
                            @if($payment['proof_url'])
                                <span class="mx-1">&middot;</span> <a href="{{ $payment['proof_url'] }}" target="_blank">View proof</a>
                            @endif
                        @elseif($row['pending_documents'] > 0)
                            <span class="badge bg-info-subtle text-info border border-info-subtle me-1">{{ $row['pending_documents'] }} document(s) pending</span>
                            Review uploaded documents on the SBO detail page.
                        @else
                            Last updated {{ $sbo->updated_at?->format('d M Y, H:i') }}
                        @endif
                    </div>

                    @if($payment && $payment['can_review'])
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-success rounded-pill" wire:click="openApproveModal({{ $payment['id'] }}, '{{ $payment['type'] }}')">
                                Approve payment
                            </button>
                            <button class="btn btn-sm btn-outline-danger rounded-pill" wire:click="openRejectModal({{ $payment['id'] }}, '{{ $payment['type'] }}')">
                                Reject
                            </button>
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    No SBOs match this approval filter.
                </div>
            </div>
        @endforelse
    </div>

    <div class="mt-3">
        {{ $approvalRows->links() }}
    </div>

    <div class="modal fade" id="paymentApprovalModal" tabindex="-1" aria-labelledby="paymentApprovalModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="approveSelectedPayment">
                    <div class="modal-header">
                        <h5 class="modal-title" id="paymentApprovalModalLabel">Approve payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @if($selectedPayment && $selectedPayment['sbo'])
                            <p class="small text-muted mb-2">
                                Confirm that the {{ strtolower($selectedPayment['source']) }} payment for <strong>{{ $selectedPayment['sbo']->name }}</strong> has been reviewed and received.
                            </p>
                            <div class="small"><strong>Application ID:</strong> {{ $selectedPayment['sbo']->application_id }}</div>
                            <div class="small"><strong>Amount:</strong> {{ number_format((int) $selectedPayment['amount']) }} {{ $selectedPayment['currency'] }}</div>
                            @if($selectedPayment['reference'])
                                <div class="small"><strong>Reference:</strong> {{ $selectedPayment['reference'] }}</div>
                            @endif
                        @else
                            <p class="small text-muted mb-0">Select a payment to approve.</p>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success rounded-pill">Confirm approval</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="paymentRejectModal" tabindex="-1" aria-labelledby="paymentRejectModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="rejectSelectedPayment">
                    <div class="modal-header">
                        <h5 class="modal-title" id="paymentRejectModalLabel">Reject payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">Provide a reason so the submitted payment can be corrected.</p>
                        <label class="form-label" for="approvalRejectionReason">Reason for rejection</label>
                        <textarea id="approvalRejectionReason" wire:model="rejectionReason" class="form-control" rows="4" placeholder="Reason for rejection"></textarea>
                        @error('rejectionReason') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill">Confirm rejection</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @script
    <script>
        const paymentApprovalModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentApprovalModal'));
        const paymentRejectModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentRejectModal'));

        $wire.on('open-payment-approval-modal', () => paymentApprovalModal().show());
        $wire.on('close-payment-approval-modal', () => paymentApprovalModal().hide());
        $wire.on('open-payment-reject-modal', () => paymentRejectModal().show());
        $wire.on('close-payment-reject-modal', () => paymentRejectModal().hide());
    </script>
    @endscript
</div>
