<?php

use App\Models\Sbo;
use App\Support\AdminCapabilities;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'SBOs – Admin Dashboard', 'dataPage' => 'businesses', 'pageTitle' => 'SBOs'])] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $filter = 'all';

    protected string $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        if ( ! auth()->user()?->hasPermission(AdminCapabilities::MANAGE_BUSINESSES)) {
            abort(403);
        }

        if ('verified' === $this->filter) {
            $this->filter = 'approved';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->reset('search');
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        if ( ! in_array($filter, ['all', 'pending', 'approved', 'declined'], true)) {
            return;
        }

        $this->filter = $filter;
        $this->resetPage();
    }

    public function with(): array
    {
        $user = auth()->user();
        // The SBO half of the directory. COB listings live on their own page.
        $baseQuery = Sbo::query()->where('entity_type', Sbo::ENTITY_SBO);
        $search = mb_trim($this->search);

        if ( ! $user?->hasPermission(AdminCapabilities::APPROVE_SBOS)) {
            $baseQuery->where('created_by', $user?->id);
        }

        $query = (clone $baseQuery)
            ->with('creator')
            ->when('' !== $search, fn ($q) => $q->where(function ($query) use ($search): void {
                $term = "%{$search}%";

                $query->where('name', 'like', $term)
                    ->orWhere('application_id', 'like', $term)
                    ->orWhere('moc_registration_number', 'like', $term)
                    ->orWhere('business_type', 'like', $term)
                    ->orWhere('category', 'like', $term)
                    ->orWhere('primary_contact_name', 'like', $term)
                    ->orWhere('primary_contact_email', 'like', $term)
                    ->orWhere('owner_first_name', 'like', $term)
                    ->orWhere('owner_surname', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('state', 'like', $term)
                    ->orWhere('country', 'like', $term)
                    ->orWhereHas('creator', function ($creatorQuery) use ($term): void {
                        $creatorQuery->where('firstname', 'like', $term)
                            ->orWhere('lastname', 'like', $term)
                            ->orWhere('name', 'like', $term)
                            ->orWhere('email', 'like', $term);
                    });
            }))
            ->when(
                in_array($this->filter, ['pending', 'approved', 'declined'], true),
                fn ($q) => $q->where('verification_status', $this->filter),
            )
            ->latest();

        $sbos = $query->paginate(10);

        $totalSbos = (clone $baseQuery)->count();
        $approvedSbos = (clone $baseQuery)->where('verification_status', 'approved')->count();
        $pendingSbos = (clone $baseQuery)->where('verification_status', 'pending')->count();
        $declinedSbos = (clone $baseQuery)->where('verification_status', 'declined')->count();
        $approvalRate = $totalSbos > 0 ? round(($approvedSbos / $totalSbos) * 100) : 0;

        return [
            'sbos' => $sbos,
            'totalSbos' => $totalSbos,
            'approvedSbos' => $approvedSbos,
            'pendingSbos' => $pendingSbos,
            'declinedSbos' => $declinedSbos,
            'approvalRate' => $approvalRate,
        ];
    }
}; ?>

<div>

<style>
    .business-logo {
        width: 48px;
        height: 48px;
        border-radius: 0.5rem;
        object-fit: contain;
        display: block;
        background-color: #fff;
        padding: 1px;
    }
</style>
    <section id="businesses" class="mb-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h5 mb-1">SBOs</h2>
                <p class="text-muted-soft small mb-0">
                    Review and manage Strategic Business Organizations in the Loveworld ecosystem.
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <x-live-search
                    model="search"
                    :value="$search"
                    label="Search SBOs"
                    placeholder="Search SBOs..."
                    class="mb-0" />

                {{-- Export the directory as it is currently filtered. Plain
                     download links, so the browser fetches the file. --}}
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary rounded-pill dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-header py-1">Download directory</li>
                        <li>
                            <a class="dropdown-item" href="{{ route('admin.businesses.export', ['format' => 'csv', 'status' => $filter, 'search' => $search, 'entity' => Sbo::ENTITY_SBO]) }}">
                                <i class="bi bi-filetype-csv me-2"></i>CSV (.csv)
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="{{ route('admin.businesses.export', ['format' => 'xlsx', 'status' => $filter, 'search' => $search, 'entity' => Sbo::ENTITY_SBO]) }}">
                                <i class="bi bi-file-earmark-spreadsheet me-2"></i>Excel (.xls)
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="{{ route('admin.businesses.export', ['format' => 'pdf', 'status' => $filter, 'search' => $search, 'entity' => Sbo::ENTITY_SBO]) }}">
                                <i class="bi bi-filetype-pdf me-2"></i>PDF (.pdf)
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Stats cards -->
        <div class="row g-3 g-md-4 mb-4">
            <div class="col-12 col-md-4">
                <div class="card stat-card dashboard-metric-card metric-gradient-green h-100">
                    <div class="card-body">
                        <div class="metric-header mb-2">
                            <div>
                                <div class="metric-title">Total SBOs</div>
                                <div class="metric-number">{{ number_format($totalSbos) }}</div>
                            </div>
                            <div class="metric-icon">
                                <i class="bi bi-building"></i>
                            </div>
                        </div>
                        <div class="metric-subtext text-muted-soft">
                            Strategic Business Organizations in the registry
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card stat-card dashboard-metric-card h-100">
                    <div class="card-body">
                        <div class="metric-header mb-2">
                            <div>
                                <div class="metric-title">Approval rate</div>
                                <div class="metric-number">{{ $approvalRate }}%</div>
                            </div>
                            <div class="metric-icon">
                                <i class="bi bi-patch-check"></i>
                            </div>
                        </div>
                        <div class="metric-subtext text-muted-soft">
                            {{ number_format($approvedSbos) }} approved of {{ number_format($totalSbos) }} total
                            <div class="metric-bar">
                                <div class="metric-bar-fill metric-bar-fill-success" style="width: {{ $approvalRate }}%;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card stat-card dashboard-metric-card h-100">
                    <div class="card-body">
                        <div class="metric-header mb-2">
                            <div>
                                <div class="metric-title">Pending review</div>
                                <div class="metric-number">{{ number_format($pendingSbos) }}</div>
                            </div>
                            <div class="metric-icon">
                                <i class="bi bi-hourglass-split"></i>
                            </div>
                        </div>
                        <div class="metric-subtext text-muted-soft">
                            Awaiting SBO approval
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab navigation -->
        <ul class="nav nav-pills pill-tab mb-3" role="tablist">
            <li class="nav-item">
                <button
                    type="button"
                    wire:click="setFilter('all')"
                    class="nav-link {{ $filter === 'all' ? 'active' : '' }}"
                >
                    All
                </button>
            </li>
            <li class="nav-item">
                <button
                    type="button"
                    wire:click="setFilter('pending')"
                    class="nav-link {{ $filter === 'pending' ? 'active' : '' }}"
                >
                    Pending review
                    @if($pendingSbos > 0)
                        <span class="badge bg-danger rounded-pill ms-1">{{ $pendingSbos }}</span>
                    @endif
                </button>
            </li>
            <li class="nav-item">
                <button
                    type="button"
                    wire:click="setFilter('approved')"
                    class="nav-link {{ $filter === 'approved' ? 'active' : '' }}"
                >
                    Approved
                </button>
            </li>
            <li class="nav-item">
                <button
                    type="button"
                    wire:click="setFilter('declined')"
                    class="nav-link {{ $filter === 'declined' ? 'active' : '' }}"
                >
                    Declined
                    @if($declinedSbos > 0)
                        <span class="badge bg-secondary rounded-pill ms-1">{{ $declinedSbos }}</span>
                    @endif
                </button>
            </li>
        </ul>

        <!-- SBOs table -->
        <div class="card" wire:loading.class.delay="opacity-50" wire:target="search,clearSearch,gotoPage,nextPage,previousPage">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>Logo</th>
                                <th>SBO</th>
                                <th>Contact</th>
                                <th>Category</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sbos as $sbo)
                                @php
                                    $detailUrl = route('admin.sbos.show', $sbo);
                                    $contactName = $sbo->primary_contact_name ?: trim(($sbo->owner_first_name ?? '') . ' ' . ($sbo->owner_surname ?? ''));
                                    $location = collect([$sbo->city, $sbo->state, $sbo->country])->filter()->join(', ');
                                    $verificationStatus = $sbo->verification_status ?? 'pending';
                                    $statusBadge = match ($verificationStatus) {
                                        'approved' => ['bg-success-subtle text-success border-success-subtle', 'bi-patch-check-fill', 'Approved'],
                                        'declined' => ['bg-danger-subtle text-danger border-danger-subtle', 'bi-x-circle', 'Declined'],
                                        'info_requested' => ['bg-info-subtle text-info border-info-subtle', 'bi-info-circle', 'Info requested'],
                                        default => ['bg-warning-subtle text-warning border-warning-subtle', 'bi-hourglass-split', 'Pending'],
                                    };
                                @endphp
                                <tr wire:key="sbo-{{ $sbo->id }}">
                                    <td>
                                        <a
                                        href="{{ $detailUrl }}"
                                        class="text-dark"
                                        style="text-decoration: none;"
                                        >
                                            <img src="{{ asset('images/logo-icon-light.png') }}" alt="{{ $sbo->name }}" class="business-logo">
                                        </a>
                                    </td>
                                    <td>
                                        <a
                                        href="{{ $detailUrl }}"
                                        class="text-dark"
                                        style="text-decoration: none;"
                                        >
                                        <div class="fw-semibold">{{ $sbo->name }}</div>
                                        @if($sbo->application_id)
                                            <div class="small text-muted-soft">{{ $sbo->application_id }}</div>
                                        @elseif($sbo->website)
                                            <div class="small text-muted-soft">{{ $sbo->website }}</div>
                                        @endif
                                        </a>
                                    </td>
                                    <td>
                                        @if($contactName || $sbo->primary_contact_email)
                                            <div class="d-flex align-items-center">
                                                <div>
                                                    @if($contactName)
                                                        <div class="fw-semibold small">{{ $contactName }}</div>
                                                    @endif
                                                    @if($sbo->primary_contact_email)
                                                        <div class="small text-muted-soft">{{ $sbo->primary_contact_email }}</div>
                                                    @endif
                                                </div>
                                            </div>
                                        @else
                                            <span class="text-muted-soft">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($sbo->category || $sbo->business_type)
                                            <span class="badge bg-light text-dark">{{ $sbo->category ?? $sbo->business_type }}</span>
                                        @else
                                            <span class="text-muted-soft">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($location)
                                            {{ $location }}
                                        @else
                                            <span class="text-muted-soft">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $statusBadge[0] }} border">
                                            <i class="bi {{ $statusBadge[1] }}"></i> {{ $statusBadge[2] }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ $detailUrl }}" class="btn btn-sm btn-outline-primary rounded-pill">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted-soft py-4">
                                        No SBOs found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($sbos->hasPages())
                    <div class="card-footer bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="small text-muted-soft">
                                Showing {{ $sbos->firstItem() }}–{{ $sbos->lastItem() }} of {{ number_format($sbos->total()) }} SBOs
                            </div>
                            <div>
                                {{ $sbos->links() }}
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>

    @push('styles')
    <style>
    /* Dashboard metric cards */
    .dashboard-metric-card {
        border-radius: 1.1rem;
    }

    .dashboard-metric-card .card-body {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 1.1rem 1.4rem;
    }

    .metric-header {
        display: flex;
        align-items: flex-start;
    }

    .metric-title {
        font-size: 0.9rem;
        color: var(--muted);
    }

    .metric-number {
        font-size: 1.7rem;
        font-weight: 600;
        line-height: 1.1;
    }

    .metric-icon {
        margin-left: auto;
        width: 44px;
        height: 44px;
        border-radius: 999px;
        background: rgba(15, 124, 146, 0.08);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--text-blue);
        font-size: 1.25rem;
    }

    .metric-subtext {
        font-size: 0.8rem;
    }

    .metric-bar {
        position: relative;
        height: 6px;
        border-radius: 999px;
        background: #edf2f7;
        overflow: hidden;
        margin-top: 0.3rem;
    }

    .metric-bar-fill {
        height: 100%;
        border-radius: 999px;
    }

    .metric-bar-fill-success {
        background: #1DBF73;
    }

    .metric-gradient-green {
        background: linear-gradient(135deg, #0F7C92, #1DBF73);
        color: #ffffff;
        border: none;
    }

    .metric-gradient-green .metric-title,
    .metric-gradient-green .metric-subtext {
        color: rgba(255, 255, 255, 0.85) !important;
    }

    .metric-gradient-green .metric-number {
        color: #ffffff;
    }

    .metric-gradient-green .metric-icon {
        background: rgba(255, 255, 255, 0.15);
    }

    .metric-gradient-green .metric-icon i {
        color: #ffffff;
    }

    .metric-gradient-green .metric-bar {
        background: rgba(255, 255, 255, 0.2);
    }

    /* Business logo in table */
    .business-logo {
        width: 48px;
        height: 48px;
        border-radius: 0.5rem;
        object-fit: cover;
        display: block;
    }
    </style>
    @endpush
</div>
