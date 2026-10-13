<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Propaganistas\LaravelPhone\Rules\Phone;

final class ProfileModal extends Component
{
    use WithFileUploads;

    public string $firstname = '';

    public string $lastname = '';

    public string $phone = '';

    public string $kcHandle = '';

    public ?TemporaryUploadedFile $profilePhoto = null;

    public function mount(): void
    {
        $user = Auth::user();

        $this->firstname = $user->firstname ?? '';
        $this->lastname = $user->lastname ?? '';
        $this->phone = $user->getRawOriginal('phone') ?? '';
        $this->kcHandle = array_key_exists('kc_handle', $user->getAttributes()) ? ($user->kc_handle ?? '') : '';
        $this->profilePhoto = null;
    }

    #[On('open-profile-modal')]
    public function openModal(): void
    {
        $this->mount();
        $this->dispatch('show-profile-modal');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', new Phone()],
            'kcHandle' => ['nullable', 'string', 'max:255'],
            'profilePhoto' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:1024'],
        ]);

        $user = Auth::user();
        $user->firstname = $validated['firstname'];
        $user->lastname = $validated['lastname'];
        $user->phone = $validated['phone'] ?: null;
        $user->kc_handle = $validated['kcHandle'] ?: null;

        if ($this->profilePhoto) {
            $oldPhotoPath = User::query()
                ->whereKey($user->getKey())
                ->value('profile_photo_path');

            $user->profile_photo_path = $this->profilePhoto->store('profile-photos', 'public');

            if ($oldPhotoPath) {
                Storage::disk('public')->delete($oldPhotoPath);
            }
        }

        $user->save();
        $this->profilePhoto = null;

        $this->dispatch('profile-updated');
        $this->dispatch('close-profile-modal');

        session()->flash('success', 'Profile updated successfully!');
    }

    public function render()
    {
        return view('livewire.profile-modal');
    }
}
