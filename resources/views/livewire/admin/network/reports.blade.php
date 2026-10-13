<?php

use App\Models\SboNetworkReport;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Network Reports - Admin Dashboard',
        'dataPage' => 'network-reports',
        'pageTitle' => 'Network Reports',
    ]
)] class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $statusFilter = SboNetworkReport::STATUS_OPEN;
    public ?int $selectedId = null;

    public function mount(): void
    {
        $this->selectedId = SboNetworkReport::latest()->value('id');
    }

    public function setStatusFilter(string $status): void
    {
        $allowed = ['all', SboNetworkReport::STATUS_OPEN, SboNetworkReport::STATUS_REVIEWING, SboNetworkReport::STATUS_RESOLVED];
        $this->statusFilter = in_array($status, $allowed, true) ? $status : SboNetworkReport::STATUS_OPEN;
        $this->resetPage();
    }

    public function select(int $id): void
    {
        $this->selectedId = SboNetworkReport::findOrFail($id)->id;
    }

    public function markReviewing(): void
    {
        $this->selectedReport()->update([
            'status' => SboNetworkReport::STATUS_REVIEWING,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        session()->flash('success', 'Report marked as under review.');
    }

    public function resolve(): void
    {
        $this->selectedReport()->update([
            'status' => SboNetworkReport::STATUS_RESOLVED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        session()->flash('success', 'Report resolved.');
    }

    public function with(): array
    {
        $query = SboNetworkReport::with([
            'reporterSbo.creator',
            'reportedSbo.creator',
            'purchaseOrder',
            'message.sbo',
            'reviewer',
        ])->latest();

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        $reports = $query->paginate(12);
        $selected = $this->selectedId
            ? SboNetworkReport::with([
                'reporterSbo.creator',
                'reportedSbo.creator',
                'purchaseOrder',
                'message.sbo',
                'reviewer',
            ])->find($this->selectedId)
            : null;

        return [
            'reports' => $reports,
            'selected' => $selected,
            'counts' => [
                'all' => SboNetworkReport::count(),
                SboNetworkReport::STATUS_OPEN => SboNetworkReport::where('status', SboNetworkReport::STATUS_OPEN)->count(),
                SboNetworkReport::STATUS_REVIEWING => SboNetworkReport::where('status', SboNetworkReport::STATUS_REVIEWING)->count(),
                SboNetworkReport::STATUS_RESOLVED => SboNetworkReport::where('status', SboNetworkReport::STATUS_RESOLVED)->count(),
            ],
        ];
    }

    private function selectedReport(): SboNetworkReport
    {
        return SboNetworkReport::findOrFail($this->selectedId);
    }
};
?>

<div>
    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <div class="d-flex justify-content-between gap-3 flex-wrap align-items-start mb-4">
        <div>
            <h2 class="h5 mb-1">Network moderation reports</h2>
            <p class="text-muted mb-0">Review reports about connection requests, messages, suspicious bidding, and spam.</p>
        </div>

        <div class="d-flex gap-2 flex-wrap justify-content-end">
            @foreach([
                \App\Models\SboNetworkReport::STATUS_OPEN => 'Open',
                \App\Models\SboNetworkReport::STATUS_REVIEWING => 'Reviewing',
                \App\Models\SboNetworkReport::STATUS_RESOLVED => 'Resolved',
                'all' => 'All',
            ] as $key => $label)
                <button
                    type="button"
                    wire:click="setStatusFilter('{{ $key }}')"
                    class="btn btn-sm {{ $statusFilter === $key ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill"
                >
                    {{ $label }}
                    <span class="badge bg-light text-dark ms-1">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Reporter</th>
                                <th>Reported</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($reports as $report)
                                <tr class="{{ $selected?->id === $report->id ? 'table-active' : '' }}">
                                    <td>
                                        <div class="fw-semibold">{{ $report->reporterSbo?->name ?? 'Unknown SBO' }}</div>
                                        <div class="small text-muted">{{ $report->reporterSbo?->creator?->email }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $report->reportedSbo?->name ?? 'Message or bid report' }}</div>
                                        <div class="small text-muted">{{ $report->purchaseOrder?->title }}</div>
                                    </td>
                                    <td>{{ $report->reason }}</td>
                                    <td>
                                        @php
                                            $badge = match($report->status) {
                                                \App\Models\SboNetworkReport::STATUS_RESOLVED => 'bg-success-subtle text-success',
                                                \App\Models\SboNetworkReport::STATUS_REVIEWING => 'bg-info-subtle text-info',
                                                default => 'bg-warning-subtle text-warning',
                                            };
                                        @endphp
                                        <span class="badge {{ $badge }} border">{{ ucfirst($report->status) }}</span>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary rounded-pill" wire:click="select({{ $report->id }})">Open</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No network reports found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($reports->hasPages())
                <div class="mt-3">{{ $reports->links() }}</div>
            @endif
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    @if($selected)
                        <div class="d-flex justify-content-between gap-3 align-items-start mb-3">
                            <div>
                                <h2 class="h5 mb-1">{{ $selected->reason }}</h2>
                                <div class="small text-muted">Submitted {{ $selected->created_at->format('M d, Y H:i') }}</div>
                            </div>
                            <span class="badge bg-light text-dark">{{ ucfirst($selected->status) }}</span>
                        </div>

                        <dl class="row small mb-3">
                            <dt class="col-4">Reporter</dt>
                            <dd class="col-8">{{ $selected->reporterSbo?->name ?? 'Unknown SBO' }}</dd>

                            <dt class="col-4">Reported SBO</dt>
                            <dd class="col-8">{{ $selected->reportedSbo?->name ?? 'Not specified' }}</dd>

                            <dt class="col-4">RFQ</dt>
                            <dd class="col-8">{{ $selected->purchaseOrder?->title ?? 'Not linked' }}</dd>

                            <dt class="col-4">Message</dt>
                            <dd class="col-8">
                                @if($selected->message)
                                    <div class="border rounded p-2">
                                        <div class="small text-muted">{{ $selected->message->sbo?->name }} wrote:</div>
                                        {{ $selected->message->body }}
                                    </div>
                                @else
                                    Not linked
                                @endif
                            </dd>

                            <dt class="col-4">Reviewed by</dt>
                            <dd class="col-8">{{ $selected->reviewer?->name ?? 'Not reviewed' }}</dd>
                        </dl>

                        @if($selected->details)
                            <div class="mb-3">
                                <div class="small text-muted mb-1">Reporter details</div>
                                <div class="border rounded p-3">{{ $selected->details }}</div>
                            </div>
                        @endif

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-outline-primary rounded-pill" wire:click="markReviewing">
                                Mark Reviewing
                            </button>
                            <button type="button" class="btn btn-success rounded-pill" wire:click="resolve">
                                Resolve
                            </button>
                        </div>
                    @else
                        <div class="text-center text-muted py-5">Select a report to review.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
