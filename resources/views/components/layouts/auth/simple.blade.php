<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="auth-shell font-sans min-h-screen antialiased">

        <div class="auth-shell__inner flex min-h-screen flex-col items-center justify-center px-6">

            {{-- Logo --}}
            <div class="mb-10 flex items-center gap-3">
                <img
                    src="{{ asset('images/logo-light.png') }}"
                    alt="{{ config('app.name') }}"
                    style="height: 40px;"
                />
            </div>

            {{-- Card Slot --}}
            <div class="w-full max-w-md">
                {{ $slot }}
            </div>

        </div>
    </body>

</html>
