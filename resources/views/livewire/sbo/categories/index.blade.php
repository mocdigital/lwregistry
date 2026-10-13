<?php

use App\Models\Category;
use App\Models\Sbo;
use App\Models\SboConnection;
use App\Support\SboNetworkAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class() extends Component
{
    use WithPagination;

    #[Url(as: 'sbo', history: true, keep: true)]
    public ?int $activeSboId = null;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'category', keep: true)]
    public string $categoryFilter = 'all';

    #[Url(as: 'country', keep: true)]
    public string $countryFilter = 'all';

    #[Url(as: 'zone', keep: true)]
    public string $zoneFilter = 'all';

    #[Url(as: 'business_type', keep: true)]
    public string $businessTypeFilter = 'all';

    #[Url(as: 'verification', keep: true)]
    public string $verificationFilter = 'all';

    #[Url(as: 'connection', keep: true)]
    public string $connectionFilter = 'all';

    #[Url(as: 'sort', keep: true)]
    public string $sort = 'name';

    protected string $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        $ownedSbos = $this->ownedSbos();

        abort_unless($ownedSbos->isNotEmpty(), 403, 'Only SBO owners can browse SBO categories.');

        $this->activeSboId ??= $ownedSbos->first()?->id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, [
            'search',
            'categoryFilter',
            'countryFilter',
            'zoneFilter',
            'businessTypeFilter',
            'verificationFilter',
            'connectionFilter',
            'sort',
            'activeSboId',
        ], true)) {
            $this->resetPage();
        }
    }

    public function clearSearch(): void
    {
        $this->reset('search');
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->categoryFilter = 'all';
        $this->countryFilter = 'all';
        $this->zoneFilter = 'all';
        $this->businessTypeFilter = 'all';
        $this->verificationFilter = 'all';
        $this->connectionFilter = 'all';
        $this->sort = 'name';
        $this->resetPage();
    }

    public function selectCategory(string $category): void
    {
        $this->categoryFilter = $category;
        $this->resetPage();
    }

    public function with(): array
    {
        $access = app(SboNetworkAccess::class);
        $ownedSbos = $this->ownedSbos();

        if ( ! $ownedSbos->pluck('id')->contains($this->activeSboId)) {
            $this->activeSboId = $ownedSbos->first()?->id;
        }

        $activeSbo = $ownedSbos->firstWhere('id', $this->activeSboId);
        abort_unless($activeSbo, 403, 'Only SBO owners can browse SBO categories.');

        $ownedSboIds = $ownedSbos->pluck('id')->all();
        $directorySbos = $this->directorySbos($ownedSboIds);
        $categoryOptions = $this->categoryOptions($directorySbos);
        $connections = $this->connectionState($activeSbo);
        $filteredSbos = $this->applyFilters($directorySbos, $connections);

        return [
            'ownedSbos' => $ownedSbos,
            'activeSbo' => $activeSbo,
            'activeCanUseNetwork' => $access->sboCanUseNetwork($activeSbo),
            'categories' => $categoryOptions,
            'countries' => $this->options($directorySbos, 'country'),
            'zones' => $this->options($directorySbos, 'church_zone'),
            'businessTypes' => $this->options($directorySbos, 'business_type'),
            'categoryHighlights' => $this->categoryHighlights($categoryOptions, $directorySbos),
            'connectionState' => $connections,
            'sbos' => $this->paginateCollection($filteredSbos),
            'totalEligible' => $directorySbos->count(),
            'totalFiltered' => $filteredSbos->count(),
        ];
    }

    /**
     * @return Collection<int, Sbo>
     */
    private function ownedSbos(): Collection
    {
        return auth()->user()
            ->sbos()
            ->orderBy('name')
            ->get()
            ->values();
    }

    /**
     * @param  array<int, int>  $ownedSboIds
     * @return Collection<int, Sbo>
     */
    private function directorySbos(array $ownedSboIds): Collection
    {
        return Sbo::query()
            ->whereNotIn('id', $ownedSboIds ?: [0])
            ->orderBy('name')
            ->get()
            ->values();
    }

    /**
     * @return array{accepted: Collection<int, int>, pending: Collection<int, int>}
     */
    private function connectionState(Sbo $activeSbo): array
    {
        $connections = SboConnection::query()
            ->whereIn('status', [SboConnection::STATUS_ACCEPTED, SboConnection::STATUS_PENDING])
            ->where(function ($query) use ($activeSbo): void {
                $query->where('requester_sbo_id', $activeSbo->id)
                    ->orWhere('recipient_sbo_id', $activeSbo->id);
            })
            ->get();

        $otherId = fn (SboConnection $connection): int => (int) (
            (int) $connection->requester_sbo_id === (int) $activeSbo->id
                ? $connection->recipient_sbo_id
                : $connection->requester_sbo_id
        );

        return [
            'accepted' => $connections
                ->where('status', SboConnection::STATUS_ACCEPTED)
                ->map($otherId)
                ->values(),
            'pending' => $connections
                ->where('status', SboConnection::STATUS_PENDING)
                ->map($otherId)
                ->values(),
        ];
    }

    /**
     * @param  Collection<int, Sbo>  $sbos
     * @param  array{accepted: Collection<int, int>, pending: Collection<int, int>}  $connections
     * @return Collection<int, Sbo>
     */
    private function applyFilters(Collection $sbos, array $connections): Collection
    {
        $search = mb_strtolower(mb_trim($this->search));

        $filtered = $sbos->filter(function (Sbo $sbo) use ($search, $connections): bool {
            if ('' !== $search && ! $this->matchesSearch($sbo, $search)) {
                return false;
            }

            if ( ! $this->matchesExact($sbo->category, $this->categoryFilter)) {
                return false;
            }

            if ( ! $this->matchesExact($sbo->country, $this->countryFilter)) {
                return false;
            }

            if ( ! $this->matchesExact($sbo->church_zone, $this->zoneFilter)) {
                return false;
            }

            if ( ! $this->matchesExact($sbo->business_type, $this->businessTypeFilter)) {
                return false;
            }

            if ( ! $this->matchesVerificationFilter($sbo)) {
                return false;
            }

            return $this->matchesConnectionFilter($sbo, $connections);
        });

        return match ($this->sort) {
            'category' => $filtered->sortBy([
                fn (Sbo $sbo): string => mb_strtolower($sbo->category ?: 'zzzz'),
                fn (Sbo $sbo): string => mb_strtolower($sbo->name),
            ])->values(),
            'country' => $filtered->sortBy([
                fn (Sbo $sbo): string => mb_strtolower($sbo->country ?: 'zzzz'),
                fn (Sbo $sbo): string => mb_strtolower($sbo->name),
            ])->values(),
            'recent' => $filtered->sortByDesc('created_at')->values(),
            'staff_desc' => $filtered->sortByDesc(fn (Sbo $sbo): int => (int) $sbo->staff_count)->values(),
            default => $filtered->sortBy(fn (Sbo $sbo): string => mb_strtolower($sbo->name))->values(),
        };
    }

    private function matchesSearch(Sbo $sbo, string $search): bool
    {
        $haystack = collect([
            $sbo->name,
            $sbo->category,
            $sbo->business_type,
            $sbo->legal_structure,
            $sbo->description,
            $sbo->city,
            $sbo->state,
            $sbo->country,
            $sbo->church_zone,
            $sbo->local_assembly,
            $sbo->moc_registration_number,
        ])
            ->filter()
            ->map(fn (string $value): string => mb_strtolower($value))
            ->join(' ');

        return str_contains($haystack, $search);
    }

    private function matchesExact(?string $value, string $filter): bool
    {
        return 'all' === $filter || $filter === (string) $value;
    }

    private function matchesVerificationFilter(Sbo $sbo): bool
    {
        return match ($this->verificationFilter) {
            'verified' => $sbo->isFullyVerified(),
            'not_verified' => ! $sbo->isFullyVerified(),
            'pending' => 'pending' === $sbo->verification_status,
            'approved' => 'approved' === $sbo->verification_status,
            'info_requested' => 'info_requested' === $sbo->verification_status,
            'declined' => 'declined' === $sbo->verification_status,
            'fees_paid' => $sbo->feesPaid(),
            'documents_compliant' => $sbo->documentsCompliant(),
            'active' => 'active' === $sbo->status && (bool) $sbo->is_active,
            'inactive' => 'active' !== $sbo->status || ! (bool) $sbo->is_active,
            default => true,
        };
    }

    /**
     * @param  array{accepted: Collection<int, int>, pending: Collection<int, int>}  $connections
     */
    private function matchesConnectionFilter(Sbo $sbo, array $connections): bool
    {
        if ('all' === $this->connectionFilter) {
            return true;
        }

        $isAccepted = $connections['accepted']->contains((int) $sbo->id);
        $isPending = $connections['pending']->contains((int) $sbo->id);

        return match ($this->connectionFilter) {
            'connected' => $isAccepted,
            'pending' => $isPending,
            'not_connected' => ! $isAccepted && ! $isPending,
            default => true,
        };
    }

    /**
     * @param  Collection<int, Sbo>  $sbos
     * @return Collection<int, string>
     */
    private function options(Collection $sbos, string $field): Collection
    {
        return $sbos
            ->pluck($field)
            ->filter(fn (?string $value): bool => filled($value))
            ->unique()
            ->sort(fn (string $a, string $b): int => strnatcasecmp($a, $b))
            ->values();
    }

    /**
     * @param  Collection<int, Sbo>  $sbos
     * @return Collection<int, string>
     */
    private function categoryOptions(Collection $sbos): Collection
    {
        return Category::query()
            ->orderBy('name')
            ->pluck('name')
            ->merge($sbos->pluck('category'))
            ->filter(fn (?string $value): bool => filled($value))
            ->unique()
            ->sort(fn (string $a, string $b): int => strnatcasecmp($a, $b))
            ->values();
    }

    /**
     * @param  Collection<int, string>  $categories
     * @param  Collection<int, Sbo>  $sbos
     * @return Collection<int, array{name: string, count: int}>
     */
    private function categoryHighlights(Collection $categories, Collection $sbos): Collection
    {
        $counts = $sbos
            ->groupBy(fn (Sbo $sbo): string => $sbo->category ?: 'General services')
            ->map(fn (Collection $group): int => $group->count());

        return $categories
            ->map(fn (string $category): array => [
                'name' => $category,
                'count' => (int) ($counts[$category] ?? 0),
            ])
            ->sortBy([
                ['count', 'desc'],
                ['name', 'asc'],
            ])
            ->take(12)
            ->values();
    }

    /**
     * @param  Collection<int, Sbo>  $sbos
     * @return LengthAwarePaginator<int, Sbo>
     */
    private function paginateCollection(Collection $sbos): LengthAwarePaginator
    {
        $page = $this->getPage();
        $perPage = 12;

        return new LengthAwarePaginator(
            $sbos->forPage($page, $perPage)->values(),
            $sbos->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );
    }
};
?>

<div class="sbo-categories-remake sbo-remake">
    <x-ui.page-header
        title="SBO Categories"
        description="Browse SBOs by category, country, zone, business type, registration status, and connection state."
        :breadcrumbs="[
            ['label' => 'SBO Workspace', 'href' => route('sbo.dashboard'), 'wireNavigate' => true],
            ['label' => 'SBO Categories', 'current' => true],
        ]"
    >
        <x-slot:actions>
            <a href="{{ route('sbo.network.index') }}" class="btn btn-outline-primary rounded-pill" wire:navigate>
                <i class="bi bi-diagram-3 me-1"></i> Open SBO Network
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.sbo-context :sbo="$activeSbo" :options="$ownedSbos" />

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                        <div>
                            <h2 class="h6 mb-1">Discover by category</h2>
                            <div class="small text-muted">{{ $totalEligible }} SBOs available outside your own workspace.</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-link text-decoration-none" wire:click="clearFilters">
                            Clear filters
                        </button>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        @forelse($categoryHighlights as $category)
                            <button
                                type="button"
                                class="btn btn-sm {{ $categoryFilter === $category['name'] ? 'btn-primary' : 'btn-light border' }} rounded-pill"
                                wire:click='selectCategory(@json($category["name"]))'
                            >
                                {{ $category['name'] }}
                                <span class="badge rounded-pill {{ $categoryFilter === $category['name'] ? 'bg-light text-primary' : 'bg-white text-muted border' }} ms-1">
                                    {{ $category['count'] }}
                                </span>
                            </button>
                        @empty
                            <div class="text-muted small">No verified SBO categories are available yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <label class="form-label">Acting as</label>
                    <select class="form-select" wire:model.live="activeSboId" data-form-help="Choose which of your SBOs should be used when checking existing network connections.">
                        @foreach($ownedSbos as $sbo)
                            <option value="{{ $sbo->id }}">{{ $sbo->name }}</option>
                        @endforeach
                    </select>
                    <div class="small text-muted mt-2">Connection filters are measured against this SBO.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <x-live-search
                        model="search"
                        :value="$search"
                        label="Search SBO categories"
                        placeholder="Search SBO, category, city, zone..."
                        help="Search across SBO name, category, business type, location, zone, description, and MOC registration number."
                        min-width="100%" />
                </div>

                <div class="col-md-6 col-lg-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Category</label>
                    <select class="form-select form-select-sm" wire:model.live="categoryFilter">
                        <option value="all">All categories</option>
                        @foreach($categories as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6 col-lg-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Country</label>
                    <select class="form-select form-select-sm" wire:model.live="countryFilter">
                        <option value="all">All countries</option>
                        @foreach($countries as $country)
                            <option value="{{ $country }}">{{ $country }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6 col-lg-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Zone</label>
                    <select class="form-select form-select-sm" wire:model.live="zoneFilter">
                        <option value="all">All zones</option>
                        @foreach($zones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6 col-lg-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Business type</label>
                    <select class="form-select form-select-sm" wire:model.live="businessTypeFilter">
                        <option value="all">All types</option>
                        @foreach($businessTypes as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6 col-lg-3">
                    <label class="form-label small fw-semibold text-muted mb-1">SBO status</label>
                    <select class="form-select form-select-sm" wire:model.live="verificationFilter">
                        <option value="all">All SBOs</option>
                        <option value="verified">Fully verified</option>
                        <option value="not_verified">Not fully verified</option>
                        <option value="pending">Pending approval</option>
                        <option value="approved">Approved registration</option>
                        <option value="info_requested">Info requested</option>
                        <option value="declined">Declined</option>
                        <option value="fees_paid">Fees paid</option>
                        <option value="documents_compliant">Documents compliant</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div class="col-md-6 col-lg-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Connection</label>
                    <select class="form-select form-select-sm" wire:model.live="connectionFilter">
                        <option value="all">All connection states</option>
                        <option value="connected">Connected</option>
                        <option value="pending">Pending</option>
                        <option value="not_connected">Not connected</option>
                    </select>
                </div>

                <div class="col-md-6 col-lg-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Sort</label>
                    <select class="form-select form-select-sm" wire:model.live="sort">
                        <option value="name">Name</option>
                        <option value="category">Category</option>
                        <option value="country">Country</option>
                        <option value="recent">Recently registered</option>
                        <option value="staff_desc">Largest staff count</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div class="small text-muted">
            Showing <strong>{{ $totalFiltered }}</strong> matching SBO{{ $totalFiltered === 1 ? '' : 's' }}.
        </div>
        <div class="small text-muted">
            Use the SBO status dropdown to include verified, provisional, pending, or declined applications.
        </div>
    </div>

    <div id="sbo-category-results" class="row g-3 sbo-categories-results-anchor" wire:loading.class.delay="opacity-50" wire:target="search,clearSearch,clearFilters,categoryFilter,countryFilter,zoneFilter,businessTypeFilter,verificationFilter,connectionFilter,sort,activeSboId,gotoPage,nextPage,previousPage">
        @forelse($sbos as $company)
            @php
                $isAccepted = $connectionState['accepted']->contains((int) $company->id);
                $isPending = $connectionState['pending']->contains((int) $company->id);
                $isFullyVerified = $company->isFullyVerified();
                $location = collect([$company->city, $company->state, $company->country])->filter()->join(', ');
                $websiteUrl = $company->website
                    ? (preg_match('/^https?:\/\//i', $company->website) ? $company->website : 'https://' . $company->website)
                    : null;
                $statusLabel = match (true) {
                    $isFullyVerified => 'Fully verified',
                    $company->isDeclined() => 'Declined',
                    $company->isInfoRequested() => 'Info requested',
                    $company->isApproved() => 'Approved',
                    default => 'Provisional',
                };
                $statusClass = match (true) {
                    $isFullyVerified => 'bg-success-subtle text-success',
                    $company->isDeclined() => 'bg-danger-subtle text-danger',
                    $company->isInfoRequested() => 'bg-warning-subtle text-warning',
                    $company->isApproved() => 'bg-primary-subtle text-primary',
                    default => 'bg-light text-dark',
                };
            @endphp
            <div class="col-md-6 col-xl-4" wire:key="sbo-category-card-{{ $company->id }}">
                <article class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between gap-3 align-items-start mb-3">
                            <div>
                                <div class="fw-semibold">{{ $company->name }}</div>
                                <div class="small text-muted">{{ $company->category ?: 'General services' }}</div>
                            </div>
                            <span class="badge {{ $statusClass }} border">{{ $statusLabel }}</span>
                        </div>

                        @if($company->description)
                            <p class="small text-muted mb-3">{{ \Illuminate\Support\Str::limit($company->description, 135) }}</p>
                        @else
                            <p class="small text-muted mb-3">This SBO has not added a full public description yet.</p>
                        @endif

                        <div class="d-flex flex-column gap-2 small mb-3">
                            @if($company->business_type)
                                <div><i class="bi bi-briefcase me-1 text-muted"></i>{{ $company->business_type }}</div>
                            @endif
                            @if($location)
                                <div><i class="bi bi-geo-alt me-1 text-muted"></i>{{ $location }}</div>
                            @endif
                            @if($company->church_zone)
                                <div><i class="bi bi-people me-1 text-muted"></i>{{ $company->church_zone }}</div>
                            @endif
                            @if($company->staff_count)
                                <div><i class="bi bi-person-workspace me-1 text-muted"></i>{{ number_format((int) $company->staff_count) }} staff</div>
                            @endif
                            <div>
                                <i class="bi bi-shield-check me-1 text-muted"></i>
                                Fees: {{ $company->feesPaid() ? 'paid' : 'pending' }} · Documents: {{ $company->documentsCompliant() ? 'compliant' : 'pending' }}
                            </div>
                        </div>

                        <div class="mt-auto d-flex gap-2 flex-wrap">
                            @if($isAccepted)
                                <span class="badge bg-success-subtle text-success border align-self-center">Connected</span>
                            @elseif($isPending)
                                <span class="badge bg-warning-subtle text-warning border align-self-center">Connection pending</span>
                            @elseif($activeCanUseNetwork && $isFullyVerified)
                                <a href="{{ route('sbo.network.index') }}" class="btn btn-sm btn-outline-primary rounded-pill" wire:navigate>
                                    Network
                                </a>
                            @elseif($isFullyVerified)
                                <span class="badge bg-light text-muted border align-self-center">Network locked</span>
                            @else
                                <span class="badge bg-light text-muted border align-self-center">Not network-ready</span>
                            @endif
                            @if($websiteUrl)
                                <a href="{{ $websiteUrl }}" class="btn btn-sm btn-light border rounded-pill" target="_blank" rel="noopener">
                                    Website
                                </a>
                            @endif
                        </div>
                    </div>
                </article>
            </div>
        @empty
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-search fs-1 text-muted mb-3"></i>
                        <h2 class="h6">No SBOs match these filters</h2>
                        <p class="text-muted small mb-3">Try a broader category, country, zone, or keyword.</p>
                        <button type="button" class="btn btn-primary rounded-pill" wire:click="clearFilters">
                            Clear filters
                        </button>
                    </div>
                </div>
            </div>
        @endforelse
    </div>

    @if($sbos->hasPages())
        <div class="mt-4">
            {{ $sbos->links(data: ['scrollTo' => '#sbo-category-results']) }}
        </div>
    @endif
</div>
