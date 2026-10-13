<div>
    @php $hasFile = $paymentProof && $paymentProof->file(); @endphp
    @php $pendingProof = $proof; @endphp

    <div class="border rounded p-3">
        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
            <div>
                <div class="fw-semibold">Proof of payment</div>
                <div class="small text-muted">Upload a PDF, JPG, or PNG receipt/transfer proof for admin confirmation (max 10 MB).</div>
            </div>
            <div>
                @if(! $hasFile)
                    <span class="badge bg-secondary-subtle text-secondary">Not uploaded</span>
                @elseif($paymentProof->isApproved())
                    <span class="badge bg-success-subtle text-success">Approved</span>
                @elseif($paymentProof->isRejected())
                    <span class="badge bg-danger-subtle text-danger">Rejected</span>
                @else
                    <span class="badge bg-warning-subtle text-warning">Pending review</span>
                @endif
            </div>
        </div>

        @if($hasFile)
            <div class="small mt-2">
                <a href="{{ route('payment-proofs.download', $paymentProof) }}" target="_blank">
                    <i class="bi bi-paperclip"></i> View current proof
                </a>
            </div>
        @endif

        @if($hasFile && $paymentProof->isRejected() && $paymentProof->rejection_reason)
            <div class="small text-danger mt-2">
                <strong>Reason:</strong> {{ $paymentProof->rejection_reason }} — please upload a corrected proof.
            </div>
        @endif

        @if(! ($hasFile && $paymentProof->isApproved()))
            <div class="mt-3">
                <div class="input-group input-group-sm">
                    <input type="file"
                           wire:model="proof"
                           class="form-control"
                           accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                           data-form-help="Upload a clear PDF, JPG, or PNG receipt showing the amount paid and payment reference.">
                    <button type="button" class="btn btn-primary"
                            wire:click="upload"
                            wire:loading.attr="disabled"
                            wire:target="proof,upload"
                            @disabled(blank($pendingProof))>
                        <span wire:loading.remove wire:target="upload">{{ $hasFile ? 'Replace' : 'Upload' }}</span>
                        <span wire:loading wire:target="upload">Uploading…</span>
                    </button>
                </div>
                @if($pendingProof)
                    <div class="small text-success mt-1">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        {{ $pendingProof->getClientOriginalName() }} is ready to upload.
                    </div>
                @endif
                <div wire:loading wire:target="proof" class="small text-muted mt-1">Reading file…</div>
                @error('proof') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
            </div>
        @endif
    </div>
</div>
