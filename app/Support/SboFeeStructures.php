<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The fixed set of SBO share structures.
 *
 * These are legal categories, not tunable amounts, so they live in code (by
 * decision). Everything the MOC tunes over time — fee bands, capital types,
 * the flat fee, currency and merchant — lives in the database and is edited
 * from the admin Fees page. Adding a structure here is a rare code change.
 */
final class SboFeeStructures
{
    /**
     * All structures, keyed by their stored value.
     *
     * `shares => true` means the structure has share capital and proceeds to
     * the per-share fee flow; `false` skips to the flat fee.
     *
     * @return array<string, array{label: string, shares: bool}>
     */
    public static function all(): array
    {
        return [
            'private' => ['label' => __('fee.structure.private'), 'shares' => true],
            'public' => ['label' => __('fee.structure.public'), 'shares' => true],
            'no_shares' => ['label' => __('fee.structure.no_shares'), 'shares' => false],
            'sole_partnership' => ['label' => __('fee.structure.sole_partnership'), 'shares' => false],
        ];
    }

    /**
     * Structure options for the gate, as key => label.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(static fn (array $s): string => $s['label'], self::all());
    }

    public static function isValid(string $structure): bool
    {
        return array_key_exists($structure, self::all());
    }

    /** Whether the structure has share capital (vs. paying the flat fee). */
    public static function isShare(string $structure): bool
    {
        return self::all()[$structure]['shares'] ?? false;
    }

    /**
     * The share-bearing structures only (private, public).
     *
     * @return array<int, string>
     */
    public static function shareStructures(): array
    {
        return array_keys(array_filter(self::all(), static fn (array $s): bool => $s['shares']));
    }

    public static function label(string $structure): ?string
    {
        return self::all()[$structure]['label'] ?? null;
    }
}
