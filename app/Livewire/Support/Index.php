<?php

declare(strict_types=1);

namespace App\Livewire\Support;

use App\Models\Sbo;
use App\Models\SupportEnquiry;
use App\Notifications\SupportInquirySubmittedNotification;
use App\Support\AdminRecipients;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class Index extends Component
{
    public string $firstName = '';

    public string $lastName = '';

    public string $selectedSboId = '';

    public string $sboNumber = '';

    public string $subject = '';

    public string $message = '';

    public function mount(): void
    {
        $user = Auth::user();

        $this->firstName = (string) ($user->firstname ?? '');
        $this->lastName = (string) ($user->lastname ?? '');
    }

    public function updatedSelectedSboId(): void
    {
        if ('' === $this->selectedSboId) {
            $this->sboNumber = '';

            return;
        }

        $sbo = $this->ownedSbo((int) $this->selectedSboId);

        if ( ! $sbo) {
            $this->selectedSboId = '';
            $this->sboNumber = '';

            return;
        }

        $this->sboNumber = $sbo->moc_registration_number ?: (string) $sbo->application_id;
    }

    public function submit(): void
    {
        $data = $this->validate([
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'selectedSboId' => ['nullable', 'integer'],
            'sboNumber' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $sbo = $this->resolveOwnedSbo();

        $enquiry = SupportEnquiry::create([
            'user_id' => Auth::id(),
            'sbo_id' => $sbo?->id,
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'],
            'sbo_number' => mb_trim((string) $data['sboNumber']) ?: null,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'status' => SupportEnquiry::STATUS_OPEN,
        ]);

        AdminRecipients::notify(new SupportInquirySubmittedNotification($enquiry));

        $this->reset(['selectedSboId', 'sboNumber', 'subject', 'message']);
        session()->flash('support_success', 'Your support enquiry has been submitted.');
    }

    public function render()
    {
        return view('livewire.support.index', [
            'managedSbos' => Sbo::where('created_by', Auth::id())
                ->latest()
                ->get(),
        ]);
    }

    private function resolveOwnedSbo(): ?Sbo
    {
        if ('' !== $this->selectedSboId) {
            $sbo = $this->ownedSbo((int) $this->selectedSboId);

            if ( ! $sbo) {
                throw ValidationException::withMessages([
                    'selectedSboId' => 'Please select one of your SBOs.',
                ]);
            }

            return $sbo;
        }

        $number = mb_trim($this->sboNumber);

        if ('' === $number) {
            return null;
        }

        $sbo = Sbo::where('application_id', $number)
            ->orWhere('moc_registration_number', $number)
            ->first();

        if ( ! $sbo) {
            return null;
        }

        if ($sbo->created_by !== Auth::id()) {
            throw ValidationException::withMessages([
                'sboNumber' => 'This SBO number is not linked to your account.',
            ]);
        }

        return $sbo;
    }

    private function ownedSbo(int $id): ?Sbo
    {
        return Sbo::where('created_by', Auth::id())
            ->whereKey($id)
            ->first();
    }
}
