<div class="sbo-document-remake sbo-remake">
    @if($rows->isEmpty())
        {{-- No required documents configured; nothing to show. --}}
    @else
        @php
            $requiredRows = $rows->filter(fn (array $row): bool => (bool) $row['type']->is_required);
            $approvedRequired = $requiredRows->filter(fn (array $row): bool => (bool) $row['document']?->isApproved())->count();
            $pendingRequired = $requiredRows->filter(fn (array $row): bool => $row['document'] && ! $row['document']->isApproved() && ! $row['document']->isRejected())->count();
            $remainingRequired = max(0, $requiredRows->count() - $approvedRequired);
            $documentPercent = $requiredRows->count() > 0 ? (int) round(($approvedRequired / $requiredRows->count()) * 100) : 0;
        @endphp
        <hr class="my-3">
        <div class="premium-flow-card mb-3">
            <div class="premium-flow-card__body">
                <div class="premium-flow-card__header mb-0">
                    <span class="premium-flow-card__icon" aria-hidden="true">
                        <i class="bi bi-file-earmark-check"></i>
                    </span>
                    <div>
                        <div class="premium-flow-card__eyebrow">Compliance documents</div>
                        <h3 class="premium-flow-card__title">Upload the required registration documents for your country.</h3>
                        <p class="premium-flow-card__copy small">
                            These fields are configured as required in the backend for your country. Upload each as a <strong>PDF, JPG, or PNG</strong> (max 10 MB).
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <section class="sbo-document-summary mb-3" aria-labelledby="document-summary-title">
            <div class="sbo-document-summary__header">
                <div>
                    <div class="sbo-document-summary__eyebrow">Document readiness</div>
                    <h4 id="document-summary-title" class="sbo-document-summary__title">
                        {{ $approvedRequired }} of {{ $requiredRows->count() }} required documents approved
                    </h4>
                </div>
                <span class="ui-status ui-status--{{ $documentPercent === 100 ? 'success' : 'info' }}">{{ $documentPercent }}%</span>
            </div>
            <div class="sbo-document-summary__track" role="progressbar" aria-label="Required document progress" aria-valuenow="{{ $documentPercent }}" aria-valuemin="0" aria-valuemax="100">
                <span style="width: {{ $documentPercent }}%;"></span>
            </div>
            <div class="sbo-document-summary__meta">
                <span><strong>{{ $remainingRequired }}</strong> remaining</span>
                <span><strong>{{ $pendingRequired }}</strong> pending review</span>
                <span>Upload every required file to complete compliance.</span>
            </div>
        </section>

        @foreach($rows as $row)
            @php
                $type = $row['type'];
                $doc = $row['document'];
                $hasFile = $doc && $doc->file();
                $pendingUpload = $uploads[$type->id] ?? null;
            @endphp
            <article class="premium-upload-card" wire:key="ctype-{{ $type->id }}" aria-labelledby="document-type-{{ $type->id }}">
                <div class="premium-upload-card__header">
                    <div>
                        <h4 id="document-type-{{ $type->id }}" class="premium-upload-card__title">
                            {{ $type->name }}
                            <span class="badge bg-danger-subtle text-danger ms-1">Required</span>
                            @if($type->country)
                                <span class="badge bg-info-subtle text-info ms-1">{{ $type->country->name }}</span>
                            @endif
                        </h4>
                        @if($type->description)
                            <div class="premium-upload-card__meta">{{ $type->description }}</div>
                        @endif
                    </div>
                    <div>
                        @if(! $hasFile)
                            <span class="premium-status-chip premium-status-chip--neutral">Not uploaded</span>
                        @elseif($doc->isApproved())
                            <span class="premium-status-chip premium-status-chip--success"><i class="bi bi-check2"></i> Approved</span>
                        @elseif($doc->isRejected())
                            <span class="premium-status-chip premium-status-chip--danger"><i class="bi bi-x-lg"></i> Rejected</span>
                        @else
                            <span class="premium-status-chip premium-status-chip--warning"><i class="bi bi-clock-history"></i> Pending review</span>
                        @endif
                    </div>
                </div>

                <div class="premium-upload-card__body">
                @if($hasFile && $doc->isRejected() && $doc->rejection_reason)
                    <div class="small text-danger mt-2"><strong>Reason:</strong> {{ $doc->rejection_reason }} — please re-upload a corrected document.</div>
                @endif

                @if($hasFile)
                    <div class="small mt-2">
                        <a href="{{ route('compliance-documents.download', $doc) }}" target="_blank">
                            <i class="bi bi-paperclip"></i> View current file
                        </a>
                    </div>
                @endif

                {{-- Upload field: shown unless already approved --}}
                @if(! ($hasFile && $doc->isApproved()))
                    <div class="premium-upload-card__dropzone mt-2">
                        <input type="file"
                               wire:model="uploads.{{ $type->id }}"
                               class="form-control form-control-sm"
                               accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                               data-form-help="Select the exact document requested for this row as a PDF, JPG, or PNG up to 10 MB. It will be submitted with the Next button.">
                        @if($pendingUpload)
                            <div class="small text-success mt-1">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                                {{ $pendingUpload->getClientOriginalName() }} is ready to submit with Next.
                            </div>
                        @endif
                        <div wire:loading wire:target="uploads.{{ $type->id }}" class="small text-muted mt-1">Reading file…</div>
                        @error('uploads.' . $type->id) <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
                    </div>
                @endif
                </div>
            </article>
        @endforeach
    @endif
</div>
