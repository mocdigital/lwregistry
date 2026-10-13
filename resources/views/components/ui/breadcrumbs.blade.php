@props([
    'items' => [],
    'label' => 'Breadcrumb',
])

@php
    $items = collect($items)->values();
@endphp

@if($items->isNotEmpty())
    <nav class="ui-breadcrumbs" aria-label="{{ $label }}">
        <ol class="ui-breadcrumbs__list">
            @foreach($items as $index => $item)
                @php
                    $isCurrent = (bool) ($item['current'] ?? $index === $items->count() - 1);
                    $isMiddle = $index > 0 && $index < $items->count() - 1;
                @endphp

                @if($index > 0)
                    <li class="ui-breadcrumbs__separator" aria-hidden="true">/</li>
                @endif

                <li class="ui-breadcrumbs__item {{ $isMiddle ? 'ui-breadcrumbs__item--middle' : '' }}">
                    @if(!empty($item['href']) && !$isCurrent)
                        <a href="{{ $item['href'] }}" @if($item['wireNavigate'] ?? false) wire:navigate @endif>
                            {{ $item['label'] }}
                        </a>
                    @else
                        <span @if($isCurrent) aria-current="page" @endif>{{ $item['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
