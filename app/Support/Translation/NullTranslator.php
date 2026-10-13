<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * The provider used when none is configured.
 *
 * Reports itself unconfigured so the chat hides the "See translation" control
 * entirely. Offering a button that always fails is worse than not offering one:
 * it teaches people the feature is broken rather than that it is switched off.
 */
final class NullTranslator implements Translator
{
    public function translate(string $text, string $target, ?string $source = null): TranslatedText
    {
        throw new TranslationFailed('No translation provider is configured.');
    }

    public function detect(string $text): ?string
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
