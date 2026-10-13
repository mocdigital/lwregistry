<div>
    <hr class="my-3">

    <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">
        <div>
            <div class="small text-muted">Registration payment</div>
            <div class="fw-semibold">Complete the fee step to continue your application.</div>
        </div>
        <x-ui.form-state />
    </div>

    @if (session('fees_success'))
        <div class="alert alert-success py-2 small">{{ session('fees_success') }}</div>
    @endif
    @if (session('fees_error'))
        {{-- Inline fallback (also shown as a modal below when JS is available). --}}
        <div class="alert alert-danger py-2 small">{{ session('fees_error') }}</div>
    @endif

    {{-- ============ Selection stage (before the fee is confirmed) ============ --}}
    @unless($this->confirmed)
        <div class="premium-flow-card mb-3">
            <div class="premium-flow-card__body">
                <div class="premium-flow-card__header">
                    <span class="premium-flow-card__icon" aria-hidden="true">
                        <i class="bi bi-calculator"></i>
                    </span>
                    <div>
                        <div class="premium-flow-card__eyebrow">Registration fee setup</div>
                        <h3 class="premium-flow-card__title">Choose the values that determine your fee.</h3>
                        <p class="premium-flow-card__copy small">
                            The issued shares range and per-share value help the registry calculate a valid share-capital fee before payment.
                        </p>
                    </div>
                </div>

        {{-- Step 0: share-structure gate --}}
        <div class="mb-3">
            <label class="form-label small fw-semibold">Share structure</label>
            <select class="form-select form-select-sm" wire:model.live="shareStructure" data-form-help="Choose the legal share structure that applies to this SBO. Private and public company selections require share-capital details.">
                <option value="">— Select your company structure —</option>
                @foreach($structures as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('shareStructure') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
        </div>

        {{-- Share-capital inputs (share structures only) --}}
        @if($this->isShareStructure)
            <div class="row g-2 mb-2">
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Share capital type</label>
                    <select class="form-select form-select-sm" wire:model.live="shareCapitalType" data-form-help="Select the share-capital type configured for this company structure. This helps match the correct fee schedule.">
                        <option value="">— Select —</option>
                        @foreach($capitalTypes as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('shareCapitalType') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Issued Shares Range</label>
                    <select class="form-select form-select-sm" wire:model.live="selectedFeeBandId" data-form-help="Choose the issued shares range that matches the company. The system uses this band to compute a valid issued-share value and fee.">
                        <option value="">— Select fee band —</option>
                        @foreach($feeBands as $band)
                            <option value="{{ $band['id'] }}">{{ $band['display_label'] }}</option>
                        @endforeach
                    </select>
                    @error('selectedFeeBandId') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Per-share value</label>
                    <input type="number" min="0.01" step="0.01" class="form-control form-control-sm"
                           wire:model.live="perShareValue" placeholder="Type 1 if not sure"
                           data-form-help="Enter the value of one share. Type 1 if you are unsure so the system can estimate the issued shares inside the selected range.">
                    @error('perShareValue') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
                </div>
            </div>
        @endif

        {{-- Live fee reveal --}}
        @if($this->fee !== null)
            <div class="premium-summary-panel mb-3">
                <div class="premium-summary-panel__label">Calculated registration fee</div>
                <div class="premium-summary-panel__value">{{ number_format($this->fee) }} {{ $currency }}</div>
                @if($this->isShareStructure)
                    <div class="premium-summary-list">
                    @if($selectedBand)
                        <div class="premium-summary-list__item">
                            <span>Selected band</span>
                            <strong>{{ $selectedBand['label'] }} <span class="text-muted fw-normal">({{ $selectedBand['range_label'] }})</span></strong>
                        </div>
                    @endif
                    @if($computedIssuedShares !== null && $computedEquity !== null)
                        <div class="premium-summary-list__item">
                            <span>Computed issued shares</span>
                            <strong>{{ number_format($computedIssuedShares) }}</strong>
                        </div>
                        <div class="premium-summary-list__item">
                            <span>Computed share capital value</span>
                            <strong>{{ number_format($computedEquity, 2) }}</strong>
                        </div>
                    @endif
                    </div>
                @endif
            </div>
            <div class="premium-action-bar">
                <span class="small text-muted">Confirm this fee to reveal payment options.</span>
                <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">
                        <span wire:loading.remove wire:target="saveDraft">Save draft</span>
                        <span wire:loading wire:target="saveDraft">Saving…</span>
                    </button>
                    <button type="button" class="btn btn-primary rounded-pill px-4" wire:click="confirmSelections" wire:loading.attr="disabled" wire:target="confirmSelections">
                        <span wire:loading.remove wire:target="confirmSelections">Confirm fee</span>
                        <span wire:loading wire:target="confirmSelections">Confirming…</span>
                    </button>
                </div>
            </div>
        @elseif($this->hasCompleteSelection && $selectedBand && $computedIssuedShares === null)
            <div class="alert alert-warning py-2 small mb-2">
                The selected fee band cannot be used with this per-share value. Please choose another band or lower the per-share value.
            </div>
        @elseif($this->hasCompleteSelection)
            <div class="alert alert-danger py-2 small mb-2">
                The registration fee schedule is not configured for this selection. Please contact support.
            </div>
        @endif
        @if($this->fee === null)
            <div class="d-flex justify-content-end mt-3">
                <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">
                    <span wire:loading.remove wire:target="saveDraft"><i class="bi bi-bookmark me-1" aria-hidden="true"></i>Save fee setup</span>
                    <span wire:loading wire:target="saveDraft">Saving…</span>
                </button>
            </div>
        @endif
        @error('feeSchedule') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
            </div>
        </div>
    @else
        {{-- ============ Payment stage (fee confirmed) ============ --}}
        @php $sbo = $this->sbo; @endphp
        @php $checkoutAmount = $isTestAccount ? $testCheckoutAmount : (int) $sbo->calculated_fee; @endphp
        @php $paymentConfirmed = $sbo->feesPaid(); $paymentSubmitted = $sbo->hasSubmittedPayment(); @endphp
        @if($isTestAccount)
            <div class="premium-summary-panel mb-3">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <div class="premium-summary-panel__label">Amount due</div>
                        <div class="premium-summary-panel__value">{{ $testCheckoutAmount }} Espee</div>
                        <div class="small text-muted">Fixed test checkout amount for this account.</div>
                    </div>
                    <span class="ui-status ui-status--info"><i class="bi bi-beaker" aria-hidden="true"></i> Test payment</span>
                </div>
            </div>
        @else
            <div class="premium-summary-panel mb-3">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                        <div class="premium-summary-panel__label">Amount due</div>
                        <div class="premium-summary-panel__value">{{ number_format((int) $sbo->calculated_fee) }} {{ $currency }}</div>
                        @if($sbo->fee_band)
                            <div class="small text-muted">Band: {{ $sbo->fee_band }}</div>
                        @endif
                    </div>
                    @unless($sbo->hasSubmittedPayment())
                        <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" wire:click="editSelections">Change selection</button>
                    @endunless
                </div>
            </div>
        @endif

        <div class="sbo-payment-journey mb-3" aria-label="Registration payment progress">
            <div class="sbo-payment-journey__step is-complete">
                <span class="sbo-payment-journey__icon" aria-hidden="true"><i class="bi bi-check2"></i></span>
                <span>
                    <strong>Fee confirmed</strong>
                    <small>Your amount is locked for this checkout.</small>
                </span>
            </div>
            <div class="sbo-payment-journey__connector {{ $paymentConfirmed ? 'is-complete' : '' }}" aria-hidden="true"></div>
            <div class="sbo-payment-journey__step {{ $paymentConfirmed ? 'is-complete' : 'is-current' }}">
                <span class="sbo-payment-journey__icon" aria-hidden="true">
                    <i class="bi {{ $paymentConfirmed ? 'bi-check2' : ($paymentSubmitted ? 'bi-clock-history' : 'bi-credit-card') }}"></i>
                </span>
                <span>
                    <strong>{{ $paymentConfirmed ? 'Payment confirmed' : ($paymentSubmitted ? 'Payment under review' : 'Complete payment') }}</strong>
                    <small>{{ $paymentConfirmed ? 'You can continue to required documents.' : ($paymentSubmitted ? 'The Registry team is checking your proof.' : 'Choose Espees or manual payment.') }}</small>
                </span>
            </div>
            <div class="sbo-payment-journey__connector {{ $sbo->documentsCompliant() ? 'is-complete' : '' }}" aria-hidden="true"></div>
            <div class="sbo-payment-journey__step {{ $sbo->documentsCompliant() ? 'is-complete' : '' }}">
                <span class="sbo-payment-journey__icon" aria-hidden="true"><i class="bi {{ $sbo->documentsCompliant() ? 'bi-check2' : 'bi-file-earmark-text' }}"></i></span>
                <span>
                    <strong>Documents</strong>
                    <small>{{ $sbo->documentsCompliant() ? 'All required documents approved.' : 'Unlocked after payment confirmation.' }}</small>
                </span>
            </div>
        </div>

        @if($sbo->feesPaid())
            <div class="ui-surface ui-payment-status p-3 mb-3" role="status">
                <div class="d-flex align-items-start gap-3">
                    <span class="ui-payment-status__icon" aria-hidden="true"><i class="bi bi-check2"></i></span>
                    <div>
                        <div class="fw-semibold">Registration payment confirmed</div>
                        <div class="small text-muted">
                            Your payment has been verified{{ $sbo->payment_confirmed_at ? ' on ' . $sbo->payment_confirmed_at->format('M j, Y') : '' }}. No further payment action is required.
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if($canUseEspeesCheckout && ! $sbo->feesPaid() && ! $sbo->hasSubmittedPayment())
                <div class="premium-payment-card p-3 mb-3" role="region" aria-label="Espees payment">
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi {{ $isTestAccount ? 'bi-beaker' : 'bi-shield-check' }} mt-1 text-success" aria-hidden="true"></i>
                        <div class="flex-grow-1">
                            @if($isTestAccount)
                                <div class="small fw-semibold text-muted">Espees test checkout</div>
                                <div class="fw-semibold">Pay securely with Espees</div>
                                <div class="small">This option is restricted to the Espees test account and will charge exactly <strong>{{ $testCheckoutAmount }} Espee</strong>.</div>
                            @else
                                <div class="fw-semibold">Pay securely with Espees</div>
                                <div class="small">Complete payment of <strong>{{ number_format($checkoutAmount) }} {{ $currency }}</strong> through Espees. Your payment will be verified server-side before registration access is unlocked.</div>
                            @endif
                            <form method="POST" action="{{ route('sbo.payments.espees.start', $sbo) }}" class="mt-2" data-ui-form>
                                @csrf
                                <button type="submit" class="btn btn-warning btn-sm rounded-pill">
                                    <i class="bi bi-credit-card me-1" aria-hidden="true"></i>
                                    @if($isTestAccount)
                                        Pay {{ $testCheckoutAmount }} Espee with Espees
                                    @else
                                        Pay {{ number_format($checkoutAmount) }} {{ $currency }} with Espees
                                    @endif
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
        @elseif($isTestAccount && ! $sbo->feesPaid())
                <div class="alert alert-warning py-2 small mb-3">
                    Espees test checkout is not configured. Please enable Espees and save the test credentials before continuing.
                </div>
        @endif

        @if(! $isTestAccount && ! $sbo->feesPaid())
            {{-- Payment-submitted status stays visible while the manual proof is reviewed. --}}
            @if($sbo->hasSubmittedPayment())
                <div class="sbo-manual-status mb-3">
                    <div class="alert alert-warning py-2 small mb-0 d-flex align-items-start gap-2">
                        <i class="bi bi-clock-history mt-1"></i>
                        <div>
                            <div>Payment submitted{{ $sbo->payment_submitted_at ? ' on ' . $sbo->payment_submitted_at->format('d M Y') : '' }} — awaiting administrator confirmation.</div>
                            @if($sbo->paymentProof?->file())
                                <a class="small d-inline-block mt-1" href="{{ route('payment-proofs.download', $sbo->paymentProof) }}" target="_blank">
                                    <i class="bi bi-paperclip"></i> View submitted proof
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- ============ Manual payment (the normal SBO registration method) ============ --}}
            <details class="sbo-manual-pay mb-3">
                <summary class="sbo-manual-pay__summary">
                    <span class="sbo-manual-pay__summary-main">
                        <i class="bi bi-bank2 sbo-manual-pay__summary-icon" aria-hidden="true"></i>
                        <span>
                            <span class="sbo-manual-pay__summary-title">Manual payment</span>
                            <span class="sbo-manual-pay__summary-sub">Pay in the {{ $merchant['app'] }} app and upload your proof</span>
                        </span>
                    </span>
                    <i class="bi bi-chevron-down sbo-manual-pay__chevron" aria-hidden="true"></i>
                </summary>

                <div class="sbo-manual-pay__body">
                    <div class="sbo-payment-card-stack">
                        <div class="sbo-payment-card-stack__shine" aria-hidden="true"></div>
                        <div class="sbo-payment-card-stack__content">
                            <div class="sbo-payment-card-stack__header">
                                <span class="sbo-payment-card-stack__icon" aria-hidden="true">
                                    <i class="bi bi-bank"></i>
                                </span>
                                <div>
                                    <div class="sbo-payment-card-stack__eyebrow">Registration payment</div>
                                    <div class="sbo-payment-card-stack__title">How to pay</div>
                                </div>
                            </div>

                            <p class="sbo-payment-card-stack__copy">
                                Transfer <strong>{{ number_format((int) $sbo->calculated_fee) }} {{ $currency }}</strong>
                                using the <strong>{{ $merchant['app'] }}</strong> app.
                            </p>

                            <div class="sbo-payment-card-stack__merchant">
                                <div class="sbo-payment-card-stack__label">Merchant code</div>
                                <div class="sbo-payment-card-stack__code-shell">
                                    <code data-merchant-code-value>{{ $merchant['code'] }}</code>
                                    <button
                                        type="button"
                                        class="sbo-payment-card-stack__copy-btn"
                                        data-copy-merchant-code
                                        data-code="{{ $merchant['code'] }}"
                                        aria-label="Copy merchant code {{ $merchant['code'] }}"
                                    >
                                        <i class="bi bi-clipboard" aria-hidden="true"></i>
                                        <span>Copy</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 small mt-3 mb-0" role="alert">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                        Facing issues with your payment? Please contact <strong>@moc-itdept</strong> on KingsChat for assistance.
                    </div>

                    {{-- Reference + proof upload: only relevant to the manual path. --}}
                    <div class="sbo-manual-pay__fields mt-3">
                        <div class="mb-3">
                            <label for="sbo-payment-reference" class="form-label small fw-semibold">Payment reference <span class="text-muted fw-normal">(optional)</span></label>
                            <input id="sbo-payment-reference" type="text" class="form-control form-control-sm" wire:model="paymentReference" placeholder="Transaction ID / reference"
                                   data-form-help="Enter the Espees transfer reference or transaction ID from your manual payment receipt.">
                            @error('paymentReference') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
                        </div>

                        <label for="sbo-payment-proof" class="form-label small fw-semibold">Proof of payment <span class="text-muted fw-normal">(PDF, JPG, or PNG, max 10 MB)</span></label>
                        <div class="input-group input-group-sm premium-upload-card__dropzone">
                            <input id="sbo-payment-proof" type="file" wire:model="proof" class="form-control"
                                   accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                   data-form-help="Upload a clear PDF, JPG, or PNG receipt showing the amount paid, merchant code, and payment reference.">
                            <button type="button" class="btn btn-primary"
                                    wire:click="submitProof"
                                    wire:loading.attr="disabled"
                                    wire:target="proof,submitProof"
                                    @disabled(blank($proof))>
                                <span wire:loading.remove wire:target="submitProof">{{ $sbo->hasSubmittedPayment() ? 'Replace proof' : 'Submit proof' }}</span>
                                <span wire:loading wire:target="submitProof">Submitting…</span>
                            </button>
                        </div>
                        @if($proof)
                            <div class="small text-success mt-1">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                                {{ $proof->getClientOriginalName() }} is ready to submit.
                            </div>
                        @endif
                        <div wire:loading wire:target="proof" class="small text-muted mt-1">Reading file…</div>
                        @error('proof') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>
            </details>
        @endif
    @endunless
</div>

@script
<script>
    if (! window.__merchantCodeCopyBound) {
        window.__merchantCodeCopyBound = true;

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-copy-merchant-code]');

            if (! button) {
                return;
            }

            event.preventDefault();

            const code = button.dataset.code || '';
            const originalHtml = button.dataset.originalHtml || button.innerHTML;
            button.dataset.originalHtml = originalHtml;

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(code);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.value = code;
                    textarea.setAttribute('readonly', '');
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                }

                button.innerHTML = '<i class="bi bi-check2" aria-hidden="true"></i><span>Copied</span>';
                button.classList.add('is-copied');
                window.setTimeout(() => {
                    button.innerHTML = originalHtml;
                    button.classList.remove('is-copied');
                }, 1800);
            } catch (error) {
                const codeEl = button.closest('.sbo-payment-card-stack__code-shell')?.querySelector('[data-merchant-code-value]');
                const range = document.createRange();
                const selection = window.getSelection();

                if (codeEl && selection) {
                    range.selectNodeContents(codeEl);
                    selection.removeAllRanges();
                    selection.addRange(range);
                }
            }
        });
    }

</script>
@endscript
