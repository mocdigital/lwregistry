<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SboCapitalType;
use App\Models\SboFeeBand;
use App\Models\SboFeeSetting;
use InvalidArgumentException;

/**
 * Resolves the registration fee (in the configured currency) an SBO owes.
 *
 * The fee depends first on the SBO's share structure. Share-bearing structures
 * (private/public) derive a fee from their equity size (issued shares ×
 * per-share value) via structure-scoped bands. Non-share structures pay a
 * single flat fee.
 *
 * Country scoping: the schedule is resolved for a given country_id. A country
 * OVERRIDES the schedule per structure (bands, capital types) or per setting
 * (flat fee, currency, merchant) when it has rows of its own; otherwise it
 * inherits the GLOBAL DEFAULT (country_id = null). Passing null resolves the
 * global default directly. Legal structures themselves are fixed in code
 * ({@see SboFeeStructures}).
 *
 * The schedule is stored in the database and managed by admins on the Fees
 * page; this class reads it and memoizes per request.
 */
final class SboFeeCalculator
{
    /**
     * Per-request cache of active bands keyed by "countryId|structure".
     *
     * @var array<string, array<int, array{id: int, max_equity: int|null, fee: int, label: string}>>
     */
    private static array $bandCache = [];

    /** Per-request cache of settings keyed by "countryId|key". */
    private static array $settingCache = [];

    /**
     * Whether the given share structure has share capital and therefore
     * proceeds to the per-share fee flow (vs. the flat fee).
     */
    public static function isShareStructure(string $structure): bool
    {
        return SboFeeStructures::isShare($structure);
    }

    /**
     * Whether the given key is a recognised share structure.
     */
    public static function isValidStructure(string $structure): bool
    {
        return SboFeeStructures::isValid($structure);
    }

    /**
     * All structure options for the gate, as key => label.
     *
     * @return array<string, string>
     */
    public static function structures(): array
    {
        return SboFeeStructures::labels();
    }

    /**
     * Active share-capital types offered for a structure, key => label.
     * Country-specific types override the global set when the country defines
     * any for the structure; otherwise the global set is returned.
     *
     * @return array<string, string>
     */
    public static function capitalTypesFor(string $structure, ?int $countryId = null): array
    {
        if (null !== $countryId) {
            $scoped = SboCapitalType::query()
                ->where('country_id', $countryId)
                ->where('structure', $structure)
                ->where('is_active', true)
                ->pluck('label', 'key')
                ->all();

            if ([] !== $scoped) {
                return $scoped;
            }
        }

        return SboCapitalType::query()
            ->whereNull('country_id')
            ->where('structure', $structure)
            ->where('is_active', true)
            ->pluck('label', 'key')
            ->all();
    }

    public static function isValidCapitalType(string $structure, string $key, ?int $countryId = null): bool
    {
        return array_key_exists($key, self::capitalTypesFor($structure, $countryId));
    }

    /**
     * Equity size = issued shares × per-share value.
     */
    public static function equity(int $issuedShares, float $perShareValue): float
    {
        return $issuedShares * $perShareValue;
    }

    /**
     * Active band options for the selected structure/country, including the
     * previous ceiling so consumers can display and derive equity ranges.
     *
     * @return array<int, array{id: int, max_equity: int|null, previous_max_equity: int|null, fee: int, label: string, range_label: string, display_label: string}>
     */
    public static function bandOptionsFor(string $structure, ?int $countryId = null): array
    {
        $previousMax = null;

        return array_map(function (array $band) use (&$previousMax): array {
            $rangeLabel = self::bandRangeLabel($previousMax, $band['max_equity']);
            $option = $band + [
                'previous_max_equity' => $previousMax,
                'range_label' => $rangeLabel,
                'display_label' => self::bandDisplayLabel($band['label'], $rangeLabel),
            ];

            if (null !== $band['max_equity']) {
                $previousMax = $band['max_equity'];
            }

            return $option;
        }, self::bands($structure, $countryId));
    }

    /**
     * A single active band option by id, resolved through the same country
     * override/fallback behavior as fee calculation.
     *
     * @return array{id: int, max_equity: int|null, previous_max_equity: int|null, fee: int, label: string, range_label: string, display_label: string}|null
     */
    public static function bandOptionFor(string $structure, int $bandId, ?int $countryId = null): ?array
    {
        foreach (self::bandOptionsFor($structure, $countryId) as $band) {
            if ($band['id'] === $bandId) {
                return $band;
            }
        }

        return null;
    }

    /**
     * Minimum whole issued-share volume that lands inside a selected band for
     * the given per-share value. Returns null when no whole share value can fit.
     *
     * @param  array{id: int, max_equity: int|null, previous_max_equity: int|null, fee: int, label: string, range_label: string, display_label: string}  $band
     */
    public static function issuedSharesForBand(array $band, float $perShareValue): ?int
    {
        if ($perShareValue <= 0) {
            return null;
        }

        $issuedShares = null === $band['previous_max_equity']
            ? 1
            : ((int) floor($band['previous_max_equity'] / $perShareValue)) + 1;

        if (null !== $band['max_equity'] && self::equity($issuedShares, $perShareValue) > $band['max_equity']) {
            return null;
        }

        return max(1, $issuedShares);
    }

    /**
     * The band a given equity size falls into, for a share structure.
     * Returns null if no band matches (misconfigured bands).
     *
     * @return array{id: int, max_equity: int|null, fee: int, label: string}|null
     */
    public static function bandFor(string $structure, float $equity, ?int $countryId = null): ?array
    {
        foreach (self::bands($structure, $countryId) as $band) {
            if (null === $band['max_equity'] || $equity <= $band['max_equity']) {
                return $band;
            }
        }

        return null;
    }

    /**
     * The fee for a structure. Share structures require the two share inputs;
     * non-share structures ignore them and return the flat fee.
     */
    public static function fee(string $structure, ?int $issuedShares = null, ?float $perShareValue = null, ?int $countryId = null): int
    {
        if ( ! self::isValidStructure($structure)) {
            throw new InvalidArgumentException("Unknown share structure: {$structure}");
        }

        if ( ! self::isShareStructure($structure)) {
            return self::flatFee($countryId);
        }

        if (null === $issuedShares || null === $perShareValue) {
            throw new InvalidArgumentException(
                "Share structure '{$structure}' requires issued shares and per-share value."
            );
        }

        $band = self::bandFor($structure, self::equity($issuedShares, $perShareValue), $countryId);

        if (null === $band) {
            throw SboFeeScheduleMisconfiguredException::missingBand($structure);
        }

        return $band['fee'];
    }

    /**
     * Human-readable band label for a share structure, or null for flat-fee
     * (non-share) structures.
     */
    public static function feeLabel(string $structure, ?int $issuedShares = null, ?float $perShareValue = null, ?int $countryId = null): ?string
    {
        if ( ! self::isShareStructure($structure) || null === $issuedShares || null === $perShareValue) {
            return null;
        }

        return self::bandFor($structure, self::equity($issuedShares, $perShareValue), $countryId)['label'] ?? null;
    }

    /** The flat fee (in the configured currency) for non-share structures. */
    public static function flatFee(?int $countryId = null): int
    {
        return (int) self::setting('flat_fee', '0', $countryId);
    }

    /** The currency label fees are quoted in (e.g. "Espees"). */
    public static function currency(?int $countryId = null): string
    {
        return (string) self::setting('currency', 'Espees', $countryId);
    }

    /**
     * The Espees merchant account details.
     *
     * @return array{name: string, code: string, app: string}
     */
    public static function merchant(?int $countryId = null): array
    {
        $code = (string) self::setting('merchant_name', '', $countryId);

        return [
            'name' => $code,
            'code' => $code,
            'app' => (string) self::setting('merchant_app', '', $countryId),
        ];
    }

    /**
     * Espees gateway settings for advertising checkout and SBO registration
     * checkout. The dedicated test account uses these credentials for its
     * gated one-Espee path.
     * All credentials resolve through the same country override/global fallback
     * as fee settings.
     *
     * @return array{enabled: bool, token: string, merchant_wallet: string, product_url: string, payment_url: string, confirm_url: string, test_mode: bool, configured: bool}
     */
    public static function espeesGateway(?int $countryId = null): array
    {
        $enabled = self::truthy(self::setting('espees_enabled', '0', $countryId));
        $token = (string) self::setting('espees_token', '', $countryId);
        $wallet = (string) self::setting('espees_merchant_wallet', '', $countryId);
        $testMode = self::truthy(self::setting('espees_test_mode', '0', $countryId));

        return [
            'enabled' => $enabled,
            'token' => $token,
            'merchant_wallet' => $wallet,
            'product_url' => (string) config('services.espees.product_url'),
            'payment_url' => (string) config('services.espees.payment_url'),
            'confirm_url' => (string) config('services.espees.confirm_url'),
            'test_mode' => $testMode,
            'configured' => $enabled && '' !== $token && '' !== $wallet,
        ];
    }

    /** Clear the per-request memo (used by tests and after admin edits). */
    public static function flushCache(): void
    {
        self::$bandCache = [];
        self::$settingCache = [];
    }

    /**
     * Active bands for a structure in the given country scope, memoized per
     * request. Country-specific bands override the global set when the country
     * defines any for the structure; otherwise the global set is used.
     *
     * @return array<int, array{id: int, max_equity: int|null, fee: int, label: string}>
     */
    private static function bands(string $structure, ?int $countryId): array
    {
        $cacheKey = ($countryId ?? 'global') . '|' . $structure;

        if ( ! isset(self::$bandCache[$cacheKey])) {
            self::$bandCache[$cacheKey] = self::mapBands(
                self::resolveBandQuery($structure, $countryId)
            );
        }

        return self::$bandCache[$cacheKey];
    }

    /**
     * Resolve which band rows apply: the country's own if it defines any for
     * the structure, else the global default. Returns already-fetched models.
     *
     * @return \Illuminate\Support\Collection<int, SboFeeBand>
     */
    private static function resolveBandQuery(string $structure, ?int $countryId): \Illuminate\Support\Collection
    {
        if (null !== $countryId) {
            $scoped = SboFeeBand::query()
                ->where('country_id', $countryId)
                ->where('structure', $structure)
                ->where('is_active', true)
                ->get(['id', 'max_equity', 'fee', 'label']);

            if ($scoped->isNotEmpty()) {
                return $scoped;
            }
        }

        return SboFeeBand::query()
            ->whereNull('country_id')
            ->where('structure', $structure)
            ->where('is_active', true)
            ->get(['id', 'max_equity', 'fee', 'label']);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SboFeeBand>  $bands
     * @return array<int, array{id: int, max_equity: int|null, fee: int, label: string}>
     */
    private static function mapBands(\Illuminate\Support\Collection $bands): array
    {
        return $bands->map(fn (SboFeeBand $b): array => [
            'id' => $b->id,
            'max_equity' => $b->max_equity,
            'fee' => $b->fee,
            'label' => $b->label,
        ])->all();
    }

    private static function bandRangeLabel(?int $previousMax, ?int $maxEquity): string
    {
        if (null === $previousMax && null === $maxEquity) {
            return 'All share capital values';
        }

        if (null === $previousMax) {
            return 'Up to ' . number_format((int) $maxEquity);
        }

        if (null === $maxEquity) {
            return 'Above ' . number_format($previousMax);
        }

        return number_format($previousMax + 1) . ' - ' . number_format($maxEquity);
    }

    private static function bandDisplayLabel(string $label, string $rangeLabel): string
    {
        if (self::labelLooksLikeRange($label)) {
            return $label;
        }

        return $label . ' — ' . $rangeLabel;
    }

    private static function labelLooksLikeRange(string $label): bool
    {
        $normalised = str_replace(['–', '—'], '-', mb_trim($label));

        return 1 === preg_match('/^(up to|above|all share capital values)\b/i', $normalised)
            || 1 === preg_match('/\d[\d,.\s]*\s*-\s*\d[\d,.\s]*/', $normalised);
    }

    private static function setting(string $key, ?string $default, ?int $countryId): ?string
    {
        $cacheKey = ($countryId ?? 'global') . '|' . $key;

        if ( ! array_key_exists($cacheKey, self::$settingCache)) {
            self::$settingCache[$cacheKey] = SboFeeSetting::get($key, $default, $countryId);
        }

        return self::$settingCache[$cacheKey];
    }

    private static function truthy(?string $value): bool
    {
        return in_array(mb_strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
