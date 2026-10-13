<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Sbo;
use App\Models\SboPaymentProof;
use App\Notifications\AdminFeePaymentSubmittedNotification;
use App\Support\AdminRecipients;
use App\Support\EspeesTestAccount;
use App\Support\SboFeeCalculator;
use App\Support\SboFeeScheduleMisconfiguredException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * SBO-facing registration-fee flow on the owner's dashboard.
 *
 * Step 0 gates on share structure. Share-bearing structures then estimate a
 * fee from a capital type, selected fee band, and per-share value; non-share
 * structures pay a flat fee. After the owner confirms the selection, normal
 * users see the server-verified Espees checkout alongside the manual fallback;
 * the gated test account skips fee selection and sees only its one-Espee
 * hosted checkout option.
 * Uploading proof does NOT confirm payment — an admin still marks it paid.
 *
 * @property-read Sbo $sbo
 * @property-read bool $isShareStructure
 */
final class SboFees extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $sboId;

    // Step 0 — share-structure gate.
    public string $shareStructure = '';

    // Share-capital inputs (share structures only).
    public string $shareCapitalType = '';

    public ?int $selectedFeeBandId = null;

    public ?int $issuedShares = null;

    public ?float $perShareValue = null;

    // Proof-of-payment step.
    public ?string $paymentReference = null;

    public ?TemporaryUploadedFile $proof = null;

    public function mount(int $sboId): void
    {
        $this->sboId = $sboId;

        // Rehydrate any previously confirmed selection so the owner returns to
        // the transfer/upload step rather than starting over.
        $sbo = $this->sbo;
        $this->shareStructure = (string) ($sbo->share_structure ?? '');
        $this->shareCapitalType = (string) ($sbo->share_capital_type ?? '');
        $this->issuedShares = $sbo->issued_shares_volume;
        $this->perShareValue = null !== $sbo->per_share_value ? (float) $sbo->per_share_value : null;
        $this->selectedFeeBandId = $this->resolvePersistedBandId();
        $this->paymentReference = $sbo->payment_reference;
    }

    public function getSboProperty(): Sbo
    {
        return Sbo::findOrFail($this->sboId);
    }

    /** Whether the selected structure has share capital. */
    public function getIsShareStructureProperty(): bool
    {
        return '' !== $this->shareStructure
            && SboFeeCalculator::isShareStructure($this->shareStructure);
    }

    public function updatedShareStructure(): void
    {
        $this->shareCapitalType = '';
        $this->selectedFeeBandId = null;
        $this->issuedShares = null;
        $this->perShareValue = null;
    }

    /**
     * The live fee for the current selection, or null if not enough has been
     * chosen yet to compute one.
     */
    public function getFeeProperty(): ?int
    {
        if ('' === $this->shareStructure || ! SboFeeCalculator::isValidStructure($this->shareStructure)) {
            return null;
        }

        try {
            if ( ! $this->isShareStructure) {
                return SboFeeCalculator::fee($this->shareStructure, null, null, $this->countryId());
            }

            if ('' === $this->shareCapitalType || ! $this->selectedFeeBandId || ! $this->perShareValue) {
                return null;
            }

            $band = $this->selectedBandOption();

            if (null === $band || null === SboFeeCalculator::issuedSharesForBand($band, (float) $this->perShareValue)) {
                return null;
            }

            return $band['fee'];
        } catch (SboFeeScheduleMisconfiguredException) {
            return null;
        }
    }

    public function getHasCompleteSelectionProperty(): bool
    {
        if ('' === $this->shareStructure || ! SboFeeCalculator::isValidStructure($this->shareStructure)) {
            return false;
        }

        return ! $this->isShareStructure
            || ('' !== $this->shareCapitalType && null !== $this->selectedFeeBandId && null !== $this->perShareValue);
    }

    /** True once the SBO has persisted its selection or is the fixed-fee test account. */
    public function getConfirmedProperty(): bool
    {
        // The dedicated Espees test account intentionally skips registration
        // fee calculation and exercises only the fixed one-Espee checkout.
        return EspeesTestAccount::matches(Auth::user())
            || null !== $this->sbo->calculated_fee;
    }

    /**
     * Persist the structure + (for share structures) the inputs, recomputing
     * the fee server-side. Never trusts a client-supplied amount.
     */
    public function confirmSelections(): void
    {
        $sbo = $this->guardedSbo();

        $rules = [
            'shareStructure' => ['required', 'string', function (string $attr, $value, $fail): void {
                if ( ! SboFeeCalculator::isValidStructure((string) $value)) {
                    $fail('Please choose a valid share structure.');
                }
            }],
        ];

        if ($this->isShareStructure) {
            $rules += [
                'shareCapitalType' => ['required', 'string', function (string $attr, $value, $fail): void {
                    if ( ! SboFeeCalculator::isValidCapitalType($this->shareStructure, (string) $value, $this->countryId())) {
                        $fail('Please choose a valid share capital type.');
                    }
                }],
                'selectedFeeBandId' => ['required', 'integer', function (string $attr, $value, $fail): void {
                    if (null === SboFeeCalculator::bandOptionFor($this->shareStructure, (int) $value, $this->countryId())) {
                        $fail('Please choose a valid fee band.');
                    }
                }],
                'perShareValue' => ['required', 'numeric', 'min:0.01'],
            ];
        }

        $this->validate($rules, [], [
            'shareStructure' => 'share structure',
            'shareCapitalType' => 'share capital type',
            'selectedFeeBandId' => 'fee band',
            'perShareValue' => 'per-share value',
        ]);

        $isShare = $this->isShareStructure;
        $countryId = $this->countryId();

        try {
            $fee = null;
            $band = null;
            $issuedShares = null;

            if ($isShare) {
                $selectedBand = $this->selectedBandOption();
                $issuedShares = null !== $selectedBand
                    ? SboFeeCalculator::issuedSharesForBand($selectedBand, (float) $this->perShareValue)
                    : null;

                if (null === $selectedBand || null === $issuedShares) {
                    $this->addError('selectedFeeBandId', 'The selected fee band cannot be used with this per-share value. Please choose another band or lower the per-share value.');

                    return;
                }

                $fee = SboFeeCalculator::fee($this->shareStructure, $issuedShares, (float) $this->perShareValue, $countryId);
                $band = $selectedBand['label'];
            } else {
                $fee = SboFeeCalculator::fee($this->shareStructure, null, null, $countryId);
            }
        } catch (SboFeeScheduleMisconfiguredException) {
            $this->addError('feeSchedule', 'The registration fee schedule is not configured for this selection. Please contact support.');

            return;
        }

        $sbo->update([
            'share_structure' => $this->shareStructure,
            'share_capital_type' => $isShare ? $this->shareCapitalType : null,
            'issued_shares_volume' => $isShare ? $issuedShares : null,
            'per_share_value' => $isShare ? $this->perShareValue : null,
            'calculated_fee' => $fee,
            'fee_band' => $band,
        ]);

        session()->flash('fees_success', 'Fee confirmed. Please complete the transfer and upload your proof of payment below.');
    }

    /**
     * Save the current fee inputs without confirming a fee or opening payment.
     * This lets an owner leave the workflow and return to the same selection.
     */
    public function saveDraft(): void
    {
        $sbo = $this->guardedSbo();
        $isShare = $this->isShareStructure;
        $issuedShares = null;

        if ($isShare) {
            try {
                $issuedShares = $this->computedIssuedShares();
            } catch (SboFeeScheduleMisconfiguredException) {
                // The draft is still useful even when the current schedule is
                // incomplete; confirmation will surface the configuration error.
            }
        }

        $sbo->update([
            'share_structure' => $this->shareStructure ?: null,
            'share_capital_type' => $isShare ? ($this->shareCapitalType ?: null) : null,
            'issued_shares_volume' => $isShare ? $issuedShares : null,
            'per_share_value' => $isShare ? $this->perShareValue : null,
        ]);

        session()->flash('fees_success', 'Your fee setup was saved. You can return to complete it later.');
    }

    /** Let the owner revise their selection before paying. */
    public function editSelections(): void
    {
        $sbo = $this->guardedSbo();
        $sbo->update(['calculated_fee' => null, 'fee_band' => null]);
    }

    /**
     * Store the proof, mark payment submitted (still unpaid), and alert admins.
     */
    public function submitProof(): void
    {
        $sbo = $this->guardedSbo();

        abort_if(null === $sbo->calculated_fee, 400, 'Confirm your fee selection first.');

        try {
            $this->validate([
                'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
                'paymentReference' => ['nullable', 'string', 'max:255'],
            ], [], [
                'proof' => 'proof of payment',
                'paymentReference' => 'payment reference',
            ]);
        } catch (ValidationException $exception) {
            $this->dispatch(
                'ui-toast',
                type: 'error',
                message: $exception->validator->errors()->first('proof')
                    ?: $exception->validator->errors()->first('paymentReference')
                    ?: 'Please check the payment details and try again.',
                duration: 7500,
            );

            throw $exception;
        }

        $file = $this->proof;

        if ( ! $file instanceof TemporaryUploadedFile || ! $file->isValid() || ! is_file((string) $file->getRealPath())) {
            $message = 'We could not read that file. Please choose it again and retry the upload.';

            $this->addError('proof', $message);
            $this->dispatch('ui-toast', type: 'error', message: $message, duration: 7500);

            return;
        }

        // Store the file on the shared SboPaymentProof record (one per SBO),
        // resetting it to pending review — mirrors SboPaymentProofUpload.
        $paymentProof = SboPaymentProof::firstOrNew(['sbo_id' => $sbo->id]);
        $wasExisting = $paymentProof->exists;
        $originalAttributes = $wasExisting ? $paymentProof->getAttributes() : [];

        $paymentProof->fill([
            'status' => 'pending',
            'rejection_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
        $paymentProof->save();

        try {
            $paymentProof->addMedia((string) $file->getRealPath())
                ->usingFileName(basename($file->getClientOriginalName()))
                ->toMediaCollection(SboPaymentProof::COLLECTION);
        } catch (Throwable $exception) {
            report($exception);

            if ($wasExisting) {
                $paymentProof->forceFill($originalAttributes)->saveQuietly();
            } else {
                $paymentProof->delete();
            }

            $message = 'We could not save this proof of payment. Please try again. If the problem continues, contact support.';

            $this->addError('proof', $message);
            $this->dispatch('ui-toast', type: 'error', message: $message, duration: 8000);

            return;
        }

        $sbo->update([
            'payment_reference' => $this->paymentReference,
            'payment_submitted_at' => now(),
        ]);

        try {
            AdminRecipients::notify(new AdminFeePaymentSubmittedNotification($sbo->fresh()));
        } catch (Throwable $exception) {
            // Do not report a stored proof as failed if only the admin
            // notification transport is unavailable.
            report($exception);
        }

        $this->reset('proof');
        $this->dispatch('payment-proof-uploaded');
        $this->dispatch(
            'ui-toast',
            type: 'success',
            message: 'Proof of payment submitted. An administrator will confirm your payment shortly.',
            duration: 5600,
        );
    }

    public function render()
    {
        $countryId = $this->countryId();
        $isTestAccount = EspeesTestAccount::matches(Auth::user());
        $gateway = SboFeeCalculator::espeesGateway($countryId);

        return view('livewire.sbo-fees', [
            'structures' => SboFeeCalculator::structures(),
            'capitalTypes' => '' !== $this->shareStructure
                ? SboFeeCalculator::capitalTypesFor($this->shareStructure, $countryId)
                : [],
            'feeBands' => $this->isShareStructure
                ? SboFeeCalculator::bandOptionsFor($this->shareStructure, $countryId)
                : [],
            'selectedBand' => $this->selectedBandOption(),
            'computedIssuedShares' => $this->computedIssuedShares(),
            'computedEquity' => $this->computedEquity(),
            'currency' => SboFeeCalculator::currency($countryId),
            'merchant' => SboFeeCalculator::merchant($countryId),
            'isTestAccount' => $isTestAccount,
            'canUseEspeesCheckout' => $isTestAccount
                ? EspeesTestAccount::canUseCheckout(Auth::user(), $countryId)
                : $gateway['configured'],
            'testCheckoutAmount' => EspeesTestAccount::PAYMENT_AMOUNT,
        ]);
    }

    /**
     * The SBO's country scope for fee resolution — its country_id, or null to
     * fall back to the global default schedule.
     */
    private function countryId(): ?int
    {
        return $this->sbo->country_id;
    }

    /**
     * @return array{id: int, max_equity: int|null, previous_max_equity: int|null, fee: int, label: string, range_label: string, display_label: string}|null
     */
    private function selectedBandOption(): ?array
    {
        if ('' === $this->shareStructure || null === $this->selectedFeeBandId) {
            return null;
        }

        return SboFeeCalculator::bandOptionFor($this->shareStructure, $this->selectedFeeBandId, $this->countryId());
    }

    private function computedIssuedShares(): ?int
    {
        $band = $this->selectedBandOption();

        if (null === $band || null === $this->perShareValue) {
            return null;
        }

        return SboFeeCalculator::issuedSharesForBand($band, (float) $this->perShareValue);
    }

    private function computedEquity(): ?float
    {
        $issuedShares = $this->computedIssuedShares();

        if (null === $issuedShares || null === $this->perShareValue) {
            return null;
        }

        return SboFeeCalculator::equity($issuedShares, (float) $this->perShareValue);
    }

    private function resolvePersistedBandId(): ?int
    {
        if (
            '' === $this->shareStructure
            || null === $this->issuedShares
            || null === $this->perShareValue
            || ! SboFeeCalculator::isShareStructure($this->shareStructure)
        ) {
            return null;
        }

        try {
            return SboFeeCalculator::bandFor($this->shareStructure, SboFeeCalculator::equity($this->issuedShares, (float) $this->perShareValue), $this->countryId())['id'] ?? null;
        } catch (SboFeeScheduleMisconfiguredException) {
            return null;
        }
    }

    /** Load the SBO and assert the current user owns it. */
    private function guardedSbo(): Sbo
    {
        $sbo = $this->sbo;
        abort_unless($sbo->created_by === Auth::id(), 403);

        return $sbo;
    }
}
