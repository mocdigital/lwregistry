<?php

declare(strict_types=1);

namespace App\Support\Translation;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Cloud Translation (v2), which authenticates with a plain API key.
 *
 * v2 rather than v3 deliberately: v3 needs a service account and a project id,
 * which is a heavier setup than this feature justifies.
 */
final class GoogleTranslator implements Translator
{
    public function __construct(
        private readonly ?string $key,
        private readonly int $timeout = 10,
    ) {}

    public function translate(string $text, string $target, ?string $source = null): TranslatedText
    {
        if ( ! $this->isConfigured()) {
            throw new TranslationFailed('Google Translation is not configured.');
        }

        try {
            $response = Http::timeout($this->timeout)
                ->post('https://translation.googleapis.com/language/translate/v2?key=' . urlencode((string) $this->key), array_filter([
                    'q' => $text,
                    'target' => $target,
                    'source' => $source,
                    // The chat stores plain text; asking for HTML would return
                    // escaped entities the bubble would then render literally.
                    'format' => 'text',
                ]));
        } catch (Throwable $exception) {
            throw new TranslationFailed('Google request failed: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new TranslationFailed('Google responded ' . $response->status() . '.');
        }

        $translation = $response->json('data.translations.0');

        if ( ! is_array($translation) || ! isset($translation['translatedText'])) {
            throw new TranslationFailed('Google returned no translation.');
        }

        return new TranslatedText(
            (string) $translation['translatedText'],
            isset($translation['detectedSourceLanguage'])
                ? mb_strtolower((string) $translation['detectedSourceLanguage'])
                : null,
        );
    }

    public function detect(string $text): ?string
    {
        if ( ! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->post('https://translation.googleapis.com/language/translate/v2/detect?key='
                    . urlencode((string) $this->key), ['q' => $text]);

            if ($response->failed()) {
                return null;
            }

            $language = $response->json('data.detections.0.0.language');

            return is_string($language) ? mb_strtolower($language) : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function isConfigured(): bool
    {
        return filled($this->key);
    }
}
