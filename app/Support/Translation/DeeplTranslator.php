<?php

declare(strict_types=1);

namespace App\Support\Translation;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DeepL.
 *
 * Free and Pro accounts use different hosts, and the key itself says which:
 * a free key ends in ":fx". Reading it from the key rather than asking for a
 * second setting removes a way to configure this wrongly.
 */
final class DeeplTranslator implements Translator
{
    public function __construct(
        private readonly ?string $key,
        private readonly int $timeout = 10,
    ) {}

    public function translate(string $text, string $target, ?string $source = null): TranslatedText
    {
        if ( ! $this->isConfigured()) {
            throw new TranslationFailed('DeepL is not configured.');
        }

        try {
            $response = Http::asForm()
                ->timeout($this->timeout)
                ->withHeaders(['Authorization' => 'DeepL-Auth-Key ' . $this->key])
                ->post($this->endpoint(), array_filter([
                    'text' => $text,
                    // DeepL wants upper-case tags, and EN/PT need a variant.
                    'target_lang' => $this->tag($target),
                    'source_lang' => $source ? $this->tag($source) : null,
                ]));
        } catch (Throwable $exception) {
            throw new TranslationFailed('DeepL request failed: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new TranslationFailed('DeepL responded ' . $response->status() . '.');
        }

        $translation = $response->json('translations.0');

        if ( ! is_array($translation) || ! isset($translation['text'])) {
            throw new TranslationFailed('DeepL returned no translation.');
        }

        return new TranslatedText(
            (string) $translation['text'],
            isset($translation['detected_source_language'])
                ? mb_strtolower((string) $translation['detected_source_language'])
                : null,
        );
    }

    /**
     * DeepL has no standalone detection endpoint — it reports the source
     * language as part of a translation. Returning null means the caller falls
     * back to offering translation without pre-filtering.
     */
    public function detect(string $text): ?string
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return filled($this->key);
    }

    private function endpoint(): string
    {
        // A free key is suffixed ":fx" and must use the free host.
        return str_ends_with((string) $this->key, ':fx')
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';
    }

    private function tag(string $locale): string
    {
        $upper = mb_strtoupper($locale);

        // DeepL rejects bare EN and PT as targets; pick a sensible variant.
        return match ($upper) {
            'EN' => 'EN-GB',
            'PT' => 'PT-PT',
            default => $upper,
        };
    }
}
