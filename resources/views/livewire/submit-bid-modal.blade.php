<div
    class="modal fade"
    id="submitBidModal"
    tabindex="-1"
    aria-labelledby="submitBidModalLabel"
    aria-hidden="true"
    wire:ignore.self
>
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <form wire:submit.prevent="save">
                <div class="modal-header">
                        <h5 class="modal-title" id="submitBidModalLabel">
                            Submit Bid
                        </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">

                        {{-- Procurement request context --}}
                        @if($rfq)
                            @php
                                $requestTypeLabels = [
                                    'product' => 'Product',
                                    'service' => 'Service',
                                    'product_service' => 'Product & Service',
                                ];
                                $requestType = $rfq->request_type ?? 'product';
                            @endphp
                            <div class="col-12">
                                <div class="p-3 rounded-2 bg-light">
                                    <div class="fw-semibold">{{ $rfq->title }}</div>
                                    <div class="small text-muted mb-2">
                                        {{ $requestTypeLabels[$requestType] ?? 'Product' }} request
                                        &middot;
                                        Category: {{ $rfq->category->name ?? '—' }}
                                    </div>

                                    @if($rfq->description)
                                        <p class="small mb-2">{{ $rfq->description }}</p>
                                    @endif

                                    <div class="row g-2 small">
                                        @if($rfq->budget)
                                            <div class="col-6"><strong>Budget:</strong> {{ number_format((float) $rfq->budget, 2) }}</div>
                                        @endif
                                        @if($rfq->quantity)
                                            <div class="col-6"><strong>Quantity:</strong> {{ $rfq->quantity }}</div>
                                        @endif
                                        @if($rfq->unit_specification)
                                            <div class="col-6"><strong>Unit / Specification:</strong> {{ $rfq->unit_specification }}</div>
                                        @endif
                                        @if($rfq->delivery_location)
                                            <div class="col-6"><strong>Delivery Location:</strong> {{ $rfq->delivery_location }}</div>
                                        @endif
                                        @if($rfq->service_duration)
                                            <div class="col-6"><strong>Service Duration:</strong> {{ $rfq->service_duration }}</div>
                                        @endif
                                        @if($rfq->required_expertise)
                                            <div class="col-6"><strong>Required Expertise:</strong> {{ $rfq->required_expertise }}</div>
                                        @endif
                                        @if($rfq->order_deadline)
                                            <div class="col-6"><strong>Deadline:</strong> {{ $rfq->order_deadline->format('M d, Y') }}</div>
                                        @endif
                                    </div>

                                    @if($rfq->service_scope)
                                        <div class="small mt-2">
                                            <strong>Service scope:</strong>
                                            <div class="text-muted">{{ $rfq->service_scope }}</div>
                                        </div>
                                    @endif

                                    @if($rfq->required_documents)
                                        <div class="small mt-2">
                                            <strong>Required documents:</strong>
                                            <div class="text-muted">{{ $rfq->required_documents }}</div>
                                        </div>
                                    @endif

                                    @if($rfq->terms)
                                        <div class="small mt-2">
                                            <strong>Terms &amp; conditions:</strong>
                                            <div class="text-muted">{{ $rfq->terms }}</div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        {{-- SBO --}}
                        <div class="col-12">
                            <label class="form-label" for="submitBidSbo">Submitting SBO</label>
                            <select
                                id="submitBidSbo"
                                wire:model.defer="bidder_sbo_id"
                                class="form-select @error('bidder_sbo_id') is-invalid @enderror"
                                data-form-help="Choose the fully verified SBO that will be responsible for this bid and any follow-up fulfilment."
                                @error('bidder_sbo_id') aria-describedby="submitBidSboError" aria-invalid="true" @enderror
                            >
                                <option value="">Choose verified SBO</option>
                                @foreach($verifiedSbos as $sbo)
                                    <option value="{{ $sbo->id }}">
                                        {{ $sbo->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('bidder_sbo_id')
                                <div id="submitBidSboError" class="invalid-feedback" role="alert">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Attachment --}}
                        <div class="col-md-12">
                            <label class="form-label" for="submitBidAttachment">
                                Proposal Document <span class="text-danger">*</span>
                            </label>
                            <input
                                type="file"
                                id="submitBidAttachment"
                                wire:model="attachment"
                                class="form-control @error('attachment') is-invalid @enderror"
                                required
                                @error('attachment') aria-describedby="submitBidAttachmentError" aria-invalid="true" @enderror
                            >
                            <div class="small text-muted mt-1">
                                Upload a signed proposal or supporting document (PDF, Word, Excel, PowerPoint). Max 10 MB.
                            </div>
                            @error('attachment')
                                <div id="submitBidAttachmentError" class="invalid-feedback" role="alert">{{ $message }}</div>
                            @enderror

                            <div wire:loading wire:target="attachment" class="small text-muted mt-1">
                                Uploading…
                            </div>
                        </div>

                        {{-- Proposal --}}
                        <div class="col-12">
                            <label class="form-label" for="submitBidProposal">Proposal / Notes</label>
                            <textarea
                                id="submitBidProposal"
                                wire:model.defer="proposal"
                                rows="4"
                                class="form-control @error('proposal') is-invalid @enderror"
                                placeholder="Describe your offer, timeline, and any important notes"
                                @error('proposal') aria-describedby="submitBidProposalError" aria-invalid="true" @enderror
                            required ></textarea>
                            @error('proposal')
                                <div id="submitBidProposalError" class="invalid-feedback" role="alert">{{ $message }}</div>
                            @enderror
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-outline-danger"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-cta"
                        wire:loading.attr="disabled"
                        wire:target="save,attachment"
                        @disabled($alreadyBid)
                    >
                        @if($alreadyBid)
                            <span>
                                <i class="bi bi-check-circle me-1"></i>
                                Bid Submitted
                            </span>
                        @else
                            <span wire:loading.remove wire:target="save">
                                Submit Bid
                            </span>
                            <span wire:loading wire:target="save">
                                Submitting…
                            </span>
                        @endif
                    </button>

                </div>
            </form>

        </div>
    </div>
</div>

{{-- Modal open / close sync --}}
<script>
    document.addEventListener('livewire:init', () => {

        Livewire.on('open-submit-bid-modal', () => {
            const modal = new bootstrap.Modal(
                document.getElementById('submitBidModal')
            );
            modal.show();
        });

        Livewire.on('close-submit-bid-modal', () => {
            const modalEl = document.getElementById('submitBidModal');
            const instance = bootstrap.Modal.getInstance(modalEl);
            if (instance) instance.hide();
        });

        Livewire.on('bid-submitted', () => {
            const modalEl = document.getElementById('submitBidModal');
            const instance = bootstrap.Modal.getInstance(modalEl);

            const redirect = () => {
                window.location.assign(
                    "{{ route('dashboard') }}?r=" + Date.now() + "#bids-sent"
                );
            };

            if (instance) {
                modalEl.addEventListener('hidden.bs.modal', redirect, { once: true });
                instance.hide();
            } else {
                redirect();
            }
        });

    });
</script>
