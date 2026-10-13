<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Not web-served: this is a private disk (app/private) and must not
            // own the public /storage URL. Serving is enabled on the `public`
            // disk below instead.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            /*
             * Live nginx currently returns 403 for /storage/* before Laravel
             * can fall back to serving the public disk. Generate public upload
             * URLs under /media instead; routes/web.php owns that endpoint and
             * route caching cannot remove it.
             */
            'url' => env('PUBLIC_STORAGE_URL', mb_rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/media'),
            'visibility' => 'public',
            // Serve public uploads (profile photos, etc.) through Laravel at
            // the disk URL path when possible. The explicit /media route is the
            // production-safe path because this provider route is skipped when
            // Laravel routes are cached.
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Private disk for sensitive compliance documents. Never publicly served;
        // files are streamed through a gated download route.
        'compliance' => [
            'driver' => 'local',
            'root' => storage_path('app/compliance'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Chat attachments. Private for the same reason compliance documents
         * are: a conversation is between two businesses, and a public disk
         * would make every file in it guessable by URL. Served through a route
         * that checks participation, never by the web server.
         */
        'chat' => [
            'driver' => 'local',
            'root' => storage_path('app/chat'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
