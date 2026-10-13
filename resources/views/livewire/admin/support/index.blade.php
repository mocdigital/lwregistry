<?php

use App\Enums\UserRole;
use App\Models\SupportEnquiry;
use App\Models\User;
use App\Notifications\SupportInquiryEscalatedNotification;
use App\Notifications\SupportInquiryRespondedNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Support Enquiries – Admin Dashboard',
        'dataPage' => 'support',
        'pageTitle' => 'Support Enquiries',
    ]
)] class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $statusFilter = 'open';
    public ?int $selectedId = null;
    public string $response = '';

    public function mount(): void
    {
        // A notification can deep-link to a specific enquiry (?enquiry=ID);
        // otherwise open the most recent one.
        $requested = (int) request()->integer('enquiry');

        $this->selectedId = $requested > 0 && SupportEnquiry::whereKey($requested)->exists()
            ? $requested
            : SupportEnquiry::latest()->value('id');
    }

    public function setFilter(string $status): void
    {
        $allowed = ['all', ...SupportEnquiry::statuses()];
        $this->statusFilter = in_array($status, $allowed, true) ? $status : 'open';
        $this->resetPage();
    }

    public function select(int $id): void
    {
        $enquiry = SupportEnquiry::findOrFail($id);
        $this->selectedId = $enquiry->id;
        $this->response = (string) ($enquiry->admin_response ?? '');
    }

    public function respond(): void
    {
        $enquiry = $this->selectedEnquiry();

        $data = $this->validate([
            'response' => ['required', 'string', 'max:5000'],
        ]);

        $enquiry->update([
            'admin_response' => $data['response'],
            'responded_by' => auth()->id(),
            'responded_at' => now(),
            'status' => SupportEnquiry::STATUS_RESPONDED,
        ]);

        $enquiry->user?->notify(new SupportInquiryRespondedNotification($enquiry->fresh()));

        session()->flash('success', 'Response sent to the user.');
    }

    public function markDone(): void
    {
        $this->selectedEnquiry()->update([
            'status' => SupportEnquiry::STATUS_DONE,
            'done_by' => auth()->id(),
            'done_at' => now(),
        ]);

        session()->flash('success', 'Support enquiry marked done.');
    }

    public function escalate(): void
    {
        $enquiry = $this->selectedEnquiry();

        $enquiry->update([
            'status' => SupportEnquiry::STATUS_ESCALATED,
            'escalated_by' => auth()->id(),
            'escalated_at' => now(),
        ]);

        $superAdmins = User::where('role', UserRole::SUPER_ADMIN->value)->get();

        if ($superAdmins->isNotEmpty()) {
            Notification::send($superAdmins, new SupportInquiryEscalatedNotification($enquiry->fresh()));
        }

        session()->flash('success', 'Support enquiry escalated to super admins.');
    }

    public function with(): array
    {
        $query = SupportEnquiry::query()
            ->with(['user', 'sbo'])
            ->latest();

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        $enquiries = $query->paginate(10);
        $selected = $this->selectedId
            ? SupportEnquiry::with(['user', 'sbo', 'responder', 'escalator', 'completer'])->find($this->selectedId)
            : null;

        return [
            'enquiries' => $enquiries,
            'selected' => $selected,
            'counts' => [
                'all' => SupportEnquiry::count(),
                'open' => SupportEnquiry::where('status', SupportEnquiry::STATUS_OPEN)->count(),
                'responded' => SupportEnquiry::where('status', SupportEnquiry::STATUS_RESPONDED)->count(),
                'escalated' => SupportEnquiry::where('status', SupportEnquiry::STATUS_ESCALATED)->count(),
                'done' => SupportEnquiry::where('status', SupportEnquiry::STATUS_DONE)->count(),
            ],
        ];
    }

    private function selectedEnquiry(): SupportEnquiry
    {
        return SupportEnquiry::findOrFail($this->selectedId);
    }
}; ?>

<div>
    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <div class="d-flex gap-2 flex-wrap mb-4">
        @foreach([
            'open' => 'Open',
            'responded' => 'Responded',
            'escalated' => 'Escalated',
            'done' => 'Done',
            'all' => 'All',
        ] as $key => $label)
            <button
                type="button"
                wire:click="setFilter('{{ $key }}')"
                class="btn btn-sm {{ $statusFilter === $key ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill"
            >
                {{ $label }}
                <span class="badge bg-light text-dark ms-1">{{ $counts[$key] }}</span>
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
                                <th>Requester</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($enquiries as $enquiry)
                                <tr class="{{ $selected?->id === $enquiry->id ? 'table-active' : '' }}">
                                    <td>
                                        <div class="fw-semibold">{{ $enquiry->first_name }} {{ $enquiry->last_name }}</div>
                                        <div class="small text-muted">{{ $enquiry->user->email ?? '—' }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $enquiry->subject }}</div>
                                        <div class="small text-muted">SBO: {{ $enquiry->sbo_number ?: 'Not provided' }}</div>
                                    </td>
                                    <td>
                                        @php
                                            $badge = match($enquiry->status) {
                                                'done' => 'bg-success-subtle text-success',
                                                'responded' => 'bg-info-subtle text-info',
                                                'escalated' => 'bg-warning-subtle text-warning',
                                                default => 'bg-secondary-subtle text-secondary',
                                            };
                                        @endphp
                                        <span class="badge {{ $badge }}">{{ ucfirst($enquiry->status) }}</span>
                                    </td>
                                    <td class="small text-muted">{{ $enquiry->created_at->format('M d, Y') }}</td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary" wire:click="select({{ $enquiry->id }})">Open</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No support enquiries found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($enquiries->hasPages())
                <div class="mt-3">{{ $enquiries->links() }}</div>
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
                                    {{ $selected->first_name }} {{ $selected->last_name }}
                                    @if($selected->user?->email)
                                        · {{ $selected->user->email }}
                                    @endif
                                </div>
                            </div>
                            <span class="badge bg-light text-dark">{{ ucfirst($selected->status) }}</span>
                        </div>

                        <dl class="row small mb-3">
                            <dt class="col-4">SBO Number</dt>
                            <dd class="col-8">{{ $selected->sbo_number ?: 'Not provided' }}</dd>

                            <dt class="col-4">SBO</dt>
                            <dd class="col-8">{{ $selected->sbo->name ?? 'Not linked' }}</dd>

                            <dt class="col-4">Submitted</dt>
                            <dd class="col-8">{{ $selected->created_at->format('M d, Y H:i') }}</dd>
                        </dl>

                        <div class="mb-3">
                            <div class="small text-muted mb-1">Message</div>
                            <div class="border rounded p-3">{{ $selected->message }}</div>
                        </div>

                        @if($selected->admin_response)
                            <div class="mb-3">
                                <div class="small text-muted mb-1">Current response</div>
                                <div class="border rounded p-3">{{ $selected->admin_response }}</div>
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label">Response to user</label>
                            <textarea wire:model.defer="response" rows="4" class="form-control @error('response') is-invalid @enderror"></textarea>
                            @error('response') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-primary rounded-pill" wire:click="respond">Send response</button>
                            <button class="btn btn-outline-success rounded-pill" wire:click="markDone">Mark done</button>
                            <button class="btn btn-outline-warning rounded-pill" wire:click="escalate">Escalate</button>
                        </div>
                    @else
                        <div class="text-muted text-center py-5">Select an enquiry to view details.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
