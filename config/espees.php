<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Naira → Espees conversion
    |--------------------------------------------------------------------------
    |
    | How many Naira make one Espee. Used to show the Espees equivalent of a
    | Naira amount: espees = naira / naira_per_espee. Override per-environment
    | with ESPEES_NAIRA_PER_ESPEE as the rate changes.
    |
    */
    'naira_per_espee' => (float) env('ESPEES_NAIRA_PER_ESPEE', 2050),

    /*
    |--------------------------------------------------------------------------
    | Espees test account
    |--------------------------------------------------------------------------
    |
    | This account is intentionally opt-in and is unavailable in production
    | unless the separate production-triage flag is enabled. Its checkout
    | amount is fixed in code at one Espee so it cannot charge a real fee.
    |
    */
    'test_account' => [
        'enabled' => filter_var(env('ESPEES_TEST_ACCOUNT_ENABLED', false), FILTER_VALIDATE_BOOL),
        'allow_production' => filter_var(env('ESPEES_TEST_ACCOUNT_ALLOW_PRODUCTION', false), FILTER_VALIDATE_BOOL),
        'email' => env('ESPEES_TEST_ACCOUNT_EMAIL', 'espees.test@lwregistry.test'),
        'password' => env('ESPEES_TEST_ACCOUNT_PASSWORD', 'EspeesTest!2026'),
    ],
];
