@props([
    'title',
    'description' => null,
    'eyebrow' => null,
    'status' => null,
    'statusTone' => 'info',
    'backHref' => null,
    'backLabel' => 'Back',
    'backIcon' => 'arrow-left',
    'meta' => null,
    'breadcrumbs' => null,
])

<header class="ui-page-header">
    <div class="ui-page-header__main">
        @if($breadcrumbs)
            <x-ui.breadcrumbs :items="$breadcrumbs" />
        @elseif($backHref || $eyebrow)
            <div class="ui-page-header__context">
                @if($backHref)
                    <a href="{{ $backHref }}" @if($attributes->has('wire:navigate')) wire:navigate @endif>
                        <i class="bi bi-{{ $backIcon }} me-1" aria-hidden="true"></i>{{ $backLabel }}
                    </a>
                @endif
                @if($backHref && $eyebrow)
                    <span aria-hidden="true">/</span>
                @endif
                @if($eyebrow)
                    <span>{{ $eyebrow }}</span>
                @endif
            </div>
        @endif

        <div class="d-flex align-items-center flex-wrap gap-2">
            <h1 class="ui-page-header__title">{{ $title }}</h1>
            @if($status)
                <span class="ui-status ui-status--{{ $statusTone }}">{{ $status }}</span>
            @endif
        </div>

        @if($description)
            <p class="ui-page-header__description">{{ $description }}</p>
        @endif

        @if($meta)
            <div class="ui-page-header__meta">{{ $meta }}</div>
        @endif
    </div>

    @if(isset($actions))
        <div class="ui-page-header__actions">{{ $actions }}</div>
    @endif
</header>
