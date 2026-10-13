<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use Illuminate\Database\Eloquent\Builder;

/**
 * What "fully verified" means, in one place.
 *
 * Approved, documents compliant and the registration fee settled — all three,
 * which is why it is five conditions rather than a single status column.
 *
 * This was private to BusinessController. The browse-by-certification page
 * counts listings by the same definition, and a browse count that disagreed
 * with the directory page it links to would be worse than no count at all.
 *
 * @see SboBrowseFacets
 */
final class SboVerificationScopes
{
    /** @param  Builder<Sbo>  $query */
    public static function fullyVerified(Builder $query): void
    {
        $query->where('verification_status', 'approved')
            ->where('documents_status', 'compliant')
            ->whereNotNull('documents_compliant_at')
            ->where('payment_status', 'paid')
            ->whereNotNull('payment_confirmed_at');
    }

    /**
     * Not fully verified, but not declined either — a listing still working
     * through registration.
     *
     * @param  Builder<Sbo>  $query
     */
    public static function notFullyVerified(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->where('verification_status', '!=', 'approved')
                ->orWhere('documents_status', '!=', 'compliant')
                ->orWhereNull('documents_compliant_at')
                ->orWhere('payment_status', '!=', 'paid')
                ->orWhereNull('payment_confirmed_at');
        });
    }
}
