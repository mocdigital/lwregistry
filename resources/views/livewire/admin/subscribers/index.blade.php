<?php

use App\Models\Subscriber;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/*
 * The opportunity-alerts list.
 *
 * A list nobody can read is a list nobody will use, and a signup form whose
 * output has no screen is how a table quietly fills with addresses that never
 * get written to. This is that screen: who is on it, where they came from, and
 * a CSV for whatever actually sends the mail.
 */
new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Subscribers – Admin Dashboard',
        'dataPage' => 'subscribers',
        'pageTitle' => 'Subscribers',
    ]
)] class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $statusFilter = 'active';

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = in_array($status, ['active', 'unsubscribed', 'all'], true) ? $status : 'active';
        $this->resetPage();
    }

    /** Remove somebody from the list without destroying the record of consent. */
    public function unsubscribe(int $id): void
    {
        Subscriber::findOrFail($id)->update(['unsubscribed_at' => now()]);

        session()->flash('success', 'Subscriber removed from the list.');
    }

    public function resubscribe(int $id): void
    {
        Subscriber::findOrFail($id)->update(['unsubscribed_at' => null]);

        session()->flash('success', 'Subscriber added back to the list.');
    }

    /**
     * CSV of the active list.
     *
     * The registry sends no bulk mail of its own, so the useful thing is an
     * export for whatever does. Active only — exporting people who have
     * unsubscribed is how they end up mailed again.
     */
    public function export(): Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows = Subscriber::query()->active()->orderBy('email')->get();

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Name', 'Email', 'Source', 'Subscribed']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->name,
                    $row->email,
                    $row->source,
                    $row->created_at?->toDateString(),
                ]);
            }

            fclose($handle);
        }, 'subscribers-' . now()->toDateString() . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function with(): array
    {
        $query = Subscriber::query()->with('user')->latest();

        if ('active' === $this->statusFilter) {
            $query->active();
        } elseif ('unsubscribed' === $this->statusFilter) {
            $query->whereNotNull('unsubscribed_at');
        }

        if ('' !== mb_trim($this->search)) {
            $term = '%' . mb_trim($this->search) . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        return [
            'subscribers' => $query->paginate(20),
            'counts' => [
                'active' => Subscriber::query()->active()->count(),
                'unsubscribed' => Subscriber::whereNotNull('unsubscribed_at')->count(),
                'all' => Subscriber::count(),
            ],
        ];
    }
}; ?>

<div>
    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <div class="d-flex justify-content-between gap-3 flex-wrap align-items-start mb-4">
        <div>
            <h2 class="h5 mb-1">Opportunity alerts</h2>
            <p class="text-muted mb-0">
                People who asked to hear about new opportunities, from the Subscribe card on the
                home page. Most are not members — that is what the card is for.
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap justify-content-end">
            @foreach(['active' => 'Active', 'unsubscribed' => 'Unsubscribed', 'all' => 'All'] as $key => $label)
                <button
                    type="button"
                    wire:click="setStatusFilter('{{ $key }}')"
                    class="btn btn-sm {{ $statusFilter === $key ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill"
                >
                    {{ $label }}
                    <span class="badge bg-light text-dark ms-1">{{ $counts[$key] }}</span>
                </button>
            @endforeach

            <button type="button" class="btn btn-sm btn-outline-dark" wire:click="export">
                Export active as CSV
            </button>
        </div>
    </div>

    <div class="mb-3" style="max-width: 320px">
        <input
            type="search"
            class="form-control form-control-sm"
            placeholder="Search by name or email"
            wire:model.live.debounce.300ms="search"
        />
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Source</th>
                        <th>Account</th>
                        <th>Subscribed</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subscribers as $subscriber)
                        <tr>
                            <td class="fw-semibold">{{ $subscriber->name }}</td>
                            <td class="small"><a href="mailto:{{ $subscriber->email }}">{{ $subscriber->email }}</a></td>
                            <td class="small text-muted">{{ $subscriber->source }}</td>
                            <td class="small text-muted">{{ $subscriber->user?->name ?? '—' }}</td>
                            <td class="small text-muted">
                                {{ $subscriber->created_at->format('M d, Y') }}
                                @unless($subscriber->isActive())
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">Unsubscribed</span>
                                @endunless
                            </td>
                            <td class="text-end">
                                @if($subscriber->isActive())
                                    <button class="btn btn-sm btn-outline-danger" wire:click="unsubscribe({{ $subscriber->id }})">
                                        Unsubscribe
                                    </button>
                                @else
                                    <button class="btn btn-sm btn-outline-secondary" wire:click="resubscribe({{ $subscriber->id }})">
                                        Resubscribe
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Nobody on this list yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($subscribers->hasPages())
        <div class="mt-3">{{ $subscribers->links() }}</div>
    @endif
</div>
