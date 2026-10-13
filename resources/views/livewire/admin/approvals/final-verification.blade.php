<?php

use App\Models\Sbo;
use App\Support\AdminCapabilities;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Final Verification – Admin Dashboard', 'dataPage' => 'approvals-final-verification', 'pageTitle' => 'Final Verification'])] class extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $statusFilter = 'ready';

    public function mount(): void
    {
        $this->authorizeReview();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'sbos' => $this->sbosQuery()->paginate(15),
            'counts' => [
                'ready' => $this->baseSbosQuery()->whereNull('verified_notified_at')->count(),
                'verified' => $this->baseSbosQuery()->whereNotNull('verified_notified_at')->count(),
                'all' => $this->baseSbosQuery()->count(),
            ],
        ];
    }

    private function authorizeReview(): void
    {
        abort_unless(auth()->user()?->hasPermission(AdminCapabilities::APPROVE_SBOS), 403);
    }

    private function sbosQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->baseSbosQuery()
            ->when('ready' === $this->statusFilter, fn ($query) => $query->whereNull('verified_notified_at'))
            ->when('verified' === $this->statusFilter, fn ($query) => $query->whereNotNull('verified_notified_at'));
    }

    private function baseSbosQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Sbo::query()
            ->with(['creator', 'approver'])
            ->where('verification_status', 'approved')
            ->where('documents_status', 'compliant')
            ->where('payment_status', 'paid')
            ->whereNotNull('payment_confirmed_at')
            ->latest('payment_confirmed_at');
    }
}; ?>

<div>
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <p class="text-muted mb-0">Track SBOs that have application approval, compliant documents, and approved payment, then confirm final verification status.</p>

        <div class="btn-group btn-group-sm" role="group" aria-label="Final verification filters">
            @foreach(['ready' => 'Ready', 'verified' => 'Verified', 'all' => 'All'] as $key => $label)
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
                            <th>Payment confirmed</th>
                            <th>MOC number</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sbos as $sbo)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $sbo->name }}</div>
                                    <div class="small text-muted">Application ID: {{ $sbo->application_id ?? '—' }}</div>
                                </td>
                                <td>
                                    <div class="small fw-semibold">{{ $sbo->creator?->name ?? '—' }}</div>
                                    <div class="small text-muted">{{ $sbo->creator?->email ?? $sbo->primary_contact_email ?? '—' }}</div>
                                </td>
                                <td>{{ $sbo->payment_confirmed_at?->format('d M Y, H:i') ?? '—' }}</td>
                                <td>{{ $sbo->moc_registration_number ?? 'Pending assignment' }}</td>
                                <td>
                                    @if($sbo->verified_notified_at)
                                        <span class="badge border bg-success-subtle text-success border-success-subtle">Verified</span>
                                        <div class="small text-muted mt-1">{{ $sbo->verified_notified_at->format('d M Y, H:i') }}</div>
                                    @else
                                        <span class="badge border bg-warning-subtle text-warning border-warning-subtle">Ready</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('admin.sbos.show', $sbo) }}" class="btn btn-sm btn-primary rounded-pill">
                                        Review SBO
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No SBOs found for this final verification filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                {{ $sbos->links() }}
            </div>
        </div>
    </div>
</div>
