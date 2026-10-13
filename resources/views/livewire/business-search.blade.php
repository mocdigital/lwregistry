<div>
@if($heroMode)
<form wire:submit.prevent="search" class="hero-search-form">
    <input type="text" wire:model="searchQuery" class="hero-search-input" placeholder="What are you looking for? (E.g Parallex Bank)" aria-label="Business or service to search for" />
    <input type="text" wire:model="locationQuery" class="hero-location-input" placeholder="Location (E.g Lagos)" aria-label="Search location" />
    <button type="submit" class="hero-search-btn" aria-label="Search businesses"><i class="bi bi-search" aria-hidden="true"></i></button>
</form>
@else
<form wire:submit.prevent="search" class="d-flex flex-column flex-lg-row gap-2">
    <input type="text" wire:model="searchQuery" placeholder="What are you looking for?" class="form-control" aria-label="Business or service to search for">
    <input type="text" wire:model="locationQuery" placeholder="Location" class="form-control" aria-label="Search location">
    <button type="submit" class="btn btn-primary" aria-label="Search businesses"><i class="bi bi-search" aria-hidden="true"></i></button>
</form>
@endif
</div>
