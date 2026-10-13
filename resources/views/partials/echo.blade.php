{{--
    Laravel Echo over Reverb, loaded standalone (this app has no Vite bundle).
    Emitted only for authenticated users and only when a Reverb key is set;
    otherwise the bell silently falls back to wire:poll.
--}}
@auth
    @php
        /*
         * Read from config, never env(): once config is cached (php artisan
         * optimize), env() outside config files returns null and the client
         * would fall back to ws on port 8080 — mixed content, blocked on an
         * https page. These are the same values the Inertia side is handed in
         * HandleInertiaRequests, so both halves connect identically.
         */
        $reverbKey = config('reverb.apps.apps.0.key');
        $reverbHost = config('broadcasting.connections.reverb.options.host') ?: request()->getHost();
        $reverbPort = (int) config('broadcasting.connections.reverb.options.port', 443);
        $reverbTls = 'https' === config('broadcasting.connections.reverb.options.scheme', 'https');
    @endphp
    @if($reverbKey)
        <script src="{{ asset('vendor/echo/pusher.min.js') }}"></script>
        <script src="{{ asset('vendor/echo/echo.iife.js') }}"></script>
        <script>
            (function () {
                // The IIFE exposes the Echo *class* as window.Echo; replace it
                // with a configured instance.
                if (window.Echo && typeof window.Echo === 'function' && window.Pusher) {
                    window.Echo = new window.Echo({
                        broadcaster: 'reverb',
                        key: @json($reverbKey),
                        wsHost: @json($reverbHost),
                        wsPort: @json($reverbPort),
                        wssPort: @json($reverbPort),
                        forceTLS: @json($reverbTls),
                        enabledTransports: ['ws', 'wss'],
                        authEndpoint: '/broadcasting/auth',
                        auth: {
                            headers: {
                                'X-CSRF-TOKEN': @json(csrf_token()),
                            },
                        },
                    });
                }
            })();
        </script>
    @endif
@endauth
