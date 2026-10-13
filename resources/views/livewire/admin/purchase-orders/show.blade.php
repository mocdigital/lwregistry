<?php

use App\Models\ActivityLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Support\AdminCapabilities;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Procurement Request Details',
        'dataPage' => 'purchase-orders',
        'pageTitle' => 'Procurement Request Details',
    ]
)] class extends Component {

    public PurchaseOrder $purchaseOrder;
    public ?string $award_note = null;

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        $this->purchaseOrder = $purchaseOrder->load([
            'sbo',
            'category',
            'bids.business',
            'bids.bidderSbo',
            'bids.user',
            // The Q&A thread and the attachment list, neither of which this
            // page could show — an admin investigating a procurement dispute
            // saw less than either party to it.
            'questions.user',
            'questions.sbo',
            'documents',
            'awardedBid',
        ]);
        $this->award_note = $this->purchaseOrder->award_note;
    }

    public function awardBid(int $bidId): void
    {
        abort_unless(auth()->user()->hasPermission(AdminCapabilities::AWARD_BIDS), 403);

        if (! $this->purchaseOrder->isOpen()) {
            session()->flash('error', 'This procurement request can no longer be awarded.');

            return;
        }

        $this->validate([
            'award_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $bid = PurchaseOrderBid::where('purchase_order_id', $this->purchaseOrder->id)
            ->findOrFail($bidId);

        if (! $bid->canBeAccepted()) {
            session()->flash('error', 'This bid can no longer be awarded.');

            return;
        }

        DB::transaction(function () use ($bid): void {

            $bid->markAccepted();

            PurchaseOrderBid::where('purchase_order_id', $this->purchaseOrder->id)
                ->where('id', '!=', $bid->id)
                ->get()
                ->each
                ->markRejected();

            $this->purchaseOrder->update([
                'status' => 'awarded',
                'awarded_bid_id' => $bid->id,
                'award_note' => $this->award_note ?: null,
            ]);
        });

        // Reload relationships
        $this->purchaseOrder->refresh()->load([
            'bids.business',
            'bids.bidderSbo',
            'bids.user',
            // The Q&A thread and the attachment list, neither of which this
            // page could show — an admin investigating a procurement dispute
            // saw less than either party to it.
            'questions.user',
            'questions.sbo',
            'documents',
            'awardedBid',
        ]);

        ActivityLog::create([
            'type' => 'rfq_awarded',
            'title' => $this->purchaseOrder->title,
            'description' => 'Procurement request awarded by <strong>' . (auth()->user()->name ?? 'admin') . '</strong>.',
            'status' => 'awarded',
            'user_id' => auth()->id(),
        ]);

        session()->flash('success', 'Bid awarded successfully. Bidders have been notified.');
    }

    public function confirmAward(int $bidId): void
    {
        $this->dispatch('confirm-award-bid', bidId: $bidId);
    }

    public function confirmMarkCompleted(): void
    {
        $this->dispatch('confirm-mark-completed');
    }

    public function markCompleted(): void
    {
        abort_unless(auth()->user()->hasPermission(AdminCapabilities::MANAGE_RFQS), 403);

        if (! $this->purchaseOrder->isAwarded()) {
            return;
        }

        $this->purchaseOrder->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->purchaseOrder->refresh();

        ActivityLog::create([
            'type' => 'rfq_completed',
            'title' => $this->purchaseOrder->title,
            'description' => 'Procurement request marked completed by <strong>' . (auth()->user()->name ?? 'admin') . '</strong>.',
            'status' => 'completed',
            'user_id' => auth()->id(),
        ]);

        session()->flash('success', 'Procurement request marked as completed.');
    }


};
?>
<div>

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h5 mb-1">{{ $purchaseOrder->title }}</h2>
            <p class="text-muted-soft small mb-0">
                Procurement request details and vendor responses
            </p>
        </div>

        <a
            href="{{ route('admin.purchase-orders.index') }}"
            class="btn btn-sm btn-outline-secondary rounded-pill"
        >
            ← Back to Requests
        </a>
    </div>

    <!-- REQUEST DETAILS -->
    <div class="card mb-4">
        <div class="card-body">

            <div class="row g-3">

                <div class="col-md-6">
                    <div class="small text-muted-soft">Organization</div>
                    <div class="fw-semibold">{{ $purchaseOrder->sbo->name }}</div>
                </div>

                <div class="col-md-3">
                    <div class="small text-muted-soft">Industry</div>
                    <div>{{ $purchaseOrder->category->name }}</div>
                </div>

                <div class="col-md-3">
                    <div class="small text-muted-soft">Deadline</div>
                    <div>{{ $purchaseOrder->order_deadline?->format('Y-m-d') ?? '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="small text-muted-soft">Request Type</div>
                    <div>
                        @php
                            $typeLabel = match($purchaseOrder->request_type ?? 'product') {
                                'service' => 'Service',
                                'product_service' => 'Product & Service',
                                default => 'Product',
                            };
                        @endphp
                        {{ $typeLabel }}
                    </div>
                </div>

                @if($purchaseOrder->budget)
                    <div class="col-md-3">
                        <div class="small text-muted-soft">Budget</div>
                        <div>{{ number_format((float) $purchaseOrder->budget, 2) }}</div>
                    </div>
                @endif

                @if($purchaseOrder->quantity)
                    <div class="col-md-3">
                        <div class="small text-muted-soft">Quantity</div>
                        <div>{{ $purchaseOrder->quantity }}</div>
                    </div>
                @endif

                @if($purchaseOrder->unit_specification)
                    <div class="col-md-3">
                        <div class="small text-muted-soft">Unit / Specification</div>
                        <div>{{ $purchaseOrder->unit_specification }}</div>
                    </div>
                @endif

                @if($purchaseOrder->delivery_location)
                    <div class="col-md-3">
                        <div class="small text-muted-soft">Delivery Location</div>
                        <div>{{ $purchaseOrder->delivery_location }}</div>
                    </div>
                @endif

                <div class="col-md-12">
                    <div class="small text-muted-soft">Description</div>
                    <div class="mt-1">
                        {{ $purchaseOrder->description }}
                    </div>
                </div>

                @if($purchaseOrder->required_documents)
                    <div class="col-md-12">
                        <div class="small text-muted-soft">Required Documents from Bidders</div>
                        <div class="mt-1">{{ $purchaseOrder->required_documents }}</div>
                    </div>
                @endif

                @if($purchaseOrder->service_scope)
                    <div class="col-md-12">
                        <div class="small text-muted-soft">Service Scope</div>
                        <div class="mt-1">{{ $purchaseOrder->service_scope }}</div>
                    </div>
                @endif

                @if($purchaseOrder->service_duration)
                    <div class="col-md-4">
                        <div class="small text-muted-soft">Service Duration</div>
                        <div>{{ $purchaseOrder->service_duration }}</div>
                    </div>
                @endif

                @if($purchaseOrder->required_expertise)
                    <div class="col-md-4">
                        <div class="small text-muted-soft">Required Expertise</div>
                        <div>{{ $purchaseOrder->required_expertise }}</div>
                    </div>
                @endif

                @if($purchaseOrder->terms)
                    <div class="col-md-12">
                        <div class="small text-muted-soft">Terms &amp; Conditions</div>
                        <div class="mt-1">{{ $purchaseOrder->terms }}</div>
                    </div>
                @endif

                <div class="col-md-4">
                    <div class="small text-muted-soft">Status</div>
                    <span class="badge {{ $purchaseOrder->statusBadgeClass() }}">{{ $purchaseOrder->statusLabel() }}</span>
                </div>

                <div class="col-md-4">
                    <div class="small text-muted-soft">Visibility</div>
                    @if($purchaseOrder->isInviteOnly())
                        <span class="badge bg-primary-subtle text-primary border">Invite-only</span>
                    @else
                        <span class="badge bg-success-subtle text-success border">Public</span>
                    @endif
                </div>

                @if($purchaseOrder->isAwarded())
                <div class="col-md-4">
                    <div class="small text-muted-soft"> Update Status </div>
                    <button
                        class="btn btn-sm btn-success rounded-pill"
                        wire:click="markCompleted"
                        wire:loading.attr="disabled"
                        onclick="return confirm('Mark this procurement request as completed?\n\nThis confirms the awarded vendor has fulfilled the engagement.')"
                    >
                        Mark Request as Completed
                    </button>
                </div>
                @elseif($purchaseOrder->isCompleted())
                <div class="col-md-4 mt-2">
                    <div class="small text-muted-soft"> Completed </div>                     
                    <span class="badge bg-success">
                        {{ $purchaseOrder->completed_at?->format('M d, Y') }}
                    </span>
                </div>
                @endif          
                
                <div class="col-md-4">
                    <div class="small text-muted-soft">Request Document</div>
                    @if($purchaseOrder->document_path)
                        <a
                            href="{{ Storage::disk('public')->url($purchaseOrder->document_path) }}"
                            target="_blank"
                            class="btn btn-sm btn-outline-primary rounded-pill mt-1"
                        >
                            View document
                        </a>
                    @else
                        <div class="text-muted-soft">No document uploaded</div>
                    @endif
                </div>

            </div>
        </div>
    </div>

    {{--
        RFP SCHEDULE
        The dates a bidder plans around. A request used to carry one date, so
        this panel did not exist; the other nine columns were invisible to
        admins even though both parties can see them.
        Only the dates actually set are listed.
    --}}
    @php
        $schedule = collect([
            ['RFP advertised', $purchaseOrder->advertised_at?->format('M d, Y')],
            [
                $purchaseOrder->briefing_mandatory
                    ? 'Pre-proposal briefing (mandatory)'
                    : 'Pre-proposal briefing',
                $purchaseOrder->briefing_at?->format('M d, Y, g:ia'),
            ],
            ['Inquiry period opens', $purchaseOrder->inquiry_opens_at?->format('M d, Y')],
            ['Inquiry period closes', $purchaseOrder->inquiry_closes_at?->format('M d, Y')],
            ['Responses to inquiries sent', $purchaseOrder->inquiry_responses_at?->format('M d, Y')],
            ['Proposals due', $purchaseOrder->order_deadline?->format('M d, Y')],
            ['Final selection anticipated', $purchaseOrder->final_selection_at?->format('M d, Y')],
        ])->filter(fn ($row) => filled($row[1]));
    @endphp

    @if($schedule->isNotEmpty())
        <div class="card mb-4">
            <div class="card-header">Procurement schedule</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            @foreach($schedule as [$label, $value])
                                <tr>
                                    <th scope="row" class="fw-semibold" style="width: 260px;">{{ $label }}</th>
                                    <td>{{ $value }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($purchaseOrder->briefing_link || $purchaseOrder->briefing_location)
                    <div class="small mt-3">
                        @if($purchaseOrder->briefing_location)
                            <div><strong>Venue:</strong> {{ $purchaseOrder->briefing_location }}</div>
                        @endif
                        @if($purchaseOrder->briefing_link)
                            <div class="text-break">
                                <strong>Meeting link:</strong>
                                <a href="{{ $purchaseOrder->briefing_link }}" target="_blank" rel="noopener">
                                    {{ $purchaseOrder->briefing_link }}
                                </a>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{--
        ATTACHMENTS
        `purchase_order_documents` — a request can hold many files now, where
        `document_path` above is the single legacy upload.
    --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Attachments</span>
            <span class="badge-secondary-soft rounded-pill">{{ $purchaseOrder->documents->count() }}</span>
        </div>
        <div class="card-body">
            @forelse($purchaseOrder->documents as $document)
                <div class="d-flex justify-content-between align-items-center gap-3 py-2 {{ ! $loop->first ? 'border-top' : '' }}">
                    <div class="min-w-0">
                        <div class="fw-semibold text-break">{{ $document->name }}</div>
                        <div class="small text-muted-soft">
                            {{ ucfirst($document->section) }}
                            @if($document->humanSize())
                                <span class="mx-1">&middot;</span>{{ $document->humanSize() }}
                            @endif
                            @if($document->created_at)
                                <span class="mx-1">&middot;</span>{{ $document->created_at->format('M d, Y') }}
                            @endif
                        </div>
                    </div>
                    <a
                        href="{{ $document->url() }}"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-sm btn-outline-secondary rounded-pill flex-shrink-0"
                    >
                        Open
                    </a>
                </div>
            @empty
                <div class="admin-empty">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                    <h3>No attachments</h3>
                    <p>Files the buyer attaches to this request will be listed here.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{--
        Q&A
        The public thread. The asker is anonymised to rival bidders on the
        buyer's screen; an admin reviewing a dispute sees who actually asked.
    --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Questions &amp; answers</span>
            <span class="badge-secondary-soft rounded-pill">{{ $purchaseOrder->questions->count() }}</span>
        </div>
        <div class="card-body">
            @forelse($purchaseOrder->questions as $question)
                <div class="py-3 {{ ! $loop->first ? 'border-top' : '' }}">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div class="fw-semibold">{{ $question->title ?: 'Question' }}</div>
                        <span class="{{ $question->isAnswered() ? 'badge-success-soft' : 'badge-warning-soft' }} rounded-pill">
                            {{ $question->isAnswered() ? 'Answered' : 'Awaiting answer' }}
                        </span>
                    </div>

                    <div class="small text-muted-soft mb-2">
                        {{ $question->sbo?->name ?? $question->user?->email ?? 'Member' }}
                        @if($question->created_at)
                            <span class="mx-1">&middot;</span>{{ $question->created_at->format('M d, Y') }}
                        @endif
                    </div>

                    <div class="small">{{ $question->body }}</div>

                    @if($question->isAnswered())
                        <div class="small mt-2 ps-3 border-start">
                            <div class="text-muted-soft">
                                Buyer replied
                                @if($question->answered_at)
                                    {{ $question->answered_at->format('M d, Y') }}
                                @endif
                            </div>
                            <div>{{ $question->answer }}</div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="admin-empty">
                    <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                    <h3>No questions yet</h3>
                    <p>Questions bidders ask about this request will appear here with the buyer's replies.</p>
                </div>
            @endforelse
        </div>
    </div>

    <!-- BIDS TABLE -->
    <div class="card">
        <div class="card-body p-3">

            <h6 class="mb-3">Bid Comparison</h6>

            @if($purchaseOrder->isOpen() && $purchaseOrder->bids->isNotEmpty())
                <div class="mb-3">
                    <label class="form-label">Award note <small class="text-muted">(optional internal reason)</small></label>
                    <textarea class="form-control" rows="2" wire:model.defer="award_note" placeholder="Add a short reason before awarding a vendor."></textarea>
                    @error('award_note') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
            @elseif($purchaseOrder->award_note)
                <div class="alert alert-light border small">
                    <span class="fw-semibold">Award note:</span> {{ $purchaseOrder->award_note }}
                </div>
            @endif

            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>SBO</th>
                            <th>Bidder</th>
                            <th>Proposal</th>
                            <th>Attachment</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($purchaseOrder->bids as $bid)
                            <tr>
                                <td class="fw-semibold">
                                    {{ $bid->bidderSbo?->name ?? $bid->business?->name ?? '—' }}
                                </td>

                                <td>
                                    <div>{{ $bid->user?->name ?? '—' }}</div>
                                    <div class="small text-muted-soft">{{ $bid->user?->email }}</div>
                                </td>

                                <td class="text-muted-soft small">
                                    {{ Str::limit($bid->proposal, 80) }}
                                </td>

                                <td>
                                    @if($bid->attachment_path)
                                        <a
                                            href="{{ Storage::disk('public')->url($bid->attachment_path) }}"
                                            target="_blank"
                                            class="btn btn-sm btn-outline-secondary rounded-pill"
                                        >
                                            View
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>

                                <td class="small">
                                    {{ $bid->submitted_at?->format('M d, Y') ?? '—' }}
                                </td>

                                <td>
                                    @if($bid->isAccepted())
                                        <span class="badge bg-success-subtle text-success">Accepted</span>
                                    @elseif($bid->isRejected())
                                        <span class="badge bg-danger-subtle text-danger">Rejected</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary">Pending</span>
                                    @endif
                                </td>

                                <td class="text-end">
                                    @if($purchaseOrder->isOpen())
                                    <button
                                        class="btn btn-sm btn-outline-success rounded-pill"
                                        wire:click="awardBid({{ $bid->id }})"
                                        onclick="return confirm('Award this procurement request to this vendor? All other bidders will be notified that they were not selected.')"
                                    >
                                        Award
                                    </button>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted-soft py-4">
                                    No bids submitted yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</div>
