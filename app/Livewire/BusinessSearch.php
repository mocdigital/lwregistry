<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Component;

final class BusinessSearch extends Component
{
    public string $searchQuery = '';

    public string $locationQuery = '';

    public bool $heroMode = false;

    public function search(): void
    {
        $this->redirect(
            route('businesses.index', [
                'search' => $this->searchQuery,
                'location' => $this->locationQuery,
            ]),
            navigate: true
        );
    }

    public function render()
    {
        return view('livewire.business-search');
    }
}
