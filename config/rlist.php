<?php

declare(strict_types=1);

/*
 * The unlisted registered-business list at /rlist/businesses.
 *
 * Not linked from anywhere, not in a sitemap, and served with a noindex
 * X-Robots-Tag — the only way in is to know the URL and the password.
 *
 * A bcrypt HASH, never the password itself, and overridable per environment so
 * the password can be changed without a deploy: set RLIST_PASSWORD_HASH in the
 * server's .env.
 *
 * The hash is of the NORMALISED password — surrounding whitespace removed and
 * internal runs collapsed to one space, see
 * RegisteredBusinessListController::normalise. Generate a replacement the same
 * way, or one-space-versus-two will lock you out:
 *
 *   php -r '$p = trim(preg_replace("/\s+/u", " ", "the new password"));
 *           echo password_hash($p, PASSWORD_BCRYPT), PHP_EOL;'
 */
return [
    'password_hash' => env(
        'RLIST_PASSWORD_HASH',
        '$2y$12$RCg/ACqJvp8O4nZepIrYHehfGRSZJBr6DXR1Moy6ndXsxLNq3kZAG',
    ),

    /** Session key holding the unlocked state. */
    'session_key' => 'rlist_unlocked_at',

    /** How long an unlock lasts before the password is asked for again. */
    'unlock_minutes' => 120,
];
