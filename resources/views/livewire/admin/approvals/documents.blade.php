<?php

use App\Models\SboComplianceDocument;
use App\Support\AdminCapabilities;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Document Approvals – Admin Dashboard', 'dataPage' => 'approvals-documents', 'pageTitle' => 'Document Approvals'])] class extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $statusFilter = 'pending';

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
            'documents' => $this->documentsQuery()->paginate(15),
            'counts' => [
                'pending' => $this->baseDocumentsQuery()->where('sbo_compliance_documents.status', 'pending')->count(),
                'approved' => $this->baseDocumentsQuery()->where('sbo_compliance_documents.status', 'approved')->count(),
                'rejected' => $this->baseDocumentsQuery()->where('sbo_compliance_documents.status', 'rejected')->count(),
                'all' => $this->baseDocumentsQuery()->count(),
            ],
        ];
    }

    private function authorizeReview(): void
    {
        abort_unless(auth()->user()?->hasPermission(AdminCapabilities::APPROVE_SBOS), 403);
    }

    private function documentsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->baseDocumentsQuery()
            ->when('all' !== $this->statusFilter, fn ($query) => $query->where('sbo_compliance_documents.status', $this->statusFilter));
    }

    private function baseDocumentsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return SboComplianceDocument::query()
            ->select('sbo_compliance_documents.*')
            ->with(['sbo.creator', 'type.country', 'reviewer', 'media'])
            ->whereHas('media', fn ($query) => $query->where('collection_name', SboComplianceDocument::COLLECTION))
            ->orderByRaw('COALESCE(sbo_compliance_documents.reviewed_at, sbo_compliance_documents.updated_at, sbo_compliance_documents.created_at) DESC');
    }
}; ?>

<div>
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <p class="text-muted mb-0">Review uploaded SBO compliance documents and jump into the SBO detail page to approve or reject each submission.</p>

        <div class="btn-group btn-group-sm" role="group" aria-label="Document approval filters">
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
                            <th>Document</th>
                            <th>SBO</th>
                            <th>Owner</th>
                            <th>Uploaded</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($documents as $document)
                            @php
                                $sbo = $document->sbo;
                                $statusClass = match($document->status) {
                                    'approved' => 'bg-success-subtle text-success border-success-subtle',
                                    'rejected' => 'bg-danger-subtle text-danger border-danger-subtle',
                                    default => 'bg-warning-subtle text-warning border-warning-subtle',
                                };
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $document->type?->name ?? 'Document' }}</div>
                                    @if($document->type?->country)
                                        <div class="small text-muted">{{ $document->type->country->name }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $sbo?->name ?? 'Unknown SBO' }}</div>
                                    <div class="small text-muted">Application ID: {{ $sbo?->application_id ?? '—' }}</div>
                                </td>
                                <td>
                                    <div class="small fw-semibold">{{ $sbo?->creator?->name ?? '—' }}</div>
                                    <div class="small text-muted">{{ $sbo?->creator?->email ?? $sbo?->primary_contact_email ?? '—' }}</div>
                                </td>
                                <td>
                                    <div class="small">{{ $document->updated_at?->format('d M Y, H:i') }}</div>
                                    @if($document->reviewer)
                                        <div class="small text-muted">Reviewed by {{ $document->reviewer->name }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge border {{ $statusClass }}">{{ ucfirst($document->status) }}</span>
                                    @if($document->isRejected() && $document->rejection_reason)
                                        <div class="small text-danger mt-1">{{ $document->rejection_reason }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('compliance-documents.download', $document) }}" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill">
                                        View file
                                    </a>
                                    @if($sbo)
                                        <a href="{{ route('admin.sbos.show', $sbo) }}" class="btn btn-sm btn-primary rounded-pill">
                                            Review SBO
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No document submissions found for this filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                {{ $documents->links() }}
            </div>
        </div>
    </div>
</div>
