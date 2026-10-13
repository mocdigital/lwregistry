@php
    $livewireManifest = json_decode(
        file_get_contents(base_path('vendor/livewire/livewire/dist/manifest.json')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $livewireScript = config('app.debug') ? 'livewire/livewire.js' : 'livewire/livewire.min.js';
    $livewireSrc = url($livewireScript) . '?id=' . $livewireManifest['/livewire.js'];
@endphp

<script
    src="{{ $livewireSrc }}"
    data-csrf="{{ csrf_token() }}"
    data-update-uri="{{ url('livewire/update') }}"
    data-navigate-once="true"
    @if (! config('livewire.navigate.show_progress_bar', true)) data-no-progress-bar @endif
></script>

{{--
    When an admin leaves a tab open past the session lifetime and then acts,
    the token stamped into the page is stale and Livewire's request comes back
    419 — the "Page Expired" screen. Reloading gets a fresh token and a working
    page, which is a far better answer than a dead end. This replaces Livewire's
    default confirm dialog with a silent reload.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (status === 419) {
                    preventDefault()
                    window.location.reload()
                }
            })
        })
    })
</script>
