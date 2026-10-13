<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Business;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class FavoriteButton extends Component
{
    #[Locked]
    public int $businessId;

    public bool $isFavorited = false;

    public function mount(int $businessId): void
    {
        $this->businessId = $businessId;

        if (Auth::check()) {
            $this->isFavorited = Auth::user()
                ->favorites()
                ->where('business_id', $this->businessId)
                ->exists();
        }
    }

    public function toggle(): void
    {
        if ( ! Auth::check()) {
            $this->dispatch('open-login-modal');

            return;
        }

        $business = Business::findOrFail($this->businessId);
        $this->authorize('view', $business);

        $user = Auth::user();

        if ($this->isFavorited) {
            $user->favorites()->detach($this->businessId);
            $this->isFavorited = false;
            $this->dispatch('favorite-removed', businessId: $this->businessId);
        } else {
            $user->favorites()->attach($this->businessId);
            $this->isFavorited = true;
            $this->dispatch('favorite-added', businessId: $this->businessId);
        }
    }

    public function render()
    {
        return view('livewire.favorite-button');
    }
}
