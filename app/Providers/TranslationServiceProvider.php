<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Translation\DeeplTranslator;
use App\Support\Translation\GoogleTranslator;
use App\Support\Translation\LibreTranslateTranslator;
use App\Support\Translation\NullTranslator;
use App\Support\Translation\Translator;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the configured machine-translation provider.
 *
 * Defaults to NullTranslator, which reports itself unconfigured — so a fresh
 * checkout with no API key behaves correctly rather than erroring: the chat
 * simply does not offer translation.
 */
final class TranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Translator::class, function (): Translator {
            $timeout = (int) config('translation.timeout', 10);

            return match (config('translation.driver')) {
                'deepl' => new DeeplTranslator(config('translation.deepl.key'), $timeout),
                'google' => new GoogleTranslator(config('translation.google.key'), $timeout),
                'libretranslate' => new LibreTranslateTranslator(
                    config('translation.libretranslate.url'),
                    config('translation.libretranslate.key'),
                    $timeout,
                ),
                default => new NullTranslator(),
            };
        });
    }
}
