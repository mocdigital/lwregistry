<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * A translation and the language it came from.
 *
 * The detected source matters to the reader, not just to us: "Translated from
 * French" is the line that explains why the button was there at all.
 */
final class TranslatedText
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $detectedSource = null,
    ) {}
}
