<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Country;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * Full-page SBO registration ("Join the Registry").
 *
 * Extends AuthModal so the multi-step form's fields, validation and
 * registerBusiness() logic live in exactly one place — this class only swaps
 * the modal chrome for a standalone page.
 */
#[Layout('layouts.app')]
#[Title('Join the Registry – Register your Business')]
final class JoinRegistry extends AuthModal
{
    public function mount(): void
    {
        // Signed-in visitors have nothing to register here.
        if (auth()->check()) {
            $this->redirectRoute('post-login', navigate: true);

            return;
        }

        parent::mount();

        // The page is register-only; login stays a modal.
        $this->mode = 'register';
    }

    public function render(): View
    {
        return view('livewire.join-registry', [
            'countries' => Country::orderBy('name')->pluck('name'),
            'churchZones' => $this->activeZoneNames(),
        ]);
    }
}
