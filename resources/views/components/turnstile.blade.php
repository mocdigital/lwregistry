@props([
    'wireModel' => null,
    'theme' => 'auto',
])

@php
    $turnstile = app(\App\Support\TurnstileVerifier::class);
    $siteKey = $turnstile->enabled() ? $turnstile->siteKey() : null;
@endphp

@if($siteKey)
    <div class="turnstile-field my-3">
        <div
            data-turnstile-widget
            data-sitekey="{{ $siteKey }}"
            data-theme="{{ $theme }}"
            wire:ignore>
        </div>
        <input
            type="hidden"
            name="cf-turnstile-response"
            data-turnstile-response
            @if($wireModel) wire:model.live="{{ $wireModel }}" @endif>
        @if($wireModel)
            @error($wireModel) <div class="text-danger small mt-1">{{ $message }}</div> @enderror
        @else
            @error('cf-turnstile-response') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
        @endif
    </div>

    @once
        <script>
            window.lwRegistryRenderTurnstile = function () {
                if (!window.turnstile) return;

                document.querySelectorAll('[data-turnstile-widget]:not([data-turnstile-rendered])').forEach(function (el) {
                    const field = el.closest('.turnstile-field');
                    const input = field ? field.querySelector('[data-turnstile-response]') : null;

                    const clearToken = function () {
                        if (!input) return;

                        input.value = '';
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    };

                    const widgetId = window.turnstile.render(el, {
                        sitekey: el.dataset.sitekey,
                        theme: el.dataset.theme || 'auto',
                        callback: function (token) {
                            if (!input) return;

                            input.value = token;
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                        },
                        'expired-callback': clearToken,
                        'error-callback': clearToken,
                    });

                    el.dataset.turnstileRendered = String(widgetId);
                });
            };

            document.addEventListener('DOMContentLoaded', window.lwRegistryRenderTurnstile);
            document.addEventListener('livewire:navigated', window.lwRegistryRenderTurnstile);
        </script>
        <script
            src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit"
            async
            defer
            onload="window.lwRegistryRenderTurnstile && window.lwRegistryRenderTurnstile()">
        </script>
    @endonce
@endif
