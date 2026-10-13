<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\TurnstileVerifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidTurnstileToken implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ( ! app(TurnstileVerifier::class)->verify(is_string($value) ? $value : null, request()->ip())) {
            $fail('Please confirm that you are not a robot.');
        }
    }
}
