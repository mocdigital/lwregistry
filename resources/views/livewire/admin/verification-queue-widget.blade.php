<?php

use App\Models\Sbo;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    public int $pendingCount = 0;

    public function mount(): void
    {
        $this->updateCount();
    }

    #[On('sbo-updated')]
    public function updateCount(): void
    {
        $this->pendingCount = Sbo::where('verification_status', 'pending')->count();
    }
}; ?>

<div class="p-3 rounded-4 bg-accent text-dark small">
    <div class="fw-semibold mb-1">SBO Approval Queue</div>
    <div class="d-flex justify-content-between align-items-center">
        <span>Pending: {{ $pendingCount }}</span>
        <a href="{{ route('admin.sbos.index') }}" class="btn btn-sm btn-outline-dark rounded-pill">
            Review
        </a>
    </div>
</div>
