<meta charset="utf-8" />

<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ $title ?? config('app.name') }}</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
<link href="{{ asset('css/form-help.css?v=3') }}" rel="stylesheet">
<link href="{{ asset('css/ui-foundation.css?v=3') }}" rel="stylesheet">
<script src="{{ asset('js/form-help.js?v=4') }}" defer></script>
<script src="{{ asset('js/ui-feedback.js?v=1') }}" defer></script>
@fluxAppearance
