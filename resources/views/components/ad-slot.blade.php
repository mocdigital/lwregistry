@props(['name'])

@php
    use App\Support\AdService;

    $slotConfig = config('ads.slots.' . $name);
    $ad = $slotConfig ? AdService::forSlot($name) : null;
    $imageUrl = $ad?->imageUrl();
    $mobileImageUrl = $ad?->mobileImageUrl();
    $mobile = $slotConfig['mobile'] ?? null;

    if ($ad && $imageUrl) {
        AdService::recordImpression($ad);
    }
@endphp

@if($slotConfig)
    <div class="ad-slot ad-slot-{{ $name }} text-center my-3" data-ad-slot="{{ $name }}">
    @if($ad && $imageUrl)
        <a href="{{ route('ads.click', $ad) }}"
           target="_blank" rel="noopener sponsored"
           aria-label="Advertisement: {{ $ad->title }}">
            <picture>
                @if($mobileImageUrl && is_array($mobile) && count($mobile) === 2)
                    <source media="(max-width: 575.98px)" srcset="{{ $mobileImageUrl }}">
                @endif
                <img src="{{ $imageUrl }}"
                     alt="{{ $ad->title }}"
                     width="{{ $slotConfig['width'] }}"
                     height="{{ $slotConfig['height'] }}"
                     loading="lazy"
                     style="max-width:100%;height:auto;border-radius:8px;display:inline-block;">
            </picture>
        </a>
        <div class="ad-slot-label small text-muted mt-1" style="font-size:.7rem;letter-spacing:.04em;">
            Advertisement
        </div>
    @else
        <div class="ad-house-banner"
             style="--ad-width: {{ (int) $slotConfig['width'] }}; --ad-height: {{ (int) $slotConfig['height'] }};">
            <div class="ad-house-banner__copy">
                <span class="ad-house-banner__eyebrow">Advertisement space</span>
                <strong>Reach verified Loveworld businesses</strong>
                <span class="ad-house-banner__meta">
                    {{ $slotConfig['width'] }}×{{ $slotConfig['height'] }} px
                    @if(is_array($mobile) && count($mobile) === 2)
                        <span aria-hidden="true">·</span> {{ $mobile[0] }}×{{ $mobile[1] }} mobile
                    @endif
                </span>
            </div>
            <a href="{{ route('advertise') }}" class="btn btn-sm btn-primary ad-house-banner__button">
                Advertise with Us
            </a>
        </div>
        <div class="ad-slot-label small text-muted mt-1" style="font-size:.7rem;letter-spacing:.04em;">
            Advertisement
        </div>
    @endif
    </div>
@endif
