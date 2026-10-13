<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>@yield('title', 'Loveworld Business Registry')</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- intl-tel-input (phone field with country flag + dial code) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.0/build/css/intlTelInput.css">
    
    <!-- Custom CSS -->
    <link href="{{ asset('css/style.css?v=6.4') }}" rel="stylesheet">
    <link href="{{ asset('css/form-help.css?v=3') }}" rel="stylesheet">
    <link href="{{ asset('css/notification-bell.css?v=2') }}" rel="stylesheet">
    <link href="{{ asset('css/responsive.css?v=7') }}" rel="stylesheet">
    <link href="{{ asset('css/ui-foundation.css?v=3') }}" rel="stylesheet">
    <link href="{{ asset('css/sbo-workspace.css?v=2') }}" rel="stylesheet">
    <link href="{{ asset('css/public-remake.css?v=3') }}" rel="stylesheet">
    @stack('styles')
    @livewireStyles
</head>
<body>
    @auth
        <x-account-nav />
    @else
        <x-nav />
    @endauth

    @yield('content')

    {{-- Livewire full-page (Volt) components render their root element here. --}}
    @if(isset($slot) && ! is_array($slot))
        {{ $slot }}
    @endif

    <div class="container">
        <x-ad-slot name="global_footer" />
    </div>

    <x-footer />

    <!-- Auth Modals -->
    @livewire('auth-modal')


    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('js/form-help.js?v=4') }}"></script>
    <script src="{{ asset('js/ui-feedback.js?v=1') }}"></script>

    <!-- intl-tel-input (phone field with country flag + dial code) -->
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.0/build/js/intlTelInput.min.js"></script>
    <script>
        // Alpine factory for the international phone field (see partials/intl-phone.blade.php)
        function intlPhone(initialValue, model, mode = 'international') {
            return {
                iti: null,
                init() {
                    const input = this.$refs.input;
                    if (initialValue && mode !== 'local-digits') {
                        input.value = initialValue;
                    }

                    this.iti = window.intlTelInput(input, {
                        initialCountry: 'ng',
                        separateDialCode: true,
                        nationalMode: mode === 'local-digits',
                        countryOrder: ['ng', 'gh', 'za', 'ke', 'us', 'gb'],
                        loadUtils: () => import('https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.0/build/js/utils.js'),
                    });

                    if (initialValue && mode === 'local-digits') {
                        const selectedDialCode = this.iti.getSelectedCountryData().dialCode || '';
                        const digits = String(initialValue).replace(/\D+/g, '');

                        input.value = selectedDialCode && digits.startsWith(selectedDialCode)
                            ? digits.slice(selectedDialCode.length)
                            : digits;
                    }

                    const push = () => {
                        let number = this.iti.getNumber() || input.value;

                        if (mode === 'local-digits') {
                            const digits = input.value.replace(/\D+/g, '');
                            input.value = digits;

                            const dialCode = this.iti.getSelectedCountryData().dialCode || '';
                            number = dialCode && digits ? `+${dialCode}${digits}` : digits;
                        }

                        this.$wire.set(model, number, false);
                    };

                    input.addEventListener('input', push);
                    input.addEventListener('countrychange', push);
                    input.addEventListener('blur', push);
                },
            };
        }
    </script>
    <style>
        .intl-phone-wrapper .iti { display: block; width: 100%; }
        .intl-phone-wrapper .iti__tel-input,
        .intl-phone-wrapper input.form-control { width: 100%; }
        /* Keep the country dropdown above the Bootstrap modal (z-index 1055) */
        .iti__dropdown-content { z-index: 1080; }
    </style>
    
    <script>
        // Global handler for .openLogin class - triggers login modal for guests
        document.addEventListener('click', function(e) {
            if (e.target.closest('.openLogin')) {
                e.preventDefault();
                const loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
                loginModal.show();
            }
        });
    </script>

    <script>
    function shareLocation(url, title) {
        if (navigator.share) {
        navigator.share({
            title: title,
            text: title,
            url: url
        });
        } else {
        navigator.clipboard.writeText(url).then(() => {
            alert('Map link copied to clipboard');
        });
        }
    }
    </script>


    @include('partials.echo')
    @stack('scripts')
    @include('partials.livewire-scripts')
    @include('partials.flux-scripts')
</body>
</html>
