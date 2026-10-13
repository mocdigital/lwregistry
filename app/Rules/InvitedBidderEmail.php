<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use App\Support\SboNetworkAccess;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An invited email must belong to a verified member.
 *
 * A private request is invited so that specific businesses can bid — and only a
 * verified business can bid. Inviting an address that is not a verified member
 * would hand access to someone who could never take part, so it is refused here
 * with a reason the owner can act on.
 */
final class InvitedBidderEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || '' === mb_trim($value)) {
            $fail('Enter a valid email address.');

            return;
        }

        $email = mb_strtolower(mb_trim($value));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (null === $user || ! app(SboNetworkAccess::class)->userCanUseNetwork($user)) {
            $fail($email . ' is not a verified member, so cannot be invited — only verified businesses can bid.');
        }
    }
}
