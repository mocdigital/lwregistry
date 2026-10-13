<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inertia
|--------------------------------------------------------------------------
|
| Published purely to point the page resolver at where the pages actually
| live. The package default is `resources/js/Pages`; this app resolves
| `Inertia::render('account/NotificationsView')` from `resources/js/spa/views`
| (see `resources/js/inertia.ts`), so `assertInertia(...)->component(...)`
| failed with "page component file does not exist" for every screen.
|
| Everything else is the package default.
*/

return [

    'ssr' => [
        // No SSR service runs for this app; the Vue front end is client-only.
        'enabled' => false,
    ],

    'ensure_pages_exist' => false,

    'page_paths' => [
        resource_path('js/spa/views'),
    ],

    'page_extensions' => [
        'js',
        'jsx',
        'svelte',
        'ts',
        'tsx',
        'vue',
    ],

    'testing' => [

        'ensure_pages_exist' => true,

        'page_paths' => [
            resource_path('js/spa/views'),
        ],

        'page_extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    'history' => [
        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', false),
    ],

];
