<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Business;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class LogoUploader extends Component
{
    use WithFileUploads;

    public Business $business;

    #[Validate('image|mimes:jpeg,jpg,png,webp|max:2048')]
    public ?TemporaryUploadedFile $logo = null;

    public function mount(Business $business): void
    {
        $this->business = $business;
    }

    public function isOwner(): bool
    {
        return auth()->check() && auth()->id() === $this->business->user_id;
    }

    public function updatedLogo(): void
    {
        $this->authorize('update', $this->business);

        $this->validate();

        $this->business->addMedia($this->logo->getRealPath())
            ->usingFileName($this->logo->hashName())
            ->toMediaCollection('logo');

        $this->business->refresh();

        $this->logo = null;

        $this->dispatch('logo-updated');
    }

    public function render()
    {
        return view('livewire.logo-uploader');
    }
}
