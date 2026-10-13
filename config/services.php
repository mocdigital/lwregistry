<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY') ?: env('RESEND_API_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'siteverify_url' => env('TURNSTILE_SITEVERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    ],

    /*
     * Espees merchant API.
     *
     * NOT /v2. That prefix is a different API Gateway stage which answers
     * every request with 403 {"message":"Forbidden"} — identically for a valid
     * key, a bogus key and no key at all, because it rejects at the key-
     * validation layer before reaching Espees' own code. Checkout could
     * therefore never start, and the failure was indistinguishable from an
     * expired merchant token. The unversioned paths are the live ones.
     *
     * Deliberately not pinned in the env files. They were, with the /v2 URLs,
     * and an env value beats this default — so correcting the code fixed
     * nothing anywhere a stale .env was already deployed. The env() calls stay
     * for anyone who genuinely needs to point at another host; the working
     * addresses just live in one place now instead of four.
     */
    'espees' => [
        'product_url' => env('ESPEES_PRODUCT_URL', 'https://api.espees.org/payment/product'),
        'payment_url' => env('ESPEES_PAYMENT_URL', 'https://payment.espees.org/pay'),
        'confirm_url' => env('ESPEES_CONFIRM_URL', 'https://api.espees.org/payment/confirm'),
    ],

    /*
     * The self-hosted sentence-embedding service, for the assistant's semantic
     * search. Localhost only — the model runs on this server, so nothing leaves
     * it. Off by default: without it, the assistant uses its lexical match.
     */
    'embeddings' => [
        'url' => env('EMBEDDINGS_URL', 'http://127.0.0.1:8091'),
        'enabled' => (bool) env('EMBEDDINGS_ENABLED', false),
    ],

];
