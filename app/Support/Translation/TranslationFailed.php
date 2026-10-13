<?php

declare(strict_types=1);

namespace App\Support\Translation;

use RuntimeException;

/**
 * The provider could not translate.
 *
 * Distinct from "no provider configured", which is not an error — it is the
 * normal state before an API key is added, and the UI hides the control rather
 * than offering one that always fails.
 */
final class TranslationFailed extends RuntimeException {}
