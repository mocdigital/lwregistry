@props([
    'label' => 'Saved',
])

<span class="ui-form-state" aria-live="polite">
    <span class="ui-form-state__saving" wire:loading>
        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Saving…
    </span>
    <span class="ui-form-state__unsaved" wire:dirty>
        <i class="bi bi-circle-fill me-1" aria-hidden="true" style="font-size:.45rem;vertical-align:middle"></i>Unsaved changes
    </span>
    <span class="ui-form-state__saved" wire:dirty.remove>
        <i class="bi bi-check2 me-1" aria-hidden="true"></i>{{ $label }}
    </span>
</span>
