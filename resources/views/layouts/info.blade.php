<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    {{-- Primary SEO --}}
    <title>@yield('title', 'Loveworld Business Registry')</title>
    <meta name="description" content="@yield(
        'meta_description',
        'Loveworld Business Registry is a trusted digital platform connecting Loveworld members, registered businesses, and strategic business organizations to opportunities across industries.'
    )">
    <meta name="keywords" content="Loveworld, Loveworld Registry, business registry, Loveworld members, registered businesses, RFQs, SBOs, Loveworld organizations">
    <meta name="author" content="Loveworld Business Registry">
    <meta name="robots" content="index, follow">

    {{-- Canonical --}}
    <link rel="canonical" href="{{ url()->current() }}">

    {{-- Open Graph (Facebook, WhatsApp, LinkedIn) --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="@yield('og_title', 'Loveworld Business Registry')">
    <meta property="og:description" content="@yield(
        'og_description',
        'Connecting Loveworld members, businesses, and global organizations through a trusted digital registry.'
    )">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="@yield('og_image', asset('images/og-loveworld.png'))">
    <meta property="og:site_name" content="Loveworld Business Registry">

    {{-- Twitter / X --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('twitter_title', 'Loveworld Business Registry')">
    <meta name="twitter:description" content="@yield(
        'twitter_description',
        'A trusted digital ecosystem empowering Loveworld members and businesses.'
    )">
    <meta name="twitter:image" content="@yield('twitter_image', asset('images/og-loveworld.png'))">

    {{-- Theme / Branding --}}
    <meta name="theme-color" content="#142A3B">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    {{-- Styles --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    {{-- Custom CSS --}}
    <link href="{{ asset('css/style.css?v=5.6') }}" rel="stylesheet">
    <link href="{{ asset('css/responsive.css?v=8') }}" rel="stylesheet">
    <link href="{{ asset('css/form-help.css?v=3') }}" rel="stylesheet">
    <link href="{{ asset('css/about.css?v=5.5') }}" rel="stylesheet">
    <link href="{{ asset('css/ui-foundation.css?v=3') }}" rel="stylesheet">
    <link href="{{ asset('css/public-remake.css?v=3') }}" rel="stylesheet">

    @stack('styles')
</head>

<body class="public-marketing">

{{-- ================= MARKETING NAV ================= --}}
<header class="marketing-nav">
    <nav class="navbar navbar-expand-lg navbar-light bg-white">
        <div class="container">

            {{-- Logo --}}
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ url('/') }}">
                <img src="{{ asset('images/logo-colored.png') }}" alt="LW Registry" height="32">
                <strong>LW Registry</strong>
            </a>

            {{-- Mobile Toggle --}}
            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#marketingNavbar"
                aria-controls="marketingNavbar"
                aria-expanded="false"
                aria-label="Toggle navigation"
            >
                <span class="navbar-toggler-icon"></span>
            </button>

            {{-- Links --}}
            <div class="collapse navbar-collapse" id="marketingNavbar">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-4">

                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="#members">Members</a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="#businesses">Businesses</a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="#sbos">SBOs</a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="#team">Team</a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('feedback') }}">Give Feedback</a>
                    </li>

                    <li class="nav-item ms-lg-3">
                        <a href="#contact" class="btn btn-cta">
                            Contact Us
                        </a>
                    </li>

                </ul>
            </div>

        </div>
    </nav>
</header>



{{-- ================= PAGE CONTENT ================= --}}
<main>
    @yield('content')
</main>

{{-- ================= FOOTER ================= --}}
@include('components.footer')

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('js/form-help.js?v=4') }}"></script>
<script src="{{ asset('js/ui-feedback.js?v=1') }}"></script>
@stack('scripts')
</body>
</html>
