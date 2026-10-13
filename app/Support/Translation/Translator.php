<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * A machine-translation provider.
 *
 * An interface rather than a direct API call so the vendor is a configuration
 * choice: DeepL reads better on business French and Spanish, Google covers more
 * languages, and a self-hosted LibreTranslate costs nothing. Swapping between
 * them must not mean touching the controller, the cache, or the UI.
 */
interface Translator
{
    /**
     * Translate `$text` into `$target`.
     *
     * `$source` is a hint, not a requirement — providers detect the source
     * language themselves and are usually better at it than we would be.
     *
     * @throws TranslationFailed when the provider is unreachable or refuses
     */
    public function translate(string $text, string $target, ?string $source = null): TranslatedText;

    /**
     * Which language `$text` is in, or null when the provider cannot say.
     *
     * Part of the contract because deciding whether to OFFER a translation is
     * half the feature: a thread already in the reader's language should not
     * carry a "See translation" link on every message.
     */
    public function detect(string $text): ?string;

    /** Whether this provider has everything it needs to run. */
    public function isConfigured(): bool;
}
