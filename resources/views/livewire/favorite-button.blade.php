<button 
    wire:click="toggle" 
    class="fav-btn" 
    title="{{ $isFavorited ? 'Remove from favorites' : 'Add to favorites' }}"
    type="button"
>
    <i class="bi {{ $isFavorited ? 'bi-heart-fill text-danger' : 'bi-heart' }}"></i> 
    <span class="hidden-mobile">{{ $isFavorited ? 'Saved' : 'Save' }}</span>
</button>
