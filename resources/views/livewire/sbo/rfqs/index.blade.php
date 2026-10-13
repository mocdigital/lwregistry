<?php

use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\PurchaseOrderInvite;
use App\Models\Sbo;
use App\Notifications\PurchaseOrderInviteNotification;
use App\Support\SboNetworkAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use WithFileUploads;
    use WithPagination;

    // Create form
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
    public string $visibility = PurchaseOrder::VISIBILITY_PUBLIC;
    public array $invited_sbo_ids = [];
    public $document = null;

    /**
     * IDs of fully verified SBOs managed by the current user.
     *
     * @return array<int, int>
     */
    private function manageableSboIds(): array
    {
        return Sbo::query()
            ->where('created_by', auth()->id())
            ->get()
            ->filter(fn (Sbo $sbo): bool => $sbo->isFullyVerified())
            ->pluck('id')
            ->all();
    }

    public function createRfq(): void
    {
        $allowedSboIds = $this->manageableSboIds();
        $invitableSboIds = $this->invitableSboIds($this->sbo_id);

        $this->validate([
            // Must be one of the user's own fully verified SBOs.
            'sbo_id' => ['required', 'integer', Rule::in($allowedSboIds)],
            'category_id' => ['required', 'exists:categories,id'],
            'request_type' => ['required', Rule::in(['product', 'service', 'product_service'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'quantity' => [Rule::requiredIf(in_array($this->request_type, ['product', 'product_service'], true)), 'nullable', 'string', 'max:255'],
            'unit_specification' => ['nullable', 'string', 'max:255'],
            'delivery_location' => [Rule::requiredIf(in_array($this->request_type, ['product', 'product_service'], true)), 'nullable', 'string', 'max:255'],
            'service_scope' => [Rule::requiredIf(in_array($this->request_type, ['service', 'product_service'], true)), 'nullable', 'string', 'max:3000'],
            'service_duration' => ['nullable', 'string', 'max:255'],
            'required_expertise' => ['nullable', 'string', 'max:255'],
            'required_documents' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'order_deadline' => ['required', 'date', 'after:today'],
            'visibility' => ['required', Rule::in([PurchaseOrder::VISIBILITY_PUBLIC, PurchaseOrder::VISIBILITY_INVITE_ONLY])],
            'invited_sbo_ids' => [Rule::requiredIf($this->visibility === PurchaseOrder::VISIBILITY_INVITE_ONLY), 'array'],
            'invited_sbo_ids.*' => ['integer', Rule::in($invitableSboIds)],
            'document' => ['nullable', 'file', 'max:10240'],
        ], [
            'sbo_id.in' => 'You can only post procurement requests for a fully verified SBO that you manage.',
            'invited_sbo_ids.required' => 'Choose at least one verified SBO for an invite-only request.',
            'invited_sbo_ids.*.in' => 'One of the selected invitees is not currently eligible for private bid invitations.',
        ]);

        $path = $this->document
            ? $this->document->store('rfqs', 'public')
            : null;

        $purchaseOrder = PurchaseOrder::create([
            'sbo_id' => $this->sbo_id,
            'category_id' => $this->category_id,
            'request_type' => $this->request_type,
            'title' => $this->title,
            'description' => $this->description,
            'budget' => $this->budget !== '' ? $this->budget : null,
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
            'visibility' => $this->visibility,
        ]);

        if ($this->visibility === PurchaseOrder::VISIBILITY_INVITE_ONLY) {
            Sbo::with('creator')
                ->whereIn('id', array_unique(array_map('intval', $this->invited_sbo_ids)))
                ->get()
                ->each(function (Sbo $invitee) use ($purchaseOrder): void {
                    $invite = PurchaseOrderInvite::create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'invited_sbo_id' => $invitee->id,
                        'invited_by_sbo_id' => $purchaseOrder->sbo_id,
                    ]);

                    $invitee->creator?->notify(new PurchaseOrderInviteNotification($invite->fresh(['purchaseOrder', 'invitedBySbo'])));
                });
        }

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
            'visibility',
            'invited_sbo_ids',
            'document',
        ]);
        $this->request_type = 'product';
        $this->visibility = PurchaseOrder::VISIBILITY_PUBLIC;
        $this->dispatch('close-create-rfq-modal');
        session()->flash('success', 'Procurement request posted successfully.');
    }

    // RFQ currently being reviewed for award (its bids are shown).
    public ?int $reviewingRfqId = null;

    public function reviewBids(int $rfqId): void
    {
        $rfq = $this->ownedRfq($rfqId);
        $this->reviewingRfqId = $rfq?->id;
        $this->dispatch('open-bids-modal');
    }

    public function awardBid(int $rfqId, int $bidId): void
    {
        $rfq = $this->ownedRfq($rfqId);

        if (! $rfq || ! $rfq->isOpen()) {
            session()->flash('error', 'This procurement request can no longer be awarded.');

            return;
        }

        $bid = PurchaseOrderBid::where('purchase_order_id', $rfq->id)->findOrFail($bidId);

        if (! $bid->canBeAccepted()) {
            session()->flash('error', 'This bid can no longer be awarded.');

            return;
        }

        DB::transaction(function () use ($rfq, $bid): void {
            $bid->markAccepted();

            PurchaseOrderBid::where('purchase_order_id', $rfq->id)
                ->where('id', '!=', $bid->id)
                ->get()
                ->each
                ->markRejected();

            $rfq->update([
                'status' => 'awarded',
                'awarded_bid_id' => $bid->id,
            ]);
        });

        $this->dispatch('close-bids-modal');
        session()->flash('success', 'Bid awarded successfully. Bidders have been notified.');
    }

    /**
     * Fetch an RFQ only if it belongs to an SBO the current user manages.
     */
    private function ownedRfq(int $rfqId): ?PurchaseOrder
    {
        return PurchaseOrder::whereIn('sbo_id', Sbo::where('created_by', auth()->id())->pluck('id'))
            ->find($rfqId);
    }

    /**
     * @return array<int, int>
     */
    private function invitableSboIds(?int $ownerSboId): array
    {
        $ownerUserId = $ownerSboId
            ? Sbo::whereKey($ownerSboId)->value('created_by')
            : auth()->id();

        return Sbo::query()
            ->whereKeyNot($ownerSboId ?: 0)
            ->when($ownerUserId, fn ($query) => $query->where('created_by', '!=', $ownerUserId))
            ->orderBy('name')
            ->get()
            ->filter(fn (Sbo $sbo): bool => app(SboNetworkAccess::class)->sboCanUseNetwork($sbo))
            ->pluck('id')
            ->all();
    }

    public function with(): array
    {
        $manageableSboIds = $this->manageableSboIds();

        $rfqs = PurchaseOrder::with(['sbo', 'category'])
            ->whereIn('sbo_id', Sbo::where('created_by', auth()->id())->pluck('id'))
            ->latest()
            ->paginate(10);

        $reviewingRfq = $this->reviewingRfqId
            ? PurchaseOrder::with(['bids.business', 'bids.bidderSbo', 'bids.user'])->find($this->reviewingRfqId)
            : null;

        $ownedSbos = Sbo::where('created_by', auth()->id())->orderBy('name')->get();
        $verifiedSbos = Sbo::whereIn('id', $manageableSboIds)->orderBy('name')->get();

        return [
            'rfqs' => $rfqs,
            'ownedSbos' => $ownedSbos,
            'activeSbo' => $verifiedSbos->first() ?? $ownedSbos->first(),
            'verifiedSbos' => $verifiedSbos,
            'invitableSbos' => Sbo::whereIn('id', $this->invitableSboIds($this->sbo_id))->orderBy('name')->get(),
            'hasVerifiedSbo' => count($manageableSboIds) > 0,
            'categories' => Category::orderBy('name')->get(),
            'reviewingRfq' => $reviewingRfq,
        ];
    }
};
?>

<div class="sbo-rfq-remake sbo-remake">
    <x-ad-slot name="dashboard_top" />

    <x-ui.page-header
        title="Procurement requests"
        description="Post and manage product or service requests for eligible vendors."
        :breadcrumbs="[
            ['label' => 'SBO Workspace', 'href' => route('sbo.dashboard'), 'wireNavigate' => true],
            ['label' => 'Procurement requests', 'current' => true],
        ]"
    >
        <x-slot:meta>
            <span><strong>{{ $rfqs->total() }}</strong> requests</span>
            <span>{{ $hasVerifiedSbo ? 'Posting access available' : 'Posting access locked' }}</span>
        </x-slot:meta>
        <x-slot:actions>
            @if($hasVerifiedSbo)
                <button type="button" class="btn btn-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#createRfqModal">
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Post procurement request
                </button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.sbo-context
        :sbo="$activeSbo"
        :options="$ownedSbos"
        :show-switcher="false"
    />

    <x-ui.flash />

    @unless($hasVerifiedSbo)
        <div class="alert alert-warning">
            You don't have a fully verified SBO yet. Procurement requests can only be posted once your SBO registration, documents, and payment are fully verified.
        </div>
    @endunless

    <div class="card">
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>Title</th>
                            <th>Type</th>
                            <th>SBO</th>
                            <th>Category</th>
                            <th>Deadline</th>
                            <th>Visibility</th>
                            <th>Status</th>
                            <th>Bids</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rfqs as $rfq)
                            @php $bidCount = $rfq->bids()->count(); @endphp
                            <tr>
                                <td class="fw-semibold">{{ $rfq->title }}</td>
                                <td>
                                    @php
                                        $typeLabel = match($rfq->request_type ?? 'product') {
                                            'service' => 'Service',
                                            'product_service' => 'Product & Service',
                                            default => 'Product',
                                        };
                                    @endphp
                                    <span class="badge bg-light text-dark border">{{ $typeLabel }}</span>
                                </td>
                                <td>{{ optional($rfq->sbo)->name ?? '—' }}</td>
                                <td>{{ optional($rfq->category)->name ?? '—' }}</td>
                                <td>{{ $rfq->order_deadline?->format('d M Y') ?? '—' }}</td>
                                <td>
                                    @if($rfq->isInviteOnly())
                                        <span class="badge bg-primary-subtle text-primary border">Invite-only</span>
                                    @else
                                        <span class="badge bg-success-subtle text-success border">Public</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $rfq->statusBadgeClass() }} border">{{ $rfq->statusLabel() }}</span>
                                </td>
                                <td>{{ $bidCount }}</td>
                                <td class="text-end">
                                    @if($bidCount > 0)
                                        <button class="btn btn-sm btn-outline-primary rounded-pill" wire:click="reviewBids({{ $rfq->id }})">
                                            {{ $rfq->isOpen() ? 'Review & Award' : 'View Bids' }}
                                        </button>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No procurement requests yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($rfqs->hasPages())
                <div class="p-2 border-top">{{ $rfqs->links() }}</div>
            @endif
        </div>
    </div>

    <!-- POST PROCUREMENT REQUEST MODAL -->
    <div class="modal fade" id="createRfqModal" tabindex="-1" aria-labelledby="createRfqModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="createRfq" data-ui-form>
                    <div class="modal-header">
                        <h5 class="modal-title" id="createRfqModalLabel">Post Procurement Request</h5>
                        <x-ui.form-state label="Ready to submit" />
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <x-ui.form-errors />
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="rfqSbo">SBO</label>
                                <select id="rfqSbo" class="form-select" wire:model="sbo_id">
                                    <option value="">Select your SBO…</option>
                                    @foreach($verifiedSbos as $s)
                                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                                    @endforeach
                                </select>
                                @error('sbo_id') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="rfqCategory">Category</label>
                                <select id="rfqCategory" class="form-select" wire:model="category_id">
                                    <option value="">Choose…</option>
                                    @foreach($categories as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                @error('category_id') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Request Type</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <input type="radio" class="btn-check" id="requestTypeProduct" value="product" wire:model.live="request_type">
                                    <label class="btn btn-outline-primary rounded-pill" for="requestTypeProduct">Product</label>

                                    <input type="radio" class="btn-check" id="requestTypeService" value="service" wire:model.live="request_type">
                                    <label class="btn btn-outline-primary rounded-pill" for="requestTypeService">Service</label>

                                    <input type="radio" class="btn-check" id="requestTypeBoth" value="product_service" wire:model.live="request_type">
                                    <label class="btn btn-outline-primary rounded-pill" for="requestTypeBoth">Product &amp; Service</label>
                                </div>
                                @error('request_type') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Bid visibility</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <input type="radio" class="btn-check" id="visibilityPublic" value="{{ \App\Models\PurchaseOrder::VISIBILITY_PUBLIC }}" wire:model.live="visibility">
                                    <label class="btn btn-outline-primary rounded-pill" for="visibilityPublic">Public to verified SBOs</label>

                                    <input type="radio" class="btn-check" id="visibilityInviteOnly" value="{{ \App\Models\PurchaseOrder::VISIBILITY_INVITE_ONLY }}" wire:model.live="visibility">
                                    <label class="btn btn-outline-primary rounded-pill" for="visibilityInviteOnly">Invite-only</label>
                                </div>
                                <div class="small text-muted mt-1">Public requests are visible to all eligible verified SBOs. Invite-only requests are visible only to the SBOs you select.</div>
                                @error('visibility') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            @if($visibility === \App\Models\PurchaseOrder::VISIBILITY_INVITE_ONLY)
                                <div class="col-12">
                                    <label class="form-label" for="rfqInvitees">Invite verified SBOs</label>
                                    <select id="rfqInvitees" class="form-select" wire:model="invited_sbo_ids" multiple size="6">
                                        @foreach($invitableSbos as $invitee)
                                            <option value="{{ $invitee->id }}">{{ $invitee->name }}</option>
                                        @endforeach
                                    </select>
                                    <div class="small text-muted mt-1">Hold Command/Ctrl to choose multiple companies. Invitees receive a private bid notification.</div>
                                    @error('invited_sbo_ids') <span class="text-danger small">{{ $message }}</span> @enderror
                                    @error('invited_sbo_ids.*') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                            @endif
                            <div class="col-12">
                                <label class="form-label" for="rfqTitle">Title</label>
                                <input id="rfqTitle" class="form-control" wire:model="title" placeholder="e.g. Supply and install media equipment">
                                @error('title') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="rfqDescription">Description / Specification</label>
                                <textarea id="rfqDescription" class="form-control" rows="4" wire:model="description" placeholder="Describe what you need vendors to quote for."></textarea>
                                @error('description') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="rfqBudget">Budget <small class="text-muted">(optional)</small></label>
                                <input id="rfqBudget" type="number" step="0.01" min="0" class="form-control" wire:model="budget" placeholder="e.g. 500000">
                                @error('budget') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            @if(in_array($request_type, ['product', 'product_service'], true))
                                <div class="col-md-4">
                                    <label class="form-label" for="rfqQuantity">Quantity</label>
                                    <input id="rfqQuantity" type="text" class="form-control" wire:model="quantity" placeholder="e.g. 200 units">
                                    @error('quantity') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="rfqUnitSpecification">Unit / Specification <small class="text-muted">(optional)</small></label>
                                    <input id="rfqUnitSpecification" type="text" class="form-control" wire:model="unit_specification" placeholder="e.g. 4K display, 55 inch">
                                    @error('unit_specification') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="rfqDeliveryLocation">Delivery Location</label>
                                    <input id="rfqDeliveryLocation" type="text" class="form-control" wire:model="delivery_location" placeholder="e.g. Lagos Zone 1">
                                    @error('delivery_location') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                            @endif
                            @if(in_array($request_type, ['service', 'product_service'], true))
                                <div class="col-12">
                                    <label class="form-label" for="rfqServiceScope">Service Scope</label>
                                    <textarea id="rfqServiceScope" class="form-control" rows="3" wire:model="service_scope" placeholder="Describe the work, deliverables, and expected outcome."></textarea>
                                    @error('service_scope') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="rfqServiceDuration">Service Duration <small class="text-muted">(optional)</small></label>
                                    <input id="rfqServiceDuration" type="text" class="form-control" wire:model="service_duration" placeholder="e.g. 2 weeks, 3 months">
                                    @error('service_duration') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="rfqRequiredExpertise">Required Expertise <small class="text-muted">(optional)</small></label>
                                    <input id="rfqRequiredExpertise" type="text" class="form-control" wire:model="required_expertise" placeholder="e.g. AV installation, logistics, catering">
                                    @error('required_expertise') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                            @endif
                            <div class="col-12">
                                <label class="form-label" for="rfqRequiredDocuments">Required documents from bidders <small class="text-muted">(optional)</small></label>
                                <textarea id="rfqRequiredDocuments" class="form-control" rows="2" wire:model="required_documents" placeholder="e.g. CAC certificate, tax clearance, company profile"></textarea>
                                @error('required_documents') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="rfqTerms">Terms &amp; conditions <small class="text-muted">(optional)</small></label>
                                <textarea id="rfqTerms" class="form-control" rows="3" wire:model="terms" placeholder="Delivery terms, payment terms, compliance requirements…"></textarea>
                                @error('terms') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="rfqOrderDeadline">Order deadline</label>
                                <input id="rfqOrderDeadline" type="date" class="form-control" wire:model="order_deadline">
                                @error('order_deadline') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="rfqDocument">Document <small class="text-muted">(optional, max 10MB)</small></label>
                                <input id="rfqDocument" type="file" class="form-control" wire:model="document">
                                @error('document') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill"
                            wire:loading.attr="disabled" wire:target="createRfq,document">
                            <span wire:loading.remove wire:target="createRfq">Post Request</span>
                            <span wire:loading wire:target="createRfq">Posting…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- REVIEW & AWARD BIDS MODAL -->
    <div class="modal fade" id="bidsModal" tabindex="-1" aria-labelledby="bidsModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bidsModalLabel">Bids — {{ optional($reviewingRfq)->title }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @if($reviewingRfq)
                        @forelse($reviewingRfq->bids as $bid)
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold">{{ optional($bid->bidderSbo)->name ?? optional($bid->business)->name ?? optional($bid->user)->name }}</div>
                                        <div class="small text-muted">Submitted {{ $bid->submitted_at?->format('d M Y') }}</div>
                                    </div>
                                    @php
                                        $bb = match($bid->status) {
                                            'accepted' => ['bg-success-subtle text-success', 'Accepted'],
                                            'rejected' => ['bg-danger-subtle text-danger', 'Rejected'],
                                            default    => ['bg-warning-subtle text-warning', 'Pending'],
                                        };
                                    @endphp
                                    <span class="badge {{ $bb[0] }} border">{{ $bb[1] }}</span>
                                </div>
                                <p class="small mt-2 mb-2">{{ $bid->proposal }}</p>
                                @if($bid->attachment_path)
                                    {{-- Not asset('storage/…'): live nginx returns 403 for /storage/* before
                                         Laravel sees it. The public disk's own url() points at the /media
                                         route, which is the one that serves uploads. --}}
                                    <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($bid->attachment_path) }}" target="_blank" class="small">
                                        <i class="bi bi-paperclip"></i> View proposal document
                                    </a>
                                @endif
                                @if($reviewingRfq->isOpen())
                                    <div class="text-end mt-2">
                                        <button class="btn btn-sm btn-success rounded-pill"
                                            wire:click="awardBid({{ $reviewingRfq->id }}, {{ $bid->id }})"
                                            wire:confirm="Award this procurement request to this vendor? All other bids will be rejected and bidders notified.">
                                            Award to this vendor
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="text-muted text-center py-3">No bids submitted.</p>
                        @endforelse
                    @endif
                </div>
            </div>
        </div>
    </div>

    @script
    <script>
        const shouldOpenCreateRfq = @js(request()->boolean('create') && $hasVerifiedSbo);
        const createRfqModalKey = `${window.location.pathname}${window.location.search}`;

        if (shouldOpenCreateRfq && window.__sboCreateRfqModalOpenedFor !== createRfqModalKey) {
            window.__sboCreateRfqModalOpenedFor = createRfqModalKey;

            requestAnimationFrame(() => {
                const el = document.getElementById('createRfqModal');
                if (el && window.bootstrap) {
                    new bootstrap.Modal(el).show();
                }
            });
        }

        window.addEventListener('close-create-rfq-modal', () => {
            const el = document.getElementById('createRfqModal');
            const inst = el ? bootstrap.Modal.getInstance(el) : null;
            if (inst) inst.hide();
        });
        window.addEventListener('open-bids-modal', () => {
            new bootstrap.Modal(document.getElementById('bidsModal')).show();
        });
        window.addEventListener('close-bids-modal', () => {
            const el = document.getElementById('bidsModal');
            const inst = el ? bootstrap.Modal.getInstance(el) : null;
            if (inst) inst.hide();
        });
    </script>
    @endscript
</div>
