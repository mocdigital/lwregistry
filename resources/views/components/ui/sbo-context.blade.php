@props([
    'sbo' => null,
    'options' => [],
    'step' => null,
    'showSwitcher' => true,
])

@if($sbo)
    @php
        $options = collect($options)->filter()->values();
        $progress = \App\Support\SboApplicationProgress::for($sbo);
        $badge = $progress['badge'];
        $badgeTone = match ($badge['label']) {
            'Verified' => 'success',
            'Declined' => 'danger',
            'Info Requested' => 'warning',
            default => 'info',
        };
        $applicationHref = route('sbo.applications.progress', array_filter([
            'sbo' => $sbo,
            'step' => $step,
        ], static fn ($value): bool => filled($value)));
    @endphp

    <section class="ui-sbo-context" aria-label="Active SBO context">
        <div class="ui-sbo-context__identity">
            <span class="ui-sbo-context__icon" aria-hidden="true">
                <i class="bi bi-building-check"></i>
            </span>
            <div class="ui-sbo-context__body">
                <span class="ui-sbo-context__eyebrow">Active SBO</span>
                <a class="ui-sbo-context__name" href="{{ $applicationHref }}" wire:navigate>{{ $sbo->name }}</a>
                <span class="ui-sbo-context__meta">
                    Application {{ $sbo->application_id ?? 'pending ID' }} · {{ $progress['percent'] }}% complete
                </span>
            </div>
        </div>

        <div class="ui-sbo-context__actions">
            <span class="ui-status ui-status--{{ $badgeTone }}">{{ $badge['label'] }}</span>

            @if($showSwitcher && $options->count() > 1)
                <div class="dropdown">
                    <button type="button"
                        class="btn btn-sm btn-outline-secondary dropdown-toggle rounded-pill"
                        data-bs-toggle="dropdown"
                        aria-expanded="false">
                        Switch SBO
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end ui-sbo-context__menu">
                        @foreach($options as $option)
                            @php
                                $optionHref = route('sbo.applications.progress', array_filter([
                                    'sbo' => $option,
                                    'step' => $step,
                                ], static fn ($value): bool => filled($value)));
                            @endphp
                            <li>
                                <a class="dropdown-item {{ $option->is($sbo) ? 'active' : '' }}"
                                   href="{{ $optionHref }}"
                                   wire:navigate
                                   @if($option->is($sbo)) aria-current="page" @endif>
                                    <span class="ui-sbo-context__option-name">{{ $option->name }}</span>
                                    <small>{{ $option->application_id ?? 'Application pending' }}</small>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </section>
@endif
