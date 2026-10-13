<?php

use App\Models\ActivityLog;
use App\Models\Sbo;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Organizations – Admin Dashboard', 'dataPage' => 'sbos', 'pageTitle' => 'Organizations'])] class extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $statusFilter = 'all'; // all | pending | approved | declined

    /*
     * Industry — the sector above category. It drives the directory facet, the
     * certificate's "Business Sector" line and industry group-chat membership,
     * and there was no way to see or filter by it here at all.
     */
    public string $industryFilter = 'all';

    public string $name = '';

    public ?string $rc_number = null;

    public ?string $tax_number = null;

    public ?string $business_type = null;

    public ?string $category = null;

    public ?string $region = null;

    public ?string $established_at = null;

    public ?string $website = null;

    public string $primary_contact_name = '';

    public string $primary_contact_email = '';

    public ?string $phone = null;

    public ?string $kingschat_handle = null;

    public ?int $staff_count = null;

    public ?string $business_model = null;

    public ?string $description = null;

    /**
     * Directors stored as JSON
     */
    public array $directors = [
        [
            'name' => '',
            'email' => '',
            'phone' => '',
        ],
    ];

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function createSbo(): void
    {
        $this->validate();

        Sbo::create([
            'name' => $this->name,
            'slug' => Str::slug($this->name),

            // 🔐 Track creator
            'created_by' => auth()->id(),

            'rc_number' => $this->rc_number,
            'tax_number' => $this->tax_number,
            'business_type' => $this->business_type,
            'category' => $this->category,
            'region' => $this->region,
            'established_at' => $this->established_at,
            'website' => $this->website,

            'primary_contact_name' => $this->primary_contact_name,
            'primary_contact_email' => $this->primary_contact_email,
            'phone' => $this->phone,
            'kingschat_handle' => $this->kingschat_handle,

            'staff_count' => $this->staff_count,
            'business_model' => $this->business_model,
            'description' => $this->description,

            'directors' => $this->directors,
            'status' => 'active',
        ]);

        $this->resetForm();
        $this->dispatch('close-create-org-modal');

        session()->flash('success', 'Organization created successfully.');
    }

    /**
     * Roll an SBO back to the pre-payment stage: reset the registration status
     * to pending (so the datatable badge reads "Pending"), mark fees unpaid and
     * clear the payment timestamps, and set any payment proof back to pending
     * review (the uploaded file is kept). Document compliance is left untouched.
     * Super admins only.
     */
    public function unapprove(int $sboId): void
    {
        $this->authorizeSuperAdmin();

        $sbo = Sbo::findOrFail($sboId);

        // Nothing to roll back if it isn't approved and fees were never paid/submitted.
        if ( ! $sbo->isApproved() && ! $sbo->feesPaid() && null === $sbo->payment_submitted_at) {
            return;
        }

        $sbo->update([
            // Back to Pending in the listing; clear the approval audit fields.
            'verification_status' => 'pending',
            'approved_by' => null,
            'date_approved' => null,
            'payment_status' => 'unpaid',
            'payment_confirmed_at' => null,
            'payment_submitted_at' => null,
            'verified_notified_at' => null,
            'moc_registration_number' => null,
        ]);

        if ($seal = $sbo->seal) {
            $seal->deleteFile();
            $seal->delete();
        }

        // Keep the uploaded proof but send it back to pending review. update()
        // on the HasOne relation affects the single related row if it exists.
        $sbo->paymentProof()->update([
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $this->log(
            'sbo_unapproved',
            $sbo->name,
            'Payment unapproved for <strong>' . e($sbo->name) . '</strong> — returned to the pre-payment stage.',
            'pending'
        );

        session()->flash('success', $sbo->name . ' returned to the pre-payment stage.');
    }

    /** Deactivate (suspend) an SBO so it is no longer active. Super admins only. */
    public function suspend(int $sboId): void
    {
        $this->authorizeSuperAdmin();

        $sbo = Sbo::findOrFail($sboId);
        $sbo->update(['is_active' => false, 'status' => 'suspended']);

        $this->log(
            'sbo_suspended',
            $sbo->name,
            'SBO <strong>' . e($sbo->name) . '</strong> suspended.',
            'declined'
        );

        session()->flash('success', $sbo->name . ' has been suspended.');
    }

    /** Permanently delete an SBO. Super admins only. */
    public function deleteSbo(int $sboId): void
    {
        $this->authorizeSuperAdmin();

        $sbo = Sbo::findOrFail($sboId);
        $name = $sbo->name;
        $sbo->delete();

        $this->log(
            'sbo_deleted',
            $name,
            'SBO <strong>' . e($name) . '</strong> permanently deleted.',
            'declined'
        );

        session()->flash('success', $name . ' has been deleted.');
    }

    public function addDirector(): void
    {
        $this->directors[] = [
            'name' => '',
            'email' => '',
            'phone' => '',
        ];
    }

    public function removeDirector(int $index): void
    {
        if (1 === count($this->directors)) {
            return;
        }

        unset($this->directors[$index]);
        $this->directors = array_values($this->directors);
    }

    public function with(): array
    {
        $user = auth()->user();

        $query = Sbo::query();

        // 🔒 Compliance reviewers see all SBOs; others see only their own
        if ( ! $user->hasPermission(App\Support\AdminCapabilities::APPROVE_SBOS)) {
            $query->where('created_by', $user->id);
        }

        // Stats must respect the scope (before the status filter is applied)
        $total = (clone $query)->count();
        $pending = (clone $query)->where('verification_status', 'pending')->count();
        $approved = (clone $query)->where('verification_status', 'approved')->count();
        $declined = (clone $query)->where('verification_status', 'declined')->count();

        // Apply the status filter to the listing only
        if (in_array($this->statusFilter, ['pending', 'approved', 'declined'], true)) {
            $query->where('verification_status', $this->statusFilter);
        }

        // Validated against the canonical list, so an arbitrary value cannot
        // be pushed into the query from the URL.
        if ('all' !== $this->industryFilter
            && in_array($this->industryFilter, App\Support\SboIndustries::all(), true)) {
            $query->where('industry', $this->industryFilter);
        }

        $sbos = $query->latest()->paginate(10);

        return [
            'sbos' => $sbos,
            // Only sectors that actually have a listing, so the filter never
            // leads to an empty page.
            'industries' => Sbo::query()
                ->whereNotNull('industry')
                ->distinct()
                ->orderBy('industry')
                ->pluck('industry'),
            'total' => $total,
            'pending' => $pending,
            'approved' => $approved,
            'declined' => $declined,
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'rc_number' => 'nullable|string|max:100',
            'tax_number' => 'nullable|string|max:100',
            'business_type' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'region' => 'nullable|string|max:100',
            'established_at' => 'nullable|date',
            'website' => 'nullable|url',

            'primary_contact_name' => 'required|string|max:255',
            'primary_contact_email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'kingschat_handle' => 'nullable|string|max:255',

            'staff_count' => 'nullable|integer|min:0',
            'business_model' => 'nullable|string|max:50',
            'description' => 'nullable|string',

            'directors' => 'array|min:1',
            'directors.*.name' => 'required|string|max:255',
            'directors.*.email' => 'required|email|max:255',
            'directors.*.phone' => 'nullable|string|max:50',
        ];
    }

    protected function resetForm(): void
    {
        $this->reset([
            'name',
            'rc_number',
            'tax_number',
            'business_type',
            'category',
            'region',
            'established_at',
            'website',
            'primary_contact_name',
            'primary_contact_email',
            'phone',
            'kingschat_handle',
            'staff_count',
            'business_model',
            'description',
        ]);

        $this->directors = [
            [
                'name' => '',
                'email' => '',
                'phone' => '',
            ],
        ];
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }

    private function log(string $type, string $title, string $description, string $status = 'approved'): void
    {
        ActivityLog::create([
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'user_id' => auth()->id(),
        ]);
    }
};
?>

<div class="admin-page admin-organizations">

  <style>

    .org-card {
      background: #ffffff;
      border-radius: 18px;
      padding: 0;
      border: 1px solid var(--ui-border);
      box-shadow: none;
      transition: border-color 0.18s ease;
      height: 100%;
    }

    .org-card:hover {
      border-color: var(--ui-primary);
    }

    .org-card-body {
      padding: 1.5rem 1.4rem;
    }

    .org-card-title {
      font-size: .9rem;
      color: #6c757d;
      font-weight: 500;
      margin-bottom: .2rem;
    }

    .org-card-value {
      font-size: 2rem;
      font-weight: 600;
      color: #0e1a2c;
      margin-bottom: .3rem;
    }

    .org-card-metric {
      font-size: .85rem;
      margin-bottom: .6rem;
    }

    .org-card-subtext {
      font-size: .8rem;
      color: #7c8a98;
      margin-top: .25rem;
    }

    /* Icon */
    .org-card-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      background: #eaf1ff;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #0a6cff;
      font-size: 1.4rem;
      margin-bottom: .8rem;
    }

    /* Sparkline placeholder */
    .org-sparkline-wrapper {
      width: 100%;
      height: 30px;
      margin-top: .4rem;
    }

    .org-sparkline {
      height: 100%;
      background: linear-gradient(90deg, rgba(0,150,120,.2), rgba(0,150,120,.05));
      border-radius: 6px;
    }

    /* Progress bar */
    .org-bar {
      width: 100%;
      height: 8px;
      background: #eef1f4;
      border-radius: 20px;
      margin: .6rem 0 .3rem;
      overflow: hidden;
    }

    .org-bar-fill {
      height: 100%;
      background: #0a8c4d;
      border-radius: 20px;
    }

    /* Accent card variation */
    .org-card-accent {
      background: var(--ui-surface);
      color: var(--ui-ink);
      border-color: var(--ui-border);
    }

    .org-card-accent .org-card-title {
      color: var(--ui-muted);
    }

    .org-card-accent .org-card-value {
      color: var(--ui-ink);
    }

    .org-card-accent .org-card-metric {
      color: #9cf6c0;
    }

    .org-card-accent .org-card-icon {
      background: var(--ui-primary-soft);
      color: var(--ui-primary-ink);
    }

  </style>

    <x-ui.page-header
        title="Organizations"
        eyebrow="SBO operations"
        description="Manage Loveworld organizations, review validation status, and keep the directory accurate."
    >
        <x-slot:meta>
            <span><strong>{{ $total }}</strong> organizations</span>
            <span>{{ $pending }} waiting for review</span>
        </x-slot:meta>
        <x-slot:actions>
            <button type="button" class="btn btn-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#createOrgModal">
                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Create organization
            </button>
        </x-slot:actions>
    </x-ui.page-header>
    <x-ui.flash />

    <!-- Stats -->
    <div class="row g-3 g-md-4 mb-4">

        <div class="col-12 col-md-4">
            <div class="org-card org-card-accent">
                <div class="org-card-body">
                    <div class="org-card-icon">
                        <i class="bi bi-building"></i>
                    </div>
                    <div class="org-card-title">Total Organizations</div>
                    <div class="org-card-value">{{ $total }}</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="org-card" role="button" wire:click="$set('statusFilter', 'pending')">
                <div class="org-card-body">
                    <div class="org-card-icon bg-warning-subtle text-warning">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <div class="org-card-title">Pending Approval</div>
                    <div class="org-card-value">{{ $pending }}</div>
                    <div class="org-card-subtext">Awaiting review</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="org-card" role="button" wire:click="$set('statusFilter', 'approved')">
                <div class="org-card-body">
                    <div class="org-card-icon bg-success-subtle text-success">
                        <i class="bi bi-patch-check-fill"></i>
                    </div>
                    <div class="org-card-title">Approved</div>
                    <div class="org-card-value">{{ $approved }}</div>
                    <div class="org-card-subtext">
                        {{ $total > 0 ? round(($approved / $total) * 100) : 0 }}% Approved
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Status filter -->
    <div class="d-flex align-items-center gap-2 mb-3">
        <span class="small text-muted-soft">Filter:</span>
        <select class="form-select form-select-sm w-auto" wire:model.live="industryFilter">
            <option value="all">All industries</option>
            @foreach($industries as $industryOption)
                <option value="{{ $industryOption }}">{{ $industryOption }}</option>
            @endforeach
        </select>

        <select class="form-select form-select-sm w-auto" wire:model.live="statusFilter">
            <option value="all">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="declined">Declined</option>
        </select>

        {{-- Export the list as it is currently filtered. Plain download links,
             so the browser fetches the file rather than a Livewire round trip. --}}
        <div class="dropdown ms-auto">
            <button class="btn btn-sm btn-outline-secondary rounded-pill dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-download me-1"></i>Export
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="dropdown-header py-1">Download list</li>
                <li>
                    <a class="dropdown-item" href="{{ route('admin.sbos.export', ['format' => 'csv', 'status' => $statusFilter, 'industry' => $industryFilter]) }}">
                        <i class="bi bi-filetype-csv me-2"></i>CSV (.csv)
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="{{ route('admin.sbos.export', ['format' => 'xlsx', 'status' => $statusFilter, 'industry' => $industryFilter]) }}">
                        <i class="bi bi-file-earmark-spreadsheet me-2"></i>Excel (.xls)
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="{{ route('admin.sbos.export', ['format' => 'pdf', 'status' => $statusFilter, 'industry' => $industryFilter]) }}">
                        <i class="bi bi-filetype-pdf me-2"></i>PDF (.pdf)
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>Organization</th>
                            <th>Industry</th>
                            <th>Region</th>
                            <th>Primary Contact</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($sbos as $sbo)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $sbo->name }}</div>
                                    <div class="small text-muted-soft">{{ $sbo->website }}</div>
                                </td>

                                <td>
                                    @if($sbo->industry)
                                        <span class="badge-secondary-soft rounded-pill">{{ $sbo->industry }}</span>
                                    @else
                                        <span class="text-muted-soft">—</span>
                                    @endif
                                </td>
                                <td>{{ $sbo->region }}</td>

                                <td>
                                    <div class="small fw-semibold">{{ $sbo->primary_contact_name }}</div>
                                    <div class="small text-muted-soft">{{ $sbo->primary_contact_email }}</div>
                                </td>

                                <td>{{ $sbo->business_type }}</td>

                                <td>
                                    @php
                                        $vs = $sbo->verification_status ?? 'pending';
                                        $b = match($vs) {
                                            'approved' => ['bg-success-subtle text-success border-success-subtle', 'Approved'],
                                            'declined' => ['bg-danger-subtle text-danger border-danger-subtle', 'Declined'],
                                            default     => ['bg-warning-subtle text-warning border-warning-subtle', 'Pending'],
                                        };
                                    @endphp
                                    <span class="badge {{ $b[0] }} border">{{ $b[1] }}</span>
                                </td>

                                <td class="text-end">
                                    <a
                                        href="{{ route('admin.sbos.show', $sbo) }}"
                                        class="btn btn-sm btn-outline-primary rounded-pill me-1"
                                    >
                                        View
                                    </a>

                                    @if(auth()->user()?->isSuperAdmin())
                                        <div class="btn-group">
                                            <button
                                                class="btn btn-sm btn-outline-secondary rounded-pill dropdown-toggle"
                                                data-bs-toggle="dropdown"
                                            >
                                                More
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <button class="dropdown-item"
                                                        wire:click="suspend({{ $sbo->id }})"
                                                        wire:confirm="Suspend {{ $sbo->name }}? It will be marked inactive.">
                                                        Suspend
                                                    </button>
                                                </li>
                                                @if($sbo->feesPaid() || $sbo->payment_submitted_at !== null)
                                                    <li>
                                                        <button class="dropdown-item"
                                                            wire:click="unapprove({{ $sbo->id }})"
                                                            wire:confirm="Unapprove {{ $sbo->name }}? This returns it to the pre-payment stage (fees marked unpaid).">
                                                            Unapprove
                                                        </button>
                                                    </li>
                                                @endif
                                                <li>
                                                    <button class="dropdown-item text-danger"
                                                        wire:click="deleteSbo({{ $sbo->id }})"
                                                        wire:confirm="Permanently delete {{ $sbo->name }}? This cannot be undone.">
                                                        Delete
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted-soft py-4">
                                    No organizations found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($sbos->hasPages())
                <div class="p-2 border-top d-flex justify-content-between align-items-center small">
                    <span class="text-muted-soft">
                        Showing {{ $sbos->firstItem() }}–{{ $sbos->lastItem() }}
                        of {{ $sbos->total() }} organizations
                    </span>

                    {{ $sbos->links() }}
                </div>
            @endif
        </div>
    </div>

    <!-- CREATE ORGANIZATION MODAL -->
    <div
        class="modal fade"
        id="createOrgModal"
        tabindex="-1"
        aria-labelledby="createOrgModalLabel"
        aria-hidden="true"
        wire:ignore.self
    >
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">

                <form wire:submit.prevent="createSbo">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createOrgModalLabel">Create new organization</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">

                            <!-- Name -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Organization name</label>
                                <input type="text" class="form-control" wire:model.defer="name">
                                @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- RC Number -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Registration number</label>
                                <input type="text" class="form-control" wire:model.defer="rc_number">
                            </div>

                            <!-- TIN -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">TIN</label>
                                <input type="text" class="form-control" wire:model.defer="tax_number">
                            </div>

                            <!-- Business Type -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Business type</label>
                                <select class="form-select" wire:model.defer="business_type">
                                    <option value="">Choose…</option>
                                    <option>Limited Liability Company (LLC)</option>
                                    <option>Sole Proprietorship</option>
                                    <option>Partnership</option>
                                    <option>Cooperative</option>
                                    <option>Non-profit / NGO</option>
                                </select>
                            </div>

                            <!-- Category -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Category</label>
                                <select class="form-select" wire:model.defer="category">
                                    <option value="">Choose…</option>
                                    <option>Ministry</option>
                                    <option>Business</option>
                                    <option>Education</option>
                                    <option>NGO</option>
                                </select>
                            </div>

                            <!-- Region -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Region</label>
                                <select class="form-select" wire:model.defer="region">
                                    <option value="">Choose…</option>
                                    <option>West Africa</option>
                                    <option>East Africa</option>
                                    <option>South Africa</option>
                                    <option>North Africa</option>
                                    <option>Middle East</option>
                                    <option>Asia</option>
                                    <option>Eastern Europe</option>
                                    <option>Western Europe</option>
                                </select>
                            </div>

                            <!-- Establishment date -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Establishment date</label>
                                <input type="date" class="form-control" wire:model.defer="established_at">
                            </div>

                            <!-- Website -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Website</label>
                                <input type="url" class="form-control" wire:model.defer="website">
                            </div>

                            <!-- Primary contact -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Primary contact name</label>
                                <input type="text" class="form-control" wire:model.defer="primary_contact_name">
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label">Primary contact email</label>
                                <input type="email" class="form-control" wire:model.defer="primary_contact_email">
                            </div>

                            <!-- Phone -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" wire:model.defer="phone">
                            </div>

                            <!-- KingsChat -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">KingsChat handle / URL</label>
                                <input type="text" class="form-control" wire:model.defer="kingschat_handle">
                            </div>

                            <!-- Staff -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Number of staff</label>
                                <input type="number" class="form-control" wire:model.defer="staff_count">
                            </div>

                            <!-- Business model -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Business model</label>
                                <select class="form-select" wire:model.defer="business_model">
                                    <option value="">Choose…</option>
                                    <option>B2B</option>
                                    <option>B2C</option>
                                    <option>B2G</option>
                                    <option>Hybrid</option>
                                </select>
                            </div>

                            <!-- Description -->
                            <div class="col-12">
                                <label class="form-label">Short description</label>
                                <textarea class="form-control" rows="3" wire:model.defer="description"></textarea>
                            </div>

                            <!-- Directors -->
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label mb-0">Directors</label>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary rounded-pill"
                                        wire:click="addDirector"
                                    >
                                        Add director
                                    </button>
                                </div>

                                @foreach($directors as $index => $director)
                                    <div class="row g-2 align-items-center mb-2 p-2 border rounded-2">
                                        <div class="col-md-4">
                                            <input
                                                type="text"
                                                class="form-control"
                                                placeholder="Name"
                                                wire:model.defer="directors.{{ $index }}.name"
                                            >
                                        </div>

                                        <div class="col-md-4">
                                            <input
                                                type="email"
                                                class="form-control"
                                                placeholder="Email"
                                                wire:model.defer="directors.{{ $index }}.email"
                                            >
                                        </div>

                                        <div class="col-md-3">
                                            <input
                                                type="text"
                                                class="form-control"
                                                placeholder="Phone"
                                                wire:model.defer="directors.{{ $index }}.phone"
                                            >
                                        </div>

                                        <div class="col-md-1 text-end">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger rounded-pill"
                                                wire:click="removeDirector({{ $index }})"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                        </div>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-outline-secondary rounded-pill"
                            data-bs-dismiss="modal"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            class="btn btn-primary rounded-pill"
                            wire:loading.attr="disabled"
                        >
                            Create organization
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
<!-- END CREATE ORGANIZATION MODAL -->
</div>
