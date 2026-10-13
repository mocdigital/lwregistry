<?php

use App\Models\SboPaymentProof;
use App\Models\SboPaymentTransaction;
use App\Support\AdminCapabilities;
use App\Support\SboPaymentProofReviewer;
use App\Support\SboPaymentTransactionReviewer;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Payment Approvals – Admin Dashboard', 'dataPage' => 'approvals-payments', 'pageTitle' => 'Payment Approvals'])] class extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $statusFilter = 'pending';

    public ?int $selectedPaymentId = null;

    public string $selectedPaymentType = 'proof';

    public string $rejectionReason = '';

    public function mount(): void
    {
        $this->authorizePaymentReview();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openApproveModal(int $paymentId, string $type = 'proof'): void
    {
        $this->authorizePaymentReview();
        $this->selectedPaymentId = $paymentId;
        $this->selectedPaymentType = $type;
        $this->dispatch('open-payment-approval-modal');
    }

    public function approveSelectedPayment(): void
    {
        $this->authorizePaymentReview();

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
        $this->authorizePaymentReview();
        $this->selectedPaymentId = $paymentId;
        $this->selectedPaymentType = $type;
        $this->rejectionReason = '';
        $this->dispatch('open-payment-reject-modal');
    }

    public function rejectSelectedPayment(): void
    {
        $this->authorizePaymentReview();

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

    public function with(): array
    {
        return [
            'payments' => $this->paginatedPaymentRows(),
            'selectedPayment' => $this->selectedPayment(),
            'counts' => [
                'pending' => $this->paymentRows('pending')->count(),
                'approved' => $this->paymentRows('approved')->count(),
                'rejected' => $this->paymentRows('rejected')->count(),
                'all' => $this->paymentRows('all')->count(),
            ],
        ];
    }

    private function authorizePaymentReview(): void
    {
        abort_unless(auth()->user()?->hasPermission(AdminCapabilities::APPROVE_SBOS), 403);
    }

    private function selectedPayment(): ?array
    {
        if (null === $this->selectedPaymentId) {
            return null;
        }

        if ('transaction' === $this->selectedPaymentType) {
            $transaction = $this->baseTransactionsQuery()->whereKey($this->selectedPaymentId)->first();

            return $transaction ? $this->transactionRow($transaction) : null;
        }

        $proof = $this->baseProofsQuery()->where('sbo_payment_proofs.id', $this->selectedPaymentId)->first();

        return $proof ? $this->proofRow($proof) : null;
    }

    private function selectedProofOrFail(): SboPaymentProof
    {
        return $this->baseProofsQuery()
            ->where('sbo_payment_proofs.id', $this->selectedPaymentId)
            ->firstOrFail();
    }

    private function selectedTransactionOrFail(): SboPaymentTransaction
    {
        return $this->baseTransactionsQuery()
            ->whereKey($this->selectedPaymentId)
            ->firstOrFail();
    }

    private function paginatedPaymentRows(): LengthAwarePaginator
    {
        $rows = $this->paymentRows($this->statusFilter);
        $pageName = 'page';
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);
        $perPage = 15;

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => $pageName],
        );
    }

    private function paymentRows(string $status): Collection
    {
        return $this->proofRows($status)
            ->merge($this->transactionRows($status))
            ->sortByDesc(fn (array $row) => $row['sort_at']?->timestamp ?? 0)
            ->values();
    }

    private function proofRows(string $status): Collection
    {
        return $this->baseProofsQuery()
            ->when('all' !== $status, fn ($query) => $query->where('sbo_payment_proofs.status', $status))
            ->get()
            ->map(fn (SboPaymentProof $proof): array => $this->proofRow($proof))
            ->toBase();
    }

    private function transactionRows(string $status): Collection
    {
        return $this->baseTransactionsQuery()
            ->when('all' !== $status, fn ($query) => $query->where('review_status', $status))
            ->get()
            ->map(fn (SboPaymentTransaction $transaction): array => $this->transactionRow($transaction))
            ->toBase();
    }

    private function baseProofsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return SboPaymentProof::query()
            ->select('sbo_payment_proofs.*')
            ->join('sbos', 'sbos.id', '=', 'sbo_payment_proofs.sbo_id')
            ->with(['sbo.creator', 'reviewer', 'media'])
            ->whereHas('media', fn ($query) => $query->where('collection_name', SboPaymentProof::COLLECTION));
    }

    private function baseTransactionsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return SboPaymentTransaction::query()
            ->with(['sbo.creator', 'reviewer'])
            ->where('provider', 'espees')
            ->whereIn('review_status', ['pending', 'approved', 'rejected']);
    }

    private function proofRow(SboPaymentProof $proof): array
    {
        $sbo = $proof->sbo;

        return [
            'type' => 'proof',
            'id' => $proof->id,
            'source' => 'Manual proof',
            'sbo' => $sbo,
            'owner' => $sbo?->creator,
            'amount' => (int) ($sbo?->calculated_fee ?? 0),
            'currency' => '',
            'reference' => $sbo?->payment_reference,
            'status' => $proof->status,
            'reviewer' => $proof->reviewer,
            'submitted_at' => $sbo?->payment_submitted_at ?? $proof->created_at,
            'sort_at' => $proof->reviewed_at ?? $sbo?->payment_submitted_at ?? $proof->updated_at,
            'proof_url' => route('payment-proofs.download', $proof),
            'payload' => null,
            'can_review' => $sbo && ! $sbo->feesPaid() && ! $proof->isApproved(),
        ];
    }

    private function transactionRow(SboPaymentTransaction $transaction): array
    {
        $sbo = $transaction->sbo;

        return [
            'type' => 'transaction',
            'id' => $transaction->id,
            'source' => 'Espees',
            'sbo' => $sbo,
            'owner' => $sbo?->creator,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'reference' => $transaction->provider_reference ?: $transaction->local_reference,
            'status' => $transaction->review_status,
            'reviewer' => $transaction->reviewer,
            'submitted_at' => $transaction->submitted_at ?? $transaction->updated_at,
            'sort_at' => $transaction->reviewed_at ?? $transaction->submitted_at ?? $transaction->updated_at,
            'proof_url' => null,
            'payload' => $transaction->raw_payload,
            'can_review' => $sbo && ! $sbo->feesPaid() && $transaction->isPendingReview(),
        ];
    }
}; ?>

<div>
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
    @endif

    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <div>
            <p class="text-muted mb-0">Review submitted SBO registration payments, including server-verified Espees transactions and manual proof uploads.</p>
        </div>
        <div class="btn-group btn-group-sm" role="group" aria-label="Payment approval filters">
            @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label)
                <button
                    type="button"
                    class="btn {{ $statusFilter === $key ? 'btn-primary' : 'btn-outline-primary' }}"
                    wire:click="$set('statusFilter', '{{ $key }}')">
                    {{ $label }} <span class="badge text-bg-light ms-1">{{ $counts[$key] ?? 0 }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="card">
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>SBO</th>
                            <th>Owner</th>
                            <th>Payment</th>
                            <th>Submission</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payments as $payment)
                            @php
                                $sbo = $payment['sbo'];
                                $owner = $payment['owner'];
                                $statusClass = match($payment['status']) {
                                    'approved' => 'bg-success-subtle text-success border-success-subtle',
                                    'rejected' => 'bg-danger-subtle text-danger border-danger-subtle',
                                    default => 'bg-warning-subtle text-warning border-warning-subtle',
                                };
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $sbo?->name ?? 'Unknown SBO' }}</div>
                                    <div class="small text-muted">Application ID: {{ $sbo?->application_id ?? '—' }}</div>
                                    <a href="{{ $sbo ? route('admin.sbos.show', $sbo) : '#' }}" class="small">View SBO details</a>
                                </td>
                                <td>
                                    <div class="small fw-semibold">{{ $owner?->name ?? '—' }}</div>
                                    <div class="small text-muted">{{ $owner?->email ?? $sbo?->primary_contact_email ?? '—' }}</div>
                                </td>
                                <td>
                                    <div class="fw-semibold">
                                        {{ number_format((int) $payment['amount']) }}@if($payment['currency']) {{ $payment['currency'] }}@endif
                                    </div>
                                    <span class="badge bg-light text-dark border">{{ $payment['source'] }}</span>
                                    @if($sbo?->fee_band)
                                        <div class="small text-muted">{{ $sbo->fee_band }}</div>
                                    @endif
                                    @if($payment['reference'])
                                        <div class="small text-muted">Ref: {{ $payment['reference'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if($payment['proof_url'])
                                        <a href="{{ $payment['proof_url'] }}" target="_blank" class="small">
                                            <i class="bi bi-paperclip"></i> View proof
                                        </a>
                                    @else
                                        <div class="small">Gateway redirect received</div>
                                        @if($payment['payload'])
                                            <details class="small text-muted">
                                                <summary>Payload</summary>
                                                <pre class="small bg-light border rounded p-2 mt-1 mb-0">{{ json_encode($payment['payload'], JSON_PRETTY_PRINT) }}</pre>
                                            </details>
                                        @endif
                                    @endif
                                    <div class="small text-muted">{{ $payment['submitted_at']?->format('d M Y, H:i') }}</div>
                                </td>
                                <td>
                                    <span class="badge border {{ $statusClass }}">{{ ucfirst($payment['status']) }}</span>
                                    @if($payment['reviewer'])
                                        <div class="small text-muted mt-1">By {{ $payment['reviewer']->name }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if($payment['can_review'])
                                        <button class="btn btn-sm btn-success rounded-pill" wire:click="openApproveModal({{ $payment['id'] }}, '{{ $payment['type'] }}')">
                                            Approve
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger rounded-pill" wire:click="openRejectModal({{ $payment['id'] }}, '{{ $payment['type'] }}')">
                                            Reject
                                        </button>
                                    @else
                                        <span class="text-muted small">No action</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No payments found for this filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                {{ $payments->links() }}
            </div>
        </div>
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
                        <label class="form-label" for="paymentRejectionReason">Reason for rejection</label>
                        <textarea id="paymentRejectionReason" wire:model="rejectionReason" class="form-control" rows="4" placeholder="Reason for rejection"></textarea>
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
