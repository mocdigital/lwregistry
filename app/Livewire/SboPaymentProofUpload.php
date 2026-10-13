<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Sbo;
use App\Models\SboPaymentProof;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * @property-read Sbo $sbo
 */
final class SboPaymentProofUpload extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $sboId;

    public ?TemporaryUploadedFile $proof = null;

    public function mount(int $sboId): void
    {
        $this->sboId = $sboId;
    }

    public function getSboProperty(): Sbo
    {
        return Sbo::findOrFail($this->sboId);
    }

    public function upload(): void
    {
        $sbo = $this->sbo;
        abort_unless($sbo->created_by === Auth::id(), 403);

        if ($sbo->feesPaid()) {
            return;
        }

        try {
            $this->validate([
                'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            ], [], [
                'proof' => 'proof of payment',
            ]);
        } catch (ValidationException $exception) {
            $this->dispatch(
                'ui-toast',
                type: 'error',
                message: $exception->validator->errors()->first('proof') ?: 'Please choose a valid proof of payment and try again.',
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

        $this->proof = null;
        $this->dispatch('payment-proof-uploaded');
        $this->dispatch(
            'ui-toast',
            type: 'success',
            message: 'Proof of payment uploaded and submitted for review.',
            duration: 5600,
        );
    }

    public function render()
    {
        return view('livewire.sbo-payment-proof-upload', [
            'paymentProof' => $this->sbo->paymentProof()->with('media')->first(),
        ]);
    }
}
