@props([
    'model' => 'search',
    'value' => '',
    'label' => 'Search',
    'placeholder' => 'Search...',
    'clearAction' => 'clearSearch',
    'loadingTarget' => null,
    'as' => 'form',
    'inputId' => null,
    'labelClass' => 'visually-hidden',
    'inputClass' => '',
    'minWidth' => '250px',
    'maxWidth' => null,
    'help' => null,
])

@php
    $id = $inputId ?: 'live-search-' . str_replace(['.', '_'], '-', $model);
    $target = $loadingTarget ?: $model . ($clearAction ? ',' . $clearAction : '');
    $style = $maxWidth ? 'max-width: ' . $maxWidth . ';' : 'min-width: ' . $minWidth . ';';
@endphp

@if($as === 'form')
    <form {{ $attributes->class('lw-live-search')->merge(['style' => $style]) }} role="search" wire:submit.prevent>
@else
    <div {{ $attributes->class('lw-live-search')->merge(['style' => $style]) }} role="search">
@endif
    <label class="{{ $labelClass }}" for="{{ $id }}">{{ $label }}</label>
    <div class="input-group input-group-sm">
        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
        <input
            id="{{ $id }}"
            type="search"
            class="form-control {{ $inputClass }}"
            wire:model.live.debounce.300ms="{{ $model }}"
            wire:keydown.enter.prevent
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            aria-label="{{ $label }}"
            @if($help) data-form-help="{{ $help }}" @endif>
        @if($clearAction && filled($value))
            <button type="button" class="btn btn-outline-secondary" wire:click="{{ $clearAction }}">
                Clear
            </button>
        @endif
    </div>
    <div class="small text-muted mt-1" wire:loading.delay wire:target="{{ $target }}">
        Updating results...
    </div>
@if($as === 'form')
    </form>
@else
    </div>
@endif
