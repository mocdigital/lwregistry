<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Snapshot import
    |--------------------------------------------------------------------------
    |
    | Whether the checked-in database snapshot is replayed by its migration.
    | Off unless a fresh installation explicitly asks for it.
    |
    */
    'import_snapshot' => env('IMPORT_REGISTRY_SNAPSHOT', false),

    /*
    |--------------------------------------------------------------------------
    | The registry's own listing
    |--------------------------------------------------------------------------
    |
    | The name members see on a conversation with the registry, and on every
    | reply an administrator sends. Deliberately an institution rather than a
    | person: staff change, and a member is writing to the registry.
    |
    | Used only when the listing is first created; renaming it afterwards is an
    | edit to that row, not to this value.
    |
    */
    'support_name' => env('REGISTRY_SUPPORT_NAME', 'LW Commerce Registry'),
];
