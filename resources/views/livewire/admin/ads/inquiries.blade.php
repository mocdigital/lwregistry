<?php

use App\Models\AdvertisingInquiry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/*
 * Advertising inquiries.
 *
 * The public form has written to `advertising_inquiries` all along and nothing
 * read the table: the only way an inquiry reached anybody was the notification
 * mail, so one missed mail was a lost advertiser with no record anyone could
 * find. This is the queue that mail should have been pointing at.
 *
 * Modelled on the feedback inbox next door, deliberately — an admin who has
 * learned one triage screen has learned both.
 */
new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Advertising Inquiries – Admin Dashboard',
        'dataPage' => 'ads-inquiries',
        'pageTitle' => 'Advertising Inquiries',
    ]
)] class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $statusFilter = AdvertisingInquiry::STATUS_NEW;

    public string $locationFilter = 'all';

    public ?int $selectedId = null;

    public string $adminNotes = '';

    public function mount(): void
    {
        $this->selectedId = AdvertisingInquiry::latest()->value('id');
        $this->adminNotes = (string) (AdvertisingInquiry::find($this->selectedId)?->admin_notes ?? '');
    }

    public function setStatusFilter(string $status): void
    {
        $allowed = ['all', ...array_keys(self::statuses())];
        $this->statusFilter = in_array($status, $allowed, true) ? $status : AdvertisingInquiry::STATUS_NEW;
        $this->resetPage();
    }

    public function setLocationFilter(string $location): void
    {
        $allowed = ['all', ...array_keys(config('ads.slots', []))];
        $this->locationFilter = in_array($location, $allowed, true) ? $location : 'all';
        $this->resetPage();
    }

    public function select(int $id): void
    {
        $inquiry = AdvertisingInquiry::findOrFail($id);
        $this->selectedId = $inquiry->id;
        $this->adminNotes = (string) ($inquiry->admin_notes ?? '');
    }

    public function saveNotes(): void
    {
        $data = $this->validate([
            'adminNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->selectedInquiry()->update(['admin_notes' => $data['adminNotes'] ?: null]);

        session()->flash('success', 'Inquiry notes saved.');
    }

    public function markReviewed(): void
    {
        $this->stamp(AdvertisingInquiry::STATUS_REVIEWED);

        session()->flash('success', 'Inquiry marked reviewed.');
    }

    public function archive(): void
    {
        $this->stamp(AdvertisingInquiry::STATUS_ARCHIVED);

        session()->flash('success', 'Inquiry archived.');
    }

    public function reopen(): void
    {
        $this->selectedInquiry()->update([
            'status' => AdvertisingInquiry::STATUS_NEW,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        session()->flash('success', 'Inquiry reopened.');
    }

    public function with(): array
    {
        $query = AdvertisingInquiry::query()->with('user')->latest();

        if ('all' !== $this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ('all' !== $this->locationFilter) {
            $query->where('ad_location', $this->locationFilter);
        }

        $slots = config('ads.slots', []);

        return [
            'inquiries' => $query->paginate(10),
            'selected' => $this->selectedId
                ? AdvertisingInquiry::with(['user', 'reviewer'])->find($this->selectedId)
                : null,
            'statusOptions' => self::statuses(),
            'slots' => $slots,
            'counts' => [
                'all' => AdvertisingInquiry::count(),
                AdvertisingInquiry::STATUS_NEW => AdvertisingInquiry::where('status', AdvertisingInquiry::STATUS_NEW)->count(),
                AdvertisingInquiry::STATUS_REVIEWED => AdvertisingInquiry::where('status', AdvertisingInquiry::STATUS_REVIEWED)->count(),
                AdvertisingInquiry::STATUS_ARCHIVED => AdvertisingInquiry::where('status', AdvertisingInquiry::STATUS_ARCHIVED)->count(),
            ],
        ];
    }

    /** @return array<string, string> */
    private static function statuses(): array
    {
        return [
            AdvertisingInquiry::STATUS_NEW => 'New',
            AdvertisingInquiry::STATUS_REVIEWED => 'Reviewed',
            AdvertisingInquiry::STATUS_ARCHIVED => 'Archived',
        ];
    }

    private function stamp(string $status): void
    {
        $this->selectedInquiry()->update([
            'status' => $status,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);
    }

    private function selectedInquiry(): AdvertisingInquiry
    {
        return AdvertisingInquiry::findOrFail($this->selectedId);
    }
}; ?>

<div>
    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <div class="d-flex justify-content-between gap-3 flex-wrap align-items-start mb-4">
        <div>
            <h2 class="h5 mb-1">Advertising inquiries</h2>
            <p class="text-muted mb-0">
                Businesses asking about a placement. Sent from the public Advertise page; the
                placements they can choose are the ones configured under Ad Placements.
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap justify-content-end">
            @foreach([...$statusOptions, 'all' => 'All'] as $key => $label)
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
            wire:click="setLocationFilter('all')"
            class="btn btn-sm {{ $locationFilter === 'all' ? 'btn-dark' : 'btn-outline-dark' }} rounded-pill"
        >
            All placements
        </button>
        @foreach($slots as $key => $slot)
            <button
                type="button"
                wire:click="setLocationFilter('{{ $key }}')"
                class="btn btn-sm {{ $locationFilter === $key ? 'btn-dark' : 'btn-outline-dark' }} rounded-pill"
            >
                {{ $slot['label'] ?? $key }}
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
                                <th>Advertiser</th>
                                <th>Placement</th>
                                <th>Status</th>
                                <th>Received</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($inquiries as $inquiry)
                                <tr class="{{ $selected?->id === $inquiry->id ? 'table-active' : '' }}">
                                    <td>
                                        <div class="fw-semibold">{{ $inquiry->name }}</div>
                                        <div class="small text-muted">{{ $inquiry->email }}</div>
                                        <div class="small text-muted">{{ $inquiry->kc_handle }}</div>
                                    </td>
                                    <td class="small">
                                        {{ $slots[$inquiry->ad_location]['label'] ?? $inquiry->ad_location }}
                                    </td>
                                    <td>
                                        @php
                                            $badge = match($inquiry->status) {
                                                AdvertisingInquiry::STATUS_REVIEWED => 'bg-info-subtle text-info',
                                                AdvertisingInquiry::STATUS_ARCHIVED => 'bg-secondary-subtle text-secondary',
                                                default => 'bg-warning-subtle text-warning',
                                            };
                                        @endphp
                                        <span class="badge {{ $badge }}">{{ $statusOptions[$inquiry->status] ?? $inquiry->status }}</span>
                                    </td>
                                    <td class="small text-muted">{{ $inquiry->created_at->format('M d, Y') }}</td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary" wire:click="select({{ $inquiry->id }})">Open</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No advertising inquiries yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($inquiries->hasPages())
                <div class="mt-3">{{ $inquiries->links() }}</div>
            @endif
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    @if($selected)
                        <div class="d-flex justify-content-between gap-3 align-items-start mb-3">
                            <div>
                                <h2 class="h5 mb-1">{{ $selected->name }}</h2>
                                <div class="small text-muted">{{ $selected->email }} · {{ $selected->kc_handle }}</div>
                            </div>
                            <span class="badge bg-light text-dark">
                                {{ $statusOptions[$selected->status] ?? $selected->status }}
                            </span>
                        </div>

                        <dl class="row small mb-3">
                            <dt class="col-4">Placement</dt>
                            <dd class="col-8">{{ $slots[$selected->ad_location]['label'] ?? $selected->ad_location }}</dd>

                            <dt class="col-4">Account</dt>
                            <dd class="col-8">{{ $selected->user?->name ?? 'Unknown user' }}</dd>

                            <dt class="col-4">Received</dt>
                            <dd class="col-8">{{ $selected->created_at->format('M d, Y H:i') }}</dd>

                            @if($selected->reviewed_at)
                                <dt class="col-4">Reviewed</dt>
                                <dd class="col-8">
                                    {{ $selected->reviewed_at->format('M d, Y H:i') }}
                                    @if($selected->reviewer) by {{ $selected->reviewer->name }} @endif
                                </dd>
                            @endif
                        </dl>

                        <div class="mb-3">
                            <div class="small text-muted mb-1">Message</div>
                            <div class="border rounded p-3 small" style="white-space: pre-wrap">{{ $selected->message }}</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small" for="admin-notes">Internal notes</label>
                            <textarea
                                id="admin-notes"
                                class="form-control @error('adminNotes') is-invalid @enderror"
                                rows="4"
                                wire:model="adminNotes"
                                placeholder="What was agreed, who is following up, which campaign this became."
                            ></textarea>
                            @error('adminNotes')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-primary" wire:click="saveNotes">Save notes</button>

                            {{-- Reply opens the admin's own mail client: there is no outbound
                                 mailbox in the panel, and pretending otherwise would lose replies. --}}
                            <a class="btn btn-sm btn-outline-primary" href="mailto:{{ $selected->email }}?subject={{ rawurlencode('Your Loveworld Registry advertising inquiry') }}">
                                Reply by email
                            </a>

                            @if($selected->status === AdvertisingInquiry::STATUS_NEW)
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="markReviewed">Mark reviewed</button>
                            @else
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="reopen">Reopen</button>
                            @endif

                            @if($selected->status !== AdvertisingInquiry::STATUS_ARCHIVED)
                                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="archive">Archive</button>
                            @endif
                        </div>
                    @else
                        <p class="text-muted mb-0">Select an inquiry to read it.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
