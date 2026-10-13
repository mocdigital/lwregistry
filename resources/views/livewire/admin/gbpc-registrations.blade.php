<?php

use App\Models\GbpcConferenceRegistration;
use App\Support\AdminCapabilities;
use App\Support\GbpcLiveStream;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', [
    'title' => 'GBPC Registrations – Admin Dashboard',
    'dataPage' => 'gbpc-registrations',
    'pageTitle' => 'GBPC Registrations',
])] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** The GBPC landing page's live-stream URL, editable here. */
    public string $streamUrl = '';

    protected string $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        if (! auth()->user()?->hasPermission(AdminCapabilities::VIEW_ADMIN)) {
            abort(403);
        }

        $this->streamUrl = GbpcLiveStream::url();
    }

    public function saveStreamUrl(): void
    {
        abort_unless(auth()->user()?->hasPermission(AdminCapabilities::VIEW_ADMIN), 403);

        $this->validate([
            'streamUrl' => ['required', 'url', 'max:2048'],
        ]);

        GbpcLiveStream::setUrl(mb_trim($this->streamUrl));

        session()->flash('stream-saved', 'Live stream URL saved. It will play on the GBPC page.');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $search = mb_trim($this->search);

        $query = GbpcConferenceRegistration::query()
            ->when('' !== $search, function ($q) use ($search): void {
                $term = '%' . $search . '%';
                $q->where('full_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('country_city', 'like', $term)
                    ->orWhere('zone', 'like', $term)
                    ->orWhere('occupation', 'like', $term)
                    ->orWhere('business_name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('kingschat_handle', 'like', $term);
            })
            ->latest();

        return [
            'registrations' => $query->paginate(25),
            'total' => GbpcConferenceRegistration::count(),
        ];
    }
};
?>

<div>
    <section class="mb-4">
        <div class="card">
            <div class="card-body">
                <h2 class="h6 mb-1"><i class="bi bi-broadcast me-1"></i>GBPC Live Stream</h2>
                <p class="text-muted-soft small mb-3">
                    The video played in the “Join the Conference Live” section on gbpc.lwregistry.org.
                    Paste an HLS (.m3u8) stream URL; it takes effect the next time the page is loaded.
                </p>
                @if (session('stream-saved'))
                    <div class="alert alert-success py-2 small mb-3">{{ session('stream-saved') }}</div>
                @endif
                <form wire:submit="saveStreamUrl" class="d-flex flex-wrap align-items-start gap-2">
                    <div class="flex-grow-1" style="min-width: 260px;">
                        <input
                            type="url"
                            wire:model="streamUrl"
                            class="form-control @error('streamUrl') is-invalid @enderror"
                            placeholder="https://…/playlist.m3u8"
                            aria-label="Live stream URL"
                        >
                        @error('streamUrl')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <span wire:loading.remove wire:target="saveStreamUrl">Save</span>
                        <span wire:loading wire:target="saveStreamUrl">Saving…</span>
                    </button>
                </form>
            </div>
        </div>
    </section>

    <section class="mb-5">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h5 mb-1">GBPC Registrations</h2>
                <p class="text-muted-soft small mb-0">
                    Conference registrations submitted from the GBPC landing page (gbpc.lwregistry.org).
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <x-live-search model="search" :value="$search" label="Search registrations" placeholder="Search…" class="mb-0" />
                <a href="{{ route('admin.gbpc-registrations.export') }}" class="btn btn-sm btn-outline-secondary rounded-pill">
                    <i class="bi bi-download me-1"></i>Export CSV
                </a>
            </div>
        </div>

        <div class="card stat-card mb-3" style="max-width: 260px;">
            <div class="card-body">
                <div class="small text-muted-soft">Total registrations</div>
                <div class="h4 mb-0">{{ number_format($total) }}</div>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle" style="min-width: 1200px;">
                        <thead class="bg-light">
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>KingsChat</th>
                                <th>Country/City</th>
                                <th>Zone</th>
                                <th>Occupation</th>
                                <th>Business</th>
                                <th>Expectations</th>
                                <th>Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($registrations as $r)
                                <tr wire:key="gbpc-{{ $r->id }}">
                                    <td class="fw-semibold">{{ trim($r->title . ' ' . $r->full_name) }}</td>
                                    <td><a href="mailto:{{ $r->email }}">{{ $r->email }}</a></td>
                                    <td>{{ $r->phone }}</td>
                                    <td>{{ $r->kingschat_handle ?: '—' }}</td>
                                    <td>{{ $r->country_city }}</td>
                                    <td>{{ $r->zone }}</td>
                                    <td>{{ $r->occupation }}</td>
                                    <td>
                                        @if('Yes' === $r->owns_business)
                                            <span class="fw-semibold">{{ $r->business_name ?: 'Yes' }}</span>
                                        @else
                                            <span class="text-muted-soft">No</span>
                                        @endif
                                    </td>
                                    <td class="small" style="max-width: 280px;">
                                        <span title="{{ $r->expectations }}">{{ \Illuminate\Support\Str::limit($r->expectations, 80) }}</span>
                                    </td>
                                    <td class="text-muted-soft small text-nowrap">{{ $r->created_at?->format('M j, Y g:ia') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted-soft py-4">No registrations yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($registrations->hasPages())
                    <div class="card-footer bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="small text-muted-soft">
                                Showing {{ $registrations->firstItem() }}–{{ $registrations->lastItem() }} of {{ number_format($registrations->total()) }}
                            </div>
                            <div>{{ $registrations->links() }}</div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>
</div>
