<?php

use App\Models\Sbo;
use App\Support\AdminPages;
use App\Support\SboFeeCalculator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', [
    'title' => 'SBO Payments – Admin Dashboard',
    'dataPage' => 'growth-payments',
    'pageTitle' => 'SBO Payments',
])] class extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';

    public string $methodFilter = 'all';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingMethodFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatingDateTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'methodFilter', 'dateFrom', 'dateTo']);
        $this->methodFilter = 'all';
        $this->resetPage();
    }

    public function with(): array
    {
        $query = $this->paidSbosQuery();
        $payments = $query
            ->latest('payment_confirmed_at')
            ->paginate(20);

        $payments->setCollection(
            $payments->getCollection()
                ->map(fn (Sbo $sbo): array => $this->paymentRow($sbo))
                ->values(),
        );

        $summaryQuery = $this->paidSbosQuery();
        $espeesCount = (clone $summaryQuery)
            ->whereHas('paymentTransactions', fn (Builder $query): Builder => $query
                ->where('provider', 'espees')
                ->where('review_status', 'approved'))
            ->count();
        $totalCount = (clone $summaryQuery)->count();

        return [
            'payments' => $payments,
            'summary' => [
                'total' => $totalCount,
                'espees' => $espeesCount,
                'manual' => max(0, $totalCount - $espeesCount),
            ],
        ];
    }

    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->hasAdminPage(AdminPages::GROWTH_PAYMENTS), 403);
    }

    /**
     * Only confirmed SBO payments are included. Failed, pending, rejected, and
     * unpaid payment attempts remain in the approval workflow and are not shown
     * as completed payments here.
     *
     * @return Builder<Sbo>
     */
    private function paidSbosQuery(): Builder
    {
        $search = mb_trim($this->search);

        return Sbo::query()
            ->where('payment_status', 'paid')
            ->whereNotNull('payment_confirmed_at')
            ->with([
                'creator',
                'paymentProof.reviewer',
                'paymentProof.media',
                'paymentTransactions' => fn ($query) => $query
                    ->with('reviewer')
                    ->where('provider', 'espees')
                    ->where('review_status', 'approved')
                    ->latest('id'),
            ])
            ->when('' !== $search, function (Builder $query) use ($search): void {
                $term = '%' . $search . '%';

                $query->where(function (Builder $searchQuery) use ($term): void {
                    $searchQuery
                        ->where('name', 'like', $term)
                        ->orWhere('application_id', 'like', $term)
                        ->orWhere('payment_reference', 'like', $term)
                        ->orWhere('primary_contact_email', 'like', $term)
                        ->orWhereHas('creator', function (Builder $ownerQuery) use ($term): void {
                            $ownerQuery
                                ->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term);
                        });
                });
            })
            ->when('espees' === $this->methodFilter, function (Builder $query): void {
                $query->whereHas('paymentTransactions', fn (Builder $transactionQuery): Builder => $transactionQuery
                    ->where('provider', 'espees')
                    ->where('review_status', 'approved'));
            })
            ->when('manual' === $this->methodFilter, function (Builder $query): void {
                $query->whereDoesntHave('paymentTransactions', fn (Builder $transactionQuery): Builder => $transactionQuery
                    ->where('provider', 'espees')
                    ->where('review_status', 'approved'));
            })
            ->when('' !== $this->dateFrom, fn (Builder $query): Builder => $query->whereDate('payment_confirmed_at', '>=', $this->dateFrom))
            ->when('' !== $this->dateTo, fn (Builder $query): Builder => $query->whereDate('payment_confirmed_at', '<=', $this->dateTo));
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRow(Sbo $sbo): array
    {
        $transaction = $sbo->paymentTransactions->first();
        $proof = $sbo->paymentProof;
        $isEspees = $transaction !== null;

        return [
            'sbo' => $sbo,
            'owner' => $sbo->creator,
            'method' => $isEspees ? 'Espees' : ($proof?->isApproved() ? 'Manual proof' : 'Manual confirmation'),
            'amount' => $isEspees ? (int) $transaction->amount : (int) ($sbo->calculated_fee ?? 0),
            'currency' => $isEspees && $transaction->currency
                ? $transaction->currency
                : SboFeeCalculator::currency($sbo->country_id),
            'reference' => $isEspees
                ? ($transaction->provider_reference ?: $transaction->local_reference)
                : $sbo->payment_reference,
            'confirmed_by' => $isEspees
                ? $transaction->reviewer?->name
                : $proof?->reviewer?->name,
            'confirmed_at' => $sbo->payment_confirmed_at,
            'proof_url' => $proof?->isApproved() && $proof->file()
                ? route('payment-proofs.download', $proof)
                : null,
        ];
    }
}; ?>

<div>
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <div>
            <div class="small text-uppercase text-muted fw-semibold">Growth / Revenue</div>
            <h2 class="h4 mb-1">Payment history</h2>
            <p class="text-muted mb-0" style="max-width: 680px;">
                A record of confirmed SBO registration-fee payments, including the relevant organization and owner details.
                Pending and rejected attempts remain in the validation approvals workflow.
            </p>
        </div>
        <a href="{{ route('admin.approvals.index') }}" class="btn btn-outline-primary rounded-pill" wire:navigate>
            <i class="bi bi-check2-circle me-1"></i> Payment approvals
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Confirmed payments</div>
                <div class="h3 mb-0 fw-bold">{{ number_format($summary['total']) }}</div>
                <div class="small text-muted-soft">Matching the current filters</div>
            </div></div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Espees payments</div>
                <div class="h3 mb-0 fw-bold">{{ number_format($summary['espees']) }}</div>
                <div class="small text-muted-soft">Server-confirmed gateway payments</div>
            </div></div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Manual payments</div>
                <div class="h3 mb-0 fw-bold">{{ number_format($summary['manual']) }}</div>
                <div class="small text-muted-soft">Proofs or admin confirmations</div>
            </div></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-lg-5">
                    <label for="growthPaymentSearch" class="form-label small mb-1">Search payments</label>
                    <input id="growthPaymentSearch" type="search" wire:model.live.debounce.350ms="search" class="form-control" placeholder="SBO, application ID, owner, email, or reference">
                </div>
                <div class="col-12 col-sm-4 col-lg-2">
                    <label for="growthPaymentMethod" class="form-label small mb-1">Method</label>
                    <select id="growthPaymentMethod" wire:model.live="methodFilter" class="form-select">
                        <option value="all">All methods</option>
                        <option value="espees">Espees</option>
                        <option value="manual">Manual</option>
                    </select>
                </div>
                <div class="col-6 col-sm-4 col-lg-2">
                    <label for="growthPaymentDateFrom" class="form-label small mb-1">From</label>
                    <input id="growthPaymentDateFrom" type="date" wire:model.live="dateFrom" class="form-control">
                </div>
                <div class="col-6 col-sm-4 col-lg-2">
                    <label for="growthPaymentDateTo" class="form-label small mb-1">To</label>
                    <input id="growthPaymentDateTo" type="date" wire:model.live="dateTo" class="form-control">
                </div>
                <div class="col-12 col-lg-1 d-grid">
                    <button type="button" wire:click="clearFilters" class="btn btn-outline-secondary">Clear</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Confirmed</th>
                            <th>SBO</th>
                            <th>Owner</th>
                            <th>Payment</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payments as $payment)
                            @php
                                $sbo = $payment['sbo'];
                                $owner = $payment['owner'];
                            @endphp
                            <tr>
                                <td class="small text-nowrap">
                                    <div class="fw-semibold">{{ $payment['confirmed_at']?->format('d M Y') ?? '—' }}</div>
                                    <div class="text-muted">{{ $payment['confirmed_at']?->format('H:i') ?? '—' }}</div>
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $sbo->name }}</div>
                                    <div class="small text-muted">{{ $sbo->application_id ?? 'No application ID' }}</div>
                                    <div class="small text-muted">{{ $sbo->country ?? 'Country not set' }}</div>
                                </td>
                                <td>
                                    <div class="small fw-semibold">{{ $owner?->name ?? '—' }}</div>
                                    <div class="small text-muted">{{ $owner?->email ?? $sbo->primary_contact_email ?? '—' }}</div>
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ number_format($payment['amount']) }} {{ $payment['currency'] }}</div>
                                    <span class="badge bg-light text-dark border">{{ $payment['method'] }}</span>
                                    @if($payment['confirmed_by'])
                                        <div class="small text-muted mt-1">By {{ $payment['confirmed_by'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="small text-break">{{ $payment['reference'] ?: '—' }}</span>
                                    @if($payment['proof_url'])
                                        <a href="{{ $payment['proof_url'] }}" target="_blank" class="d-block small">View proof</a>
                                    @endif
                                </td>
                                <td><span class="badge bg-success-subtle text-success border border-success-subtle">Successful</span></td>
                                <td class="text-end">
                                    <a href="{{ route('admin.sbos.show', $sbo) }}" class="btn btn-sm btn-outline-primary rounded-pill">View SBO</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">No confirmed payments match the current filters.</td>
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
</div>
