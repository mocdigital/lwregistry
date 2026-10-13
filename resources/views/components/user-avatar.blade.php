@props([
    'user' => null,
    'alt' => null,
    'fallback' => null,
])

@php
    $photoUrl = $user?->profilePhotoUrl();
    $label = $alt ?? trim(($user?->name ?? 'User') . ' avatar');
    $fallbackText = $fallback ?? ($user?->initials() ?: 'U');
@endphp

@if($photoUrl)
    <img
        src="{{ $photoUrl }}"
        alt="{{ $label }}"
        {{ $attributes->class(['user-avatar-image']) }}
    >
@else
    <span {{ $attributes->class(['user-avatar-fallback']) }}>
        {{ $fallbackText }}
    </span>
@endif
