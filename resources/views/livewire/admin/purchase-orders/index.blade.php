<?php

use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\Sbo;
use App\Support\AdminCapabilities;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Procurement Requests', 'dataPage' => 'purchase-orders', 'pageTitle' => 'Procurement Requests'])] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    /* =======================
     * Filters
     * ======================= */
    public ?string $statusFilter = null;

    public string $search = '';

    /* =======================
     * Create RFQ form
     * ======================= */
    public ?int $sbo_id = null;

    public ?int $category_id = null;

    public string $request_type = 'product';

    public string $title = '';

    public string $description = '';

    public ?string $budget = null;

    public ?string $quantity = null;

    public ?string $unit_specification = null;

    public ?string $delivery_location = null;

    public ?string $service_scope = null;

    public ?string $service_duration = null;

    public ?string $required_expertise = null;

    public ?string $required_documents = null;

    public ?string $terms = null;

    public ?string $order_deadline = null;

    public $document = null;

    protected string $paginationTheme = 'bootstrap';

    protected $queryString = [
        'statusFilter' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    public function with(): array
    {
        $user = auth()->user();
        $search = mb_trim($this->search);

        /* =======================
         * Base scoped query
         * ======================= */
        $baseQuery = PurchaseOrder::with(['sbo', 'category'])
            ->withCount('bids');

        if ( ! $user->hasPermission(AdminCapabilities::MANAGE_RFQS)) {
            $baseQuery->whereHas(
                'sbo',
                fn ($q) => $q->where('created_by', $user->id)
            );
        }

        /* =======================
         * Stats (scoped)
         * ======================= */
        $total = (clone $baseQuery)->count();
        $open = (clone $baseQuery)->where('status', 'open')->count();
        $awarded = (clone $baseQuery)->where('status', 'awarded')->count();
        $completed = (clone $baseQuery)->where('status', 'completed')->count();

        /* =======================
         * Filters
         * ======================= */
        if ($this->statusFilter) {
            $baseQuery->where('status', $this->statusFilter);
        }

        if ('' !== $search) {
            $baseQuery->where(function ($q) use ($search): void {
                $term = "%{$search}%";

                $q->where('title', 'like', $term)
                    ->orWhereHas('sbo', fn ($sq) => $sq->where('name', 'like', $term));
            });
        }

        /* =======================
         * SBO dropdown (scoped)
         * ======================= */
        $sbosQuery = Sbo::query()
            ->where('verification_status', 'approved')
            ->where('documents_status', 'compliant')
            ->where('payment_status', 'paid');

        if ( ! $user->hasPermission(AdminCapabilities::MANAGE_RFQS)) {
            $sbosQuery->where('created_by', $user->id);
        }

        return [
            'purchaseOrders' => $baseQuery->latest()->paginate(10),
            'total' => $total,
            'open' => $open,
            'awarded' => $awarded,
            'completed' => $completed,
            'sbosForDropdown' => $sbosQuery->orderBy('name')->get(),
            'categoriesForDropdown' => Category::orderBy('name')->get(),
        ];
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

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /* =======================
     * Create RFQ
     * ======================= */
    public function createRfq(): void
    {
        $this->validate([
            'sbo_id' => [
                'required',
                Rule::exists('sbos', 'id')
                    ->where('verification_status', 'approved')
                    ->where('documents_status', 'compliant')
                    ->where('payment_status', 'paid'),
            ],
            'category_id' => 'required|exists:categories,id',
            'request_type' => ['required', Rule::in(['product', 'service', 'product_service'])],
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'budget' => ['nullable', 'numeric', 'min:0'],
            'quantity' => [Rule::requiredIf(in_array($this->request_type, ['product', 'product_service'], true)), 'nullable', 'string', 'max:255'],
            'unit_specification' => ['nullable', 'string', 'max:255'],
            'delivery_location' => [Rule::requiredIf(in_array($this->request_type, ['product', 'product_service'], true)), 'nullable', 'string', 'max:255'],
            'service_scope' => [Rule::requiredIf(in_array($this->request_type, ['service', 'product_service'], true)), 'nullable', 'string', 'max:3000'],
            'service_duration' => ['nullable', 'string', 'max:255'],
            'required_expertise' => ['nullable', 'string', 'max:255'],
            'required_documents' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'order_deadline' => 'required|date|after:today',
            'document' => 'nullable|file|max:10240',
        ]);

        $path = $this->document
            ? $this->document->store('rfqs', 'public')
            : null;

        PurchaseOrder::create([
            'sbo_id' => $this->sbo_id,
            'category_id' => $this->category_id,
            'request_type' => $this->request_type,
            'title' => $this->title,
            'description' => $this->description,
            'budget' => '' !== $this->budget ? $this->budget : null,
            'quantity' => in_array($this->request_type, ['product', 'product_service'], true) ? ($this->quantity ?: null) : null,
            'unit_specification' => in_array($this->request_type, ['product', 'product_service'], true) ? ($this->unit_specification ?: null) : null,
            'delivery_location' => in_array($this->request_type, ['product', 'product_service'], true) ? ($this->delivery_location ?: null) : null,
            'service_scope' => in_array($this->request_type, ['service', 'product_service'], true) ? ($this->service_scope ?: null) : null,
            'service_duration' => in_array($this->request_type, ['service', 'product_service'], true) ? ($this->service_duration ?: null) : null,
            'required_expertise' => in_array($this->request_type, ['service', 'product_service'], true) ? ($this->required_expertise ?: null) : null,
            'required_documents' => $this->required_documents ?: null,
            'terms' => $this->terms ?: null,
            'order_deadline' => $this->order_deadline,
            'document_path' => $path,
            'status' => 'open',
        ]);

        $this->reset([
            'sbo_id',
            'category_id',
            'title',
            'description',
            'budget',
            'quantity',
            'unit_specification',
            'delivery_location',
            'service_scope',
            'service_duration',
            'required_expertise',
            'required_documents',
            'terms',
            'order_deadline',
            'document',
        ]);
        $this->request_type = 'product';

        $this->dispatch('close-create-rfq-modal');
        session()->flash('success', 'Procurement request created successfully.');
    }
};
?>

<div>

  <!-- Small CSS tweaks for this screen -->
  <style>
    .org-card { border-radius: 14px; box-shadow: 0 8px 30px rgba(11,37,69,0.04); }
    .org-card-body { padding: 1rem; display:flex; gap:1rem; align-items:center; }
    .org-card-icon { width:48px;height:48px;border-radius:12px;background:#eef6ff;display:flex;align-items:center;justify-content:center;color:#0f6fb2;font-size:1.25rem; }
    .org-card-accent { background: linear-gradient(135deg,#0f5132,#0a3622); color:#fff; }
    .org-card-accent .org-card-icon { background: rgba(255,255,255,0.12); color:#fff; }
    .table-sm td, .table-sm th { font-size: .88rem; }
    /* ============================================================
       Purchase Order Screen — Visual Enhancements
       ============================================================ */

    /* --- METRIC CARDS --- */
    .po-stats .org-card {
      border: none;
      border-radius: 14px;
      padding: 0;
      background: #fff;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
      transition: transform .15s ease, box-shadow .2s ease;
    }

    .po-stats .org-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 12px 32px rgba(0,0,0,0.12);
    }

    .po-stats .org-card-body {
      padding: 1.25rem;
    }

    .po-stats .org-card-icon {
      width: 50px;
      height: 50px;
      background: #ecf4ff;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.35rem;
      color: #0a58ca;
    }

    .po-stats .org-card-accent {
      background: linear-gradient(135deg, #0a3622, #0f5132);
      color: #fff;
    }

    .po-stats .org-card-title {
      font-size: .85rem;
      font-weight: 600;
      color: #7b8a9d;
      margin-bottom: 2px;
    }

    .po-stats .org-card-value {
      font-size: 1.6rem;
      font-weight: 700;
      margin-bottom: 2px;
    }


    /* --- SEARCH + FILTER ROW --- */
    #rfqSearch,
    #rfqFilterStatus {
      border-radius: 50px !important;
      border: 1px solid #d5dce6 !important;
      padding-left: 14px;
    }

    #rfqSearch:focus,
    #rfqFilterStatus:focus {
      border-color: #0d6efd !important;
      box-shadow: 0 0 0 0.15rem rgba(13, 110, 253, 0.15);
    }


    /* --- RFQ TABLE --- */
    #rfqTableBody tr {
      transition: background-color 0.2s ease;
    }

    #rfqTableBody tr:hover {
      background: #f7faff !important;
    }

    .table thead th {
      font-size: .8rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      font-weight: 600;
      color: #6c7a8a;
      background-color: #f1f4f8 !important;
      border-bottom: none;
    }

    .table tbody td {
      padding-top: 0.75rem !important;
      padding-bottom: 0.75rem !important;
    }

    .table-hover tbody tr:hover td {
      background: #f4f8ff !important;
    }

    /* modern badge look */
    .badge {
      font-weight: 600;
      padding: 0.35em 0.7em;
      border-radius: 50px;
    }

    /* status colors */
    .badge-open {
      background: #fff7e0;
      color: #d89a00;
    }

    .badge-assigned {
      background: #e7f6ff;
      color: #0d6efd;
    }

    .badge-completed {
      background: #e8f7ed;
      color: #1c7c2f;
    }

    /* --- ACTION BUTTONS --- */
    .table .btn-sm {
      padding: 4px 10px;
      font-weight: 500;
      border-radius: 50px !important;
    }

    /* --- PAGINATION --- */
    .pagination .page-link {
      border-radius: 50px !important;
      padding: .35rem .75rem;
      border: none;
      color: #6b7987;
      font-weight: 500;
    }

    .pagination .page-item.active .page-link {
      background-color: #0d6efd;
      color: #fff;
    }

    /* --- CARD WRAPPER --- */
    .card {
      border-radius: 14px;
      border: none;
      box-shadow: 0 4px 18px rgba(0,0,0,0.04);
    }

    .rfq-dropzone {
      background: #f8fafc;
      border-style: dashed !important;
      cursor: pointer;
      transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .rfq-dropzone:hover {
      background: #f1f5f9;
      border-color: rgba(15, 124, 146, 0.4) !important;
      box-shadow: 0 0 0 0.12rem rgba(15, 124, 146, 0.12);
    }

    .rfq-dropzone.rfq-dropzone--active {
      background: #e0f7f0;
      border-color: var(--light-color) !important;
      box-shadow: 0 0 0 0.14rem rgba(29, 191, 115, 0.2);
    }

    .rfq-dropzone-inner p {
      margin-bottom: .25rem;
    }

    /* Info graphic box */
    .rfq-infobox {
      background: radial-gradient(circle at top left, #f3f7ff 0, #edf2ff 50%, #e5f5f0 100%);
      border: 1px solid rgba(148, 163, 184, 0.25);
    }

    .rfq-infobox-icon {
      width: 32px;
      height: 32px;
      border-radius: 999px;
      background: rgba(15, 124, 146, 0.08);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-blue);
      font-size: 1.1rem;
    }

    .rfq-steps li::marker {
      color: var(--text-blue);
      font-weight: 600;
    }

  </style>

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h5 mb-1">Procurement Requests</h2>
            <p class="text-muted-soft small mb-0">
                Create product and service requests, review bids, and track completion.
            </p>
        </div>

        <button
            class="btn btn-sm btn-primary rounded-pill"
            data-bs-toggle="modal"
            data-bs-target="#createPOFromRFQModal"
        >
            <i class="bi bi-plus-circle me-1"></i> Create Request
        </button>
    </div>

    <!-- STATS -->
    <div class="row g-3 g-md-4 mb-4">

        <div class="col-12 col-md-3">
            <div class="org-card">
                <div class="org-card-body">
                    <div class="d-flex align-items-center">
                        <div class="org-card-icon"><i class="bi bi-clipboard"></i></div>
                        <div class="ms-3">
                            <div class="org-card-title">Total Requests</div>
                            <div class="org-card-value">{{ $total }}</div>
                            <div class="small text-muted-soft">All procurement requests</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-3">
            <div class="org-card">
                <div class="org-card-body">
                    <div class="d-flex align-items-center">
                        <div class="org-card-icon"><i class="bi bi-clipboard"></i></div>
                        <div class="ms-3">
                            <div class="org-card-title">Open Requests</div>
                            <div class="org-card-value">{{ $open }}</div>
                            <div class="small text-muted-soft">Awaiting award</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-3">
            <div class="org-card">
                 <div class="org-card-body">
                    <div class="d-flex align-items-center">
                        <div class="org-card-icon bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i></div>
                        <div class="ms-3">
                            <div class="org-card-title">Awarded Requests</div>
                            <div class="org-card-value">{{ $awarded }}</div>
                            <div class="small text-muted-soft">In progress</div>
                        </div>
                    </div>
                </div> 
            </div>
        </div>

        <div class="col-12 col-md-3">
            <div class="org-card org-card-accent">
                  <div class="org-card-body">
                    <div class="d-flex align-items-center">
                        <div class="org-card-icon"><i class="bi bi-graph-up"></i></div>
                        <div class="ms-3">
                            <div class="org-card-title text-light">Completed Requests</div>
                            <div class="org-card-value">{{ $completed }}</div>
                            <div class="small text-light"> Marked Complete </div>
                        </div>
                    </div>
                </div>   
            </div>
        </div>

    </div>

    <!-- TABLE -->
    <div class="card" wire:loading.class.delay="opacity-50" wire:target="search,clearSearch,statusFilter,gotoPage,nextPage,previousPage">
        <div class="card-body p-3">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex gap-2 flex-wrap">
                    <x-live-search
                        model="search"
                        :value="$search"
                        label="Search procurement requests"
                        placeholder="Search requests or organizations..."
                        min-width="240px"
                        class="mb-0" />
                    <select class="form-select form-select-sm" wire:model.live="statusFilter" style="width:160px;">
                        <option value="">All statuses</option>
                        <option value="open">Open</option>
                        <option value="awarded">Awarded</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div class="small text-muted-soft">
                    Showing {{ $purchaseOrders->firstItem() }}–{{ $purchaseOrders->lastItem() }} of {{ $purchaseOrders->total() }}
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>Request</th>
                            <th>Organization</th>
                            <th>Bids</th>
                            <th>Visibility</th>
                            <th>Deadline</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($purchaseOrders as $po)
                            <tr>
                                <td class="fw-semibold">{{ $po->title }}</td>
                                <td>{{ $po->sbo->name }}</td>
                                <td>{{ $po->bids_count }}</td>
                                <td>
                                    @if($po->isInviteOnly())
                                        <span class="badge bg-primary-subtle text-primary border">Invite-only</span>
                                    @else
                                        <span class="badge bg-success-subtle text-success border">Public</span>
                                    @endif
                                </td>
                                <td>{{ $po->order_deadline?->format('Y-m-d') ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $po->statusBadgeClass() }}">{{ $po->statusLabel() }}</span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('admin.purchase-orders.show', $po) }}"
                                       class="btn btn-sm btn-outline-primary rounded-pill">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted-soft py-4">No procurement requests found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $purchaseOrders->links() }}
        </div>
    </div>

    <!-- CREATE PROCUREMENT REQUEST MODAL -->
    <div class="modal fade" id="createPOFromRFQModal" tabindex="-1" aria-labelledby="createPOFromRFQModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="createRfq" id="createRFQForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createPOFromRFQModalLabel">Create Procurement Request</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-4">
                            <!-- Left: form fields -->
                            <div class="col-12 col-lg-7">
                                <div class="mb-3">
                                    <label class="form-label" for="adminRfqOrganization">Organization</label>
                                    <select id="adminRfqOrganization" class="form-select" wire:model.defer="sbo_id">
                                        <option value="">Select organization</option>
                                        @foreach($sbosForDropdown as $sbo)
                                            <option value="{{ $sbo->id }}">{{ $sbo->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="adminRfqIndustry">Industry</label>
                                    <select id="adminRfqIndustry" class="form-select" wire:model.defer="category_id">
                                        <option value="">Select Industry</option>
                                        @foreach($categoriesForDropdown as $cat)
                                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Request Type</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <input type="radio" class="btn-check" id="adminRequestTypeProduct" value="product" wire:model.live="request_type">
                                        <label class="btn btn-outline-primary rounded-pill" for="adminRequestTypeProduct">Product</label>

                                        <input type="radio" class="btn-check" id="adminRequestTypeService" value="service" wire:model.live="request_type">
                                        <label class="btn btn-outline-primary rounded-pill" for="adminRequestTypeService">Service</label>

                                        <input type="radio" class="btn-check" id="adminRequestTypeBoth" value="product_service" wire:model.live="request_type">
                                        <label class="btn btn-outline-primary rounded-pill" for="adminRequestTypeBoth">Product &amp; Service</label>
                                    </div>
                                    @error('request_type') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="adminRfqTitle">Request Title</label>
                                    <input id="adminRfqTitle" class="form-control" wire:model.defer="title" placeholder="e.g. Supply and install media equipment">
                                    @error('title') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="adminRfqDescription">Description / Specification</label>
                                    <textarea id="adminRfqDescription" class="form-control" rows="4" wire:model.defer="description" placeholder="Describe what vendors should quote for."></textarea>
                                    @error('description') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="adminRfqBudget">Budget <small class="text-muted">(optional)</small></label>
                                    <input id="adminRfqBudget" type="number" step="0.01" min="0" class="form-control" wire:model.defer="budget">
                                    @error('budget') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                @if(in_array($request_type, ['product', 'product_service'], true))
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label" for="adminRfqQuantity">Quantity</label>
                                            <input id="adminRfqQuantity" class="form-control" wire:model.defer="quantity" placeholder="e.g. 200 units">
                                            @error('quantity') <span class="text-danger small">{{ $message }}</span> @enderror
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label" for="adminRfqUnitSpecification">Unit / Specification</label>
                                            <input id="adminRfqUnitSpecification" class="form-control" wire:model.defer="unit_specification" placeholder="e.g. 55 inch">
                                            @error('unit_specification') <span class="text-danger small">{{ $message }}</span> @enderror
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label" for="adminRfqDeliveryLocation">Delivery Location</label>
                                            <input id="adminRfqDeliveryLocation" class="form-control" wire:model.defer="delivery_location" placeholder="e.g. Lagos Zone 1">
                                            @error('delivery_location') <span class="text-danger small">{{ $message }}</span> @enderror
                                        </div>
                                    </div>
                                @endif

                                @if(in_array($request_type, ['service', 'product_service'], true))
                                    <div class="mb-3">
                                        <label class="form-label" for="adminRfqServiceScope">Service Scope</label>
                                        <textarea id="adminRfqServiceScope" class="form-control" rows="3" wire:model.defer="service_scope" placeholder="Describe the work, deliverables, and expected outcome."></textarea>
                                        @error('service_scope') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="adminRfqServiceDuration">Service Duration <small class="text-muted">(optional)</small></label>
                                            <input id="adminRfqServiceDuration" class="form-control" wire:model.defer="service_duration" placeholder="e.g. 2 weeks">
                                            @error('service_duration') <span class="text-danger small">{{ $message }}</span> @enderror
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="adminRfqRequiredExpertise">Required Expertise <small class="text-muted">(optional)</small></label>
                                            <input id="adminRfqRequiredExpertise" class="form-control" wire:model.defer="required_expertise" placeholder="e.g. AV installation">
                                            @error('required_expertise') <span class="text-danger small">{{ $message }}</span> @enderror
                                        </div>
                                    </div>
                                @endif

                                <div class="mb-3">
                                    <label class="form-label" for="adminRfqRequiredDocuments">Required documents from bidders <small class="text-muted">(optional)</small></label>
                                    <textarea id="adminRfqRequiredDocuments" class="form-control" rows="2" wire:model.defer="required_documents"></textarea>
                                    @error('required_documents') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="adminRfqTerms">Terms &amp; conditions <small class="text-muted">(optional)</small></label>
                                    <textarea id="adminRfqTerms" class="form-control" rows="3" wire:model.defer="terms"></textarea>
                                    @error('terms') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="adminRfqDeadline">Submission Deadline</label>
                                    <input id="adminRfqDeadline" type="date" class="form-control" wire:model.defer="order_deadline">
                                    @error('order_deadline') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                <div class="mb-2">
                                    <label class="form-label" for="rfqFileInput">Request Document</label>
                                    <div id="rfqDropzone" class="rfq-dropzone border border-dashed rounded-3 p-3 text-center" wire:ignore>
                                        <input type="file" id="rfqFileInput" class="form-control d-none" wire:model="document">
                                        <div class="rfq-dropzone-inner">
                                            <i class="bi bi-cloud-arrow-up fs-3 mb-1 text-brand"></i>
                                            <p class="mb-1 small">
                                            <strong>Drop request file here</strong> or
                                            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="rfqBrowseBtn">
                                                browse
                                            </button>
                                            </p>
                                            <p class="mb-0 text-muted-soft small">
                                            PDF, Word, Excel, PowerPoint or images. Max 10 MB.
                                            </p>
                                            <div id="rfqFileName" class="small text-brand mt-1" style="display:none;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right: info graphic / explainer -->
                            <div class="col-12 col-lg-5">
                                <div class="rfq-infobox rounded-3 p-3 h-100">
                                    <div class="d-flex align-items-center mb-2">
                                    <div class="rfq-infobox-icon me-2">
                                        <i class="bi bi-diagram-3"></i>
                                    </div>
                                    <div>
                                        <div class="small text-uppercase fw-semibold text-muted-soft" style="letter-spacing:.07em;">
                                        How procurement requests work
                                        </div>
                                        <div class="small text-muted-soft">
                                        Simple 3-step flow in Loveworld Registry.
                                        </div>
                                    </div>
                                    </div>

                                    <ol class="small mb-3 ps-3 rfq-steps">
                                    <li class="mb-2">
                                        <span class="fw-semibold text-dark">Create request</span>
                                        <br />
                                        <span class="text-muted-soft">
                                        Add the request details, optional documents, and a deadline.
                                        </span>
                                    </li>
                                    <li class="mb-2">
                                        <span class="fw-semibold text-dark">Vendors respond</span>
                                        <br />
                                        <span class="text-muted-soft">
                                        Verified businesses in the matching category are notified and can bid.
                                        </span>
                                    </li>
                                    <li>
                                        <span class="fw-semibold text-dark">Assign &amp; convert to PO</span>
                                        <br />
                                        <span class="text-muted-soft">
                                        Choose a vendor, notify bidders, and mark the request completed.
                                        </span>
                                    </li>
                                    </ol>

                                    <div class="small text-muted-soft border-top pt-2 mt-2">
                                    <i class="bi bi-shield-check me-1"></i>
                                    Procurement requests are visible only to authenticated vendors and admins.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill">Create Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
