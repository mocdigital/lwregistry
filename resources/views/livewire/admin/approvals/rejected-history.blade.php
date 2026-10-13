<?php

use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Models\SboPaymentProof;
use App\Support\AdminCapabilities;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.admin', ['title' => 'Rejected History – Admin Dashboard', 'dataPage' => 'approvals-rejected-history', 'pageTitle' => 'Rejected History'])] class extends Component
{
    public string $typeFilter = 'all';

    public function mount(): void
    {
        $this->authorizeReview();
    }

    public function with(): array
    {
        return [
            'items' => $this->historyItems(),
            'counts' => [
                'applications' => Sbo::query()->where('verification_status', 'declined')->count(),
                'payments' => SboPaymentProof::query()->where('status', 'rejected')->count(),
                'documents' => SboComplianceDocument::query()->where('status', 'rejected')->count(),
            ],
        ];
    }

    private function authorizeReview(): void
    {
        abort_unless(auth()->user()?->hasPermission(AdminCapabilities::APPROVE_SBOS), 403);
    }

    private function historyItems(): Collection
    {
        $items = collect();

        if (in_array($this->typeFilter, ['all', 'applications'], true)) {
            Sbo::query()
                ->with('creator')
                ->where('verification_status', 'declined')
                ->latest('updated_at')
                ->limit(50)
                ->get()
                ->each(fn (Sbo $sbo) => $items->push([
                    'type' => 'Application',
                    'title' => $sbo->name,
                    'application_id' => $sbo->application_id,
                    'owner' => $sbo->creator?->name,
                    'email' => $sbo->creator?->email ?? $sbo->primary_contact_email,
                    'reason' => $sbo->decline_reason,
                    'date' => $sbo->updated_at,
                    'url' => route('admin.sbos.show', $sbo),
                ]));
        }

        if (in_array($this->typeFilter, ['all', 'payments'], true)) {
            SboPaymentProof::query()
                ->with(['sbo.creator', 'reviewer'])
                ->where('status', 'rejected')
                ->latest('reviewed_at')
                ->limit(50)
                ->get()
                ->each(fn (SboPaymentProof $proof) => $items->push([
                    'type' => 'Payment',
                    'title' => $proof->sbo?->name ?? 'Unknown SBO',
                    'application_id' => $proof->sbo?->application_id,
                    'owner' => $proof->sbo?->creator?->name,
                    'email' => $proof->sbo?->creator?->email ?? $proof->sbo?->primary_contact_email,
                    'reason' => $proof->rejection_reason,
                    'date' => $proof->reviewed_at ?? $proof->updated_at,
                    'url' => $proof->sbo ? route('admin.sbos.show', $proof->sbo) : null,
                ]));
        }

        if (in_array($this->typeFilter, ['all', 'documents'], true)) {
            SboComplianceDocument::query()
                ->with(['sbo.creator', 'type', 'reviewer'])
                ->where('status', 'rejected')
                ->latest('reviewed_at')
                ->limit(50)
                ->get()
                ->each(fn (SboComplianceDocument $document) => $items->push([
                    'type' => 'Document',
                    'title' => ($document->sbo?->name ?? 'Unknown SBO') . ' — ' . ($document->type?->name ?? 'Document'),
                    'application_id' => $document->sbo?->application_id,
                    'owner' => $document->sbo?->creator?->name,
                    'email' => $document->sbo?->creator?->email ?? $document->sbo?->primary_contact_email,
                    'reason' => $document->rejection_reason,
                    'date' => $document->reviewed_at ?? $document->updated_at,
                    'url' => $document->sbo ? route('admin.sbos.show', $document->sbo) : null,
                ]));
        }

        return $items
            ->sortByDesc(fn (array $item) => optional($item['date'])->timestamp ?? 0)
            ->take(100)
            ->values();
    }
}; ?>

<div>
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <p class="text-muted mb-0">Audit rejected applications, payment proofs, and compliance documents from one place.</p>

        <div class="btn-group btn-group-sm" role="group" aria-label="Rejected history filters">
            @foreach(['all' => 'All', 'applications' => 'Applications', 'payments' => 'Payments', 'documents' => 'Documents'] as $key => $label)
                <button
                    type="button"
                    class="btn {{ $typeFilter === $key ? 'btn-primary' : 'btn-outline-primary' }}"
                    wire:click="$set('typeFilter', '{{ $key }}')">
                    {{ $label }}
                    @if($key !== 'all')
                        <span class="badge text-bg-light ms-1">{{ $counts[$key] ?? 0 }}</span>
                    @endif
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
                            <th>Type</th>
                            <th>Record</th>
                            <th>Owner</th>
                            <th>Reason</th>
                            <th>Date</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $item)
                            <tr>
                                <td><span class="badge border bg-danger-subtle text-danger border-danger-subtle">{{ $item['type'] }}</span></td>
                                <td>
                                    <div class="fw-semibold">{{ $item['title'] }}</div>
                                    <div class="small text-muted">Application ID: {{ $item['application_id'] ?? '—' }}</div>
                                </td>
                                <td>
                                    <div class="small fw-semibold">{{ $item['owner'] ?? '—' }}</div>
                                    <div class="small text-muted">{{ $item['email'] ?? '—' }}</div>
                                </td>
                                <td class="small">{{ $item['reason'] ?: 'No reason recorded.' }}</td>
                                <td class="small">{{ $item['date']?->format('d M Y, H:i') ?? '—' }}</td>
                                <td class="text-end">
                                    @if($item['url'])
                                        <a href="{{ $item['url'] }}" class="btn btn-sm btn-primary rounded-pill">Review SBO</a>
                                    @else
                                        <span class="text-muted small">Unavailable</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No rejected records found for this filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
