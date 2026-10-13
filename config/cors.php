<?php

declare(strict_types=1);

/*
 * CORS is scoped to the GBPC endpoints only. The GBPC landing page is a static
 * site on gbpc.lwregistry.org that POSTs its conference-registration form here
 * cross-origin; nothing else in the app is consumed cross-origin (the SPA is
 * same-origin Inertia), so this stays deliberately narrow.
 */
return [
    'paths' => ['gbpc/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => [
        'https://gbpc.lwregistry.org',
        'https://www.gbpc.lwregistry.org',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,
];
