<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class SboFeeScheduleMisconfiguredException extends RuntimeException
{
    public static function missingBand(string $structure): self
    {
        return new self("No active fee band matches the '{$structure}' share structure.");
    }
}
