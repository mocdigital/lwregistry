@php
    /*
     * Appearance, written into the document before anything paints.
     *
     * The Vue side applies these too, but only once its bundle has parsed —
     * which on a dark-mode member's first load means a white flash first. The
     * server already knows their preferences, so it renders them here.
     *
     * 'system' is left for the client to resolve: the server cannot see the
     * member's OS setting.
     */
    $appearance = auth()->check()
        ? \App\Support\UserPreferences::for(auth()->user())['appearance']
        : null;

    $theme = $appearance['theme'] ?? 'light';
    $toggles = $appearance['toggles'] ?? [];
@endphp
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    @if('system' !== $theme) data-theme="{{ $theme }}" @endif
    data-font-size="{{ $appearance['fontSize'] ?? 'Medium' }}"
    data-density="{{ $appearance['density'] ?? 'comfortable' }}"
    data-rail="{{ $appearance['railSide'] ?? 'right' }}"
    data-reduce-motion="{{ ($toggles['reduceMotion'] ?? false) ? 'true' : 'false' }}"
    data-high-contrast="{{ ($toggles['highContrast'] ?? false) ? 'true' : 'false' }}"
    @if($appearance)
        style="--color-accent: {{ $appearance['accent'] }};"
    @endif
>
<head>
    {{-- 'system' resolved before paint, for the same reason as the above. --}}
    @if('system' === $theme)
        <script>
            document.documentElement.dataset.theme =
                window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        </script>
    @endif
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Read by the raw `fetch` calls the SPA makes outside Inertia — the
         chat's "See translation" is one. Inertia's own visits carry the token
         automatically; a bare fetch does not. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'LW Registry') }}</title>

    {{-- The three families the designs use, one altitude each — see the tier
         rule in resources/js/spa/styles/tokens.css:
           Geist               UI / body, below 16px      400 / 500 / 600
           Plus Jakarta Sans   card + panel titles, 16-20 400 / 500 / 600
           Cormorant Garamond  display, 24px and up       600 / 700
         Only the weights the tier rule can actually produce are requested;
         700 is reserved for the display serif, so Geist and Plus Jakarta Sans stop at
         600 and two font files never get downloaded. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600&family=Cormorant+Garamond:wght@600;700&display=swap"
        rel="stylesheet">

    @vite('resources/js/inertia.ts')
    @inertiaHead
</head>
<body class="bg-surface-secondary font-sans text-ink antialiased">
    @inertia
</body>
</html>
