<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Machine translation
|--------------------------------------------------------------------------
|
| Powers the "See translation" control on SBO Network messages. Until a key is
| present the driver resolves to `null`, the endpoint refuses, and the chat
| hides the control — so nothing is called and nothing is billed.
|
| To switch it on, set TRANSLATION_DRIVER and the matching key:
|
|   TRANSLATION_DRIVER=libretranslate
|   LIBRETRANSLATE_URL=http://127.0.0.1:5050
|
|   TRANSLATION_DRIVER=deepl
|   DEEPL_API_KEY=xxxxxxxx:fx        # ":fx" suffix = free tier
|
|   TRANSLATION_DRIVER=google
|   GOOGLE_TRANSLATE_KEY=xxxxxxxx
|
*/

return [
    'driver' => env('TRANSLATION_DRIVER', 'null'),

    'timeout' => (int) env('TRANSLATION_TIMEOUT', 10),

    /*
     * Longest message we will send to a provider. Providers bill per character
     * and a runaway paste should not turn into a surprise invoice; anything
     * longer is refused rather than silently truncated, because a half
     * translated message is worse than none.
     */
    'max_characters' => (int) env('TRANSLATION_MAX_CHARACTERS', 5000),

    /*
     * Self-hosted, so nothing is billed and no message leaves your
     * infrastructure.
     *
     * Port 5050, not LibreTranslate's own default of 5000: on macOS the
     * AirPlay Receiver already listens on 5000 and answers every request with
     * a 403, which looks exactly like a misconfigured translator.
     *
     * One container, where Docker is available:
     *
     *   docker run -d --name libretranslate -p 5050:5000 \
     *     libretranslate/libretranslate --load-only en,fr,es
     *
     * Without Docker (this machine), it runs from a virtualenv — see
     * docs/translation.md.
     */
    'libretranslate' => [
        'url' => env('LIBRETRANSLATE_URL'),
        // Only needed if the container was started with --api-keys.
        'key' => env('LIBRETRANSLATE_KEY'),
    ],

    'deepl' => [
        'key' => env('DEEPL_API_KEY'),
    ],

    'google' => [
        'key' => env('GOOGLE_TRANSLATE_KEY'),
    ],
];
