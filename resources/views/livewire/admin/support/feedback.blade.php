<?php

use App\Models\FeedbackSubmission;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Feedback – Admin Dashboard',
        'dataPage' => 'support-feedback',
        'pageTitle' => 'Feedback',
    ]
)] class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $statusFilter = FeedbackSubmission::STATUS_NEW;
    public string $typeFilter = 'all';
    public ?int $selectedId = null;
    public string $adminNotes = '';

    public function mount(): void
    {
        $this->selectedId = FeedbackSubmission::latest()->value('id');
    }

    public function setStatusFilter(string $status): void
    {
        $allowed = ['all', ...array_keys(FeedbackSubmission::statusOptions())];
        $this->statusFilter = in_array($status, $allowed, true) ? $status : FeedbackSubmission::STATUS_NEW;
        $this->resetPage();
    }

    public function setTypeFilter(string $type): void
    {
        $allowed = ['all', ...array_keys(FeedbackSubmission::typeOptions())];
        $this->typeFilter = in_array($type, $allowed, true) ? $type : 'all';
        $this->resetPage();
    }

    public function select(int $id): void
    {
        $feedback = FeedbackSubmission::findOrFail($id);
        $this->selectedId = $feedback->id;
        $this->adminNotes = (string) ($feedback->admin_notes ?? '');
    }

    public function saveNotes(): void
    {
        $data = $this->validate([
            'adminNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->selectedFeedback()->update([
            'admin_notes' => $data['adminNotes'] ?: null,
        ]);

        session()->flash('success', 'Feedback notes saved.');
    }

    public function markReviewed(): void
    {
        $this->selectedFeedback()->update([
            'status' => FeedbackSubmission::STATUS_REVIEWED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        session()->flash('success', 'Feedback marked reviewed.');
    }

    public function archive(): void
    {
        $this->selectedFeedback()->update([
            'status' => FeedbackSubmission::STATUS_ARCHIVED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        session()->flash('success', 'Feedback archived.');
    }

    public function with(): array
    {
        $query = FeedbackSubmission::query()
            ->with(['user'])
            ->latest();

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->typeFilter !== 'all') {
            $query->where('feedback_type', $this->typeFilter);
        }

        $feedbackItems = $query->paginate(10);
        $selected = $this->selectedId
            ? FeedbackSubmission::with(['user', 'reviewer'])->find($this->selectedId)
            : null;

        return [
            'feedbackItems' => $feedbackItems,
            'selected' => $selected,
            'statusOptions' => FeedbackSubmission::statusOptions(),
            'typeOptions' => FeedbackSubmission::typeOptions(),
            'counts' => [
                'all' => FeedbackSubmission::count(),
                FeedbackSubmission::STATUS_NEW => FeedbackSubmission::where('status', FeedbackSubmission::STATUS_NEW)->count(),
                FeedbackSubmission::STATUS_REVIEWED => FeedbackSubmission::where('status', FeedbackSubmission::STATUS_REVIEWED)->count(),
                FeedbackSubmission::STATUS_ARCHIVED => FeedbackSubmission::where('status', FeedbackSubmission::STATUS_ARCHIVED)->count(),
            ],
        ];
    }

    private function selectedFeedback(): FeedbackSubmission
    {
        return FeedbackSubmission::findOrFail($this->selectedId);
    }
}; ?>

<div>
    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <div class="d-flex justify-content-between gap-3 flex-wrap align-items-start mb-4">
        <div>
            <h2 class="h5 mb-1">Feedback inbox</h2>
            <p class="text-muted mb-0">Review technical issues, business suggestions, and platform ideas submitted by signed-in users.</p>
        </div>

        <div class="d-flex gap-2 flex-wrap justify-content-end">
            @foreach([
                FeedbackSubmission::STATUS_NEW => 'New',
                FeedbackSubmission::STATUS_REVIEWED => 'Reviewed',
                FeedbackSubmission::STATUS_ARCHIVED => 'Archived',
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

    <div class="d-flex gap-2 flex-wrap mb-4">
        <button
            type="button"
            wire:click="setTypeFilter('all')"
            class="btn btn-sm {{ $typeFilter === 'all' ? 'btn-dark' : 'btn-outline-dark' }} rounded-pill"
        >
            All types
        </button>
        @foreach($typeOptions as $key => $label)
            <button
                type="button"
                wire:click="setTypeFilter('{{ $key }}')"
                class="btn btn-sm {{ $typeFilter === $key ? 'btn-dark' : 'btn-outline-dark' }} rounded-pill"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Submitter</th>
                                <th>Feedback</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($feedbackItems as $feedback)
                                <tr class="{{ $selected?->id === $feedback->id ? 'table-active' : '' }}">
                                    <td>
                                        <div class="fw-semibold">{{ $feedback->name }}</div>
                                        <div class="small text-muted">{{ $feedback->email }}</div>
                                        <div class="small text-muted">{{ $feedback->kc_handle }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $feedback->subject }}</div>
                                        <div class="small text-muted">{{ $feedback->typeLabel() }} · {{ $feedback->priorityLabel() }}</div>
                                    </td>
                                    <td>
                                        @php
                                            $badge = match($feedback->status) {
                                                FeedbackSubmission::STATUS_REVIEWED => 'bg-info-subtle text-info',
                                                FeedbackSubmission::STATUS_ARCHIVED => 'bg-secondary-subtle text-secondary',
                                                default => 'bg-warning-subtle text-warning',
                                            };
                                        @endphp
                                        <span class="badge {{ $badge }}">{{ $feedback->statusLabel() }}</span>
                                    </td>
                                    <td class="small text-muted">{{ $feedback->created_at->format('M d, Y') }}</td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary" wire:click="select({{ $feedback->id }})">Open</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No feedback submissions found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($feedbackItems->hasPages())
                <div class="mt-3">{{ $feedbackItems->links() }}</div>
            @endif
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    @if($selected)
                        <div class="d-flex justify-content-between gap-3 align-items-start mb-3">
                            <div>
                                <h2 class="h5 mb-1">{{ $selected->subject }}</h2>
                                <div class="small text-muted">
                                    {{ $selected->name }} · {{ $selected->email }} · {{ $selected->kc_handle }}
                                </div>
                            </div>
                            <span class="badge bg-light text-dark">{{ $selected->statusLabel() }}</span>
                        </div>

                        <dl class="row small mb-3">
                            <dt class="col-4">Type</dt>
                            <dd class="col-8">{{ $selected->typeLabel() }}</dd>

                            <dt class="col-4">Priority</dt>
                            <dd class="col-8">{{ $selected->priorityLabel() }}</dd>

                            <dt class="col-4">User</dt>
                            <dd class="col-8">{{ $selected->user?->name ?? 'Unknown user' }}</dd>

                            <dt class="col-4">Submitted</dt>
                            <dd class="col-8">{{ $selected->created_at->format('M d, Y H:i') }}</dd>

                            <dt class="col-4">Related page</dt>
                            <dd class="col-8">
                                @if($selected->page_url)
                                    <a href="{{ $selected->page_url }}" target="_blank" rel="noopener">{{ $selected->page_url }}</a>
                                @else
                                    Not provided
                                @endif
                            </dd>

                            @if($selected->reviewer)
                                <dt class="col-4">Reviewed by</dt>
                                <dd class="col-8">{{ $selected->reviewer->name }} · {{ $selected->reviewed_at?->format('M d, Y H:i') }}</dd>
                            @endif
                        </dl>

                        <div class="mb-3">
                            <div class="small text-muted mb-1">Message</div>
                            <div class="border rounded p-3">{{ $selected->message }}</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Admin notes</label>
                            <textarea wire:model.defer="adminNotes" rows="4" class="form-control @error('adminNotes') is-invalid @enderror" data-form-help="Add internal notes about how this feedback was handled. These notes are visible to admins only."></textarea>
                            @error('adminNotes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-primary rounded-pill" wire:click="saveNotes">Save notes</button>
                            <button class="btn btn-outline-success rounded-pill" wire:click="markReviewed">Mark reviewed</button>
                            <button class="btn btn-outline-secondary rounded-pill" wire:click="archive">Archive</button>
                        </div>
                    @else
                        <div class="text-muted text-center py-5">Select feedback to view details.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
