@props([
    'success' => session('success'),
    'error' => session('error'),
    'warning' => session('warning'),
    'info' => session('info'),
])

@php
    $messages = collect([
        'success' => $success,
        'error' => $error,
        'warning' => $warning,
        'info' => $info,
    ])->filter(fn ($message) => filled($message));

    $icons = [
        'success' => 'check-circle',
        'error' => 'exclamation-octagon',
        'warning' => 'exclamation-triangle',
        'info' => 'info-circle',
    ];
@endphp

@if($messages->isNotEmpty())
    <div class="ui-flash-stack" data-ui-flash-stack aria-live="polite">
        @foreach($messages as $type => $message)
            <div class="ui-flash ui-flash--{{ $type }}" data-ui-flash data-ui-flash-timeout="5200" role="status">
                <i class="ui-flash__icon bi bi-{{ $icons[$type] }}" aria-hidden="true"></i>
                <span>{{ $message }}</span>
                <button type="button" class="ui-flash__close" data-ui-flash-close aria-label="Dismiss notification">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
                <span class="ui-flash__progress" aria-hidden="true"></span>
            </div>
        @endforeach
    </div>
@endif
