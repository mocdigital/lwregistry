<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

final class SecurityModal extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    #[On('open-security-modal')]
    public function openModal(): void
    {
        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->resetValidation();
        $this->dispatch('show-security-modal');
    }

    public function save(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset(['current_password', 'password', 'password_confirmation']);

            throw $e;
        }

        $user = Auth::user();
        $user->password = Hash::make($validated['password']);
        $user->save();

        $this->reset(['current_password', 'password', 'password_confirmation']);

        $this->dispatch('password-updated');
        $this->dispatch('close-security-modal');

        session()->flash('success', 'Password changed successfully!');
    }

    public function render()
    {
        return view('livewire.security-modal');
    }
}
