@php
    $fluxManifestPath = \Flux\Flux::pro()
        ? base_path('vendor/livewire/flux-pro/dist/manifest.json')
        : base_path('vendor/livewire/flux/dist/manifest.json');
    $fluxManifest = json_decode(
        file_get_contents($fluxManifestPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $fluxScript = config('app.debug') ? 'flux/flux.js' : 'flux/flux.min.js';
    $fluxSrc = url($fluxScript) . '?id=' . $fluxManifest['/flux.js'];
@endphp

<script src="{{ $fluxSrc }}" data-navigate-once></script>
