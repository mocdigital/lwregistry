<?php

use App\Models\Sbo;
use App\Support\AdminCapabilities;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Info Requests – Admin Dashboard', 'dataPage' => 'approvals-info-requests', 'pageTitle' => 'Info Requests'])] class extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $statusFilter = 'open';

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
                'open' => $this->baseSbosQuery()->whereNull('resubmitted_at')->count(),
                'resubmitted' => $this->baseSbosQuery()->whereNotNull('resubmitted_at')->count(),
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
            ->when('open' === $this->statusFilter, fn ($query) => $query->whereNull('resubmitted_at'))
            ->when('resubmitted' === $this->statusFilter, fn ($query) => $query->whereNotNull('resubmitted_at'));
    }

    private function baseSbosQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Sbo::query()
            ->with(['creator'])
            ->where('verification_status', 'info_requested')
            ->latest('updated_at');
    }
}; ?>

<div>
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <p class="text-muted mb-0">Track SBO applications where admins requested more information and see which owners have resubmitted updates.</p>

        <div class="btn-group btn-group-sm" role="group" aria-label="Info request filters">
            @foreach(['open' => 'Open', 'resubmitted' => 'Resubmitted', 'all' => 'All'] as $key => $label)
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
                            <th>Request</th>
                            <th>Resubmitted</th>
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
                                <td>
                                    <div class="small">{{ $sbo->info_request ?: 'No message recorded.' }}</div>
                                    <div class="small text-muted mt-1">Updated {{ $sbo->updated_at?->format('d M Y, H:i') }}</div>
                                </td>
                                <td>
                                    @if($sbo->resubmitted_at)
                                        <span class="badge border bg-success-subtle text-success border-success-subtle">Resubmitted</span>
                                        <div class="small text-muted mt-1">{{ $sbo->resubmitted_at->format('d M Y, H:i') }}</div>
                                    @else
                                        <span class="badge border bg-info-subtle text-info border-info-subtle">Awaiting owner</span>
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
                                <td colspan="5" class="text-center text-muted py-4">No info-requested SBOs found for this filter.</td>
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
