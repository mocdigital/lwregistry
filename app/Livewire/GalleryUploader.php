<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Business;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class GalleryUploader extends Component
{
    use WithFileUploads;

    public const MAX_IMAGES = 5;

    public Business $business;

    #[Validate('image|mimes:jpeg,jpg,png,webp|max:2048')]
    public ?TemporaryUploadedFile $image = null;

    public ?int $replaceIndex = null;

    public function mount(Business $business): void
    {
        $this->business = $business;
    }

    public function isOwner(): bool
    {
        return auth()->check() && auth()->id() === $this->business->user_id;
    }

    public function updatedImage(): void
    {
        $this->authorize('update', $this->business);

        $this->validate();

        $galleryMedia = $this->business->getMedia('gallery');

        if (null !== $this->replaceIndex) {
            $mediaToReplace = $galleryMedia->get($this->replaceIndex);

            if ($mediaToReplace) {
                $mediaToReplace->delete();
            }

            $this->business->addMedia($this->image->getRealPath())
                ->usingFileName($this->image->hashName())
                ->toMediaCollection('gallery');
        } else {
            if ($galleryMedia->count() >= self::MAX_IMAGES) {
                $this->addError('image', 'Maximum of ' . self::MAX_IMAGES . ' gallery images allowed.');
                $this->image = null;

                return;
            }

            $this->business->addMedia($this->image->getRealPath())
                ->usingFileName($this->image->hashName())
                ->toMediaCollection('gallery');
        }

        $this->business->refresh();

        $this->image = null;
        $this->replaceIndex = null;

        $this->dispatch('gallery-updated');
    }

    public function setReplaceIndex(int $index): void
    {
        $this->authorize('update', $this->business);

        $this->replaceIndex = $index;
    }

    public function render()
    {
        return view('livewire.gallery-uploader');
    }
}
