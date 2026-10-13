<?php

declare(strict_types=1);

namespace App\Support\Translation;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * LibreTranslate, self-hosted.
 *
 * Chosen over DeepL and Google because it needs no vendor account, bills
 * nothing per character, and — the reason that matters for a business registry
 * — a private conversation between two members never leaves infrastructure you
 * control. It also works in every browser, which an on-device browser API does
 * not: Safari and Firefox have no equivalent, and their users would silently
 * lose the feature.
 *
 * Runs as one container, or from a virtualenv where there is no Docker —
 * docs/translation.md has both, and the models each one needs.
 *
 * `--load-only` matters. Loading every language pulls gigabytes of models;
 * three is a few hundred megabytes and starts in seconds.
 *
 *   TRANSLATION_DRIVER=libretranslate
 *   LIBRETRANSLATE_URL=http://127.0.0.1:5050
 *   LIBRETRANSLATE_KEY=            # only if you ran it with --api-keys
 *
 * Port 5050 rather than LibreTranslate's own 5000, because macOS AirPlay
 * Receiver holds 5000 and 403s everything sent to it.
 */
final class LibreTranslateTranslator implements Translator
{
    public function __construct(
        private readonly ?string $baseUrl,
        private readonly ?string $key = null,
        private readonly int $timeout = 10,
    ) {}

    public function translate(string $text, string $target, ?string $source = null): TranslatedText
    {
        if ( ! $this->isConfigured()) {
            throw new TranslationFailed('LibreTranslate is not configured.');
        }

        try {
            $response = Http::timeout($this->timeout)
                ->asJson()
                ->post($this->endpoint('translate'), array_filter([
                    'q' => $text,
                    // "auto" lets the server detect, which it does better than
                    // a guess made from the sender's interface language.
                    'source' => $source ?? 'auto',
                    'target' => $target,
                    'format' => 'text',
                    'api_key' => $this->key,
                ], static fn ($value): bool => null !== $value && '' !== $value));
        } catch (Throwable $exception) {
            throw new TranslationFailed(
                'LibreTranslate request failed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        if ($response->failed()) {
            throw new TranslationFailed('LibreTranslate responded ' . $response->status() . '.');
        }

        $translated = $response->json('translatedText');

        if ( ! is_string($translated) || '' === $translated) {
            throw new TranslationFailed('LibreTranslate returned no translation.');
        }

        return new TranslatedText(
            $translated,
            // Present only when `source` was "auto".
            $response->json('detectedLanguage.language'),
        );
    }

    /**
     * Which language a piece of text is in, or null if the server will not say.
     *
     * Used to decide whether to offer "See translation" at all: a thread
     * already in the reader's language should not carry the control on every
     * bubble.
     */
    public function detect(string $text): ?string
    {
        if ( ! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->asJson()
                ->post($this->endpoint('detect'), array_filter([
                    'q' => $text,
                    'api_key' => $this->key,
                ], static fn ($value): bool => null !== $value && '' !== $value));

            if ($response->failed()) {
                return null;
            }

            $language = $response->json('0.language');

            return is_string($language) ? mb_strtolower($language) : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function isConfigured(): bool
    {
        return filled($this->baseUrl);
    }

    private function endpoint(string $path): string
    {
        return mb_rtrim((string) $this->baseUrl, '/') . '/' . $path;
    }
}
