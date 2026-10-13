<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Explore the Global Directory" — the five browse-by rows on the home page.
 *
 * All five linked to `/categories` regardless of which one was clicked, so the
 * labels were decoration. Each is a real facet now: this class says what a
 * facet is called, how to list its values with counts, and how to filter the
 * directory by one.
 *
 * Every facet reads a column that already exists. Nothing here invents a
 * classification the registry does not hold:
 *
 *   industry      → `sbos.industry`      (see SboIndustries)
 *   country       → `sbos.country`
 *   service       → `sbos.category`      — the specific trade, "Find specific
 *                                          services" in the design
 *   certification → verification state   — the same definition the directory's
 *                                          Verified filter already uses
 *   size          → `sbos.staff_count`   — banded, because a headcount is not
 *                                          something anyone browses by exactly
 */
final class SboBrowseFacets
{
    public const INDUSTRY = 'industry';

    public const COUNTRY = 'country';

    public const SERVICE = 'service';

    public const CERTIFICATION = 'certification';

    public const SIZE = 'size';

    /**
     * Employee bands, smallest first.
     *
     * The usual micro/small/medium/large split. `min`/`max` are inclusive;
     * a null `max` is open-ended.
     *
     * Labels are translation keys, not prose: a const is built once at compile
     * time and would freeze whichever language happened to be active first.
     *
     * @var array<string, array{label: string, min: int, max: int|null}>
     */
    private const SIZE_BANDS = [
        '1-10' => ['label' => 'browse.size.1_10', 'min' => 1, 'max' => 10],
        '11-50' => ['label' => 'browse.size.11_50', 'min' => 11, 'max' => 50],
        '51-200' => ['label' => 'browse.size.51_200', 'min' => 51, 'max' => 200],
        '201+' => ['label' => 'browse.size.201_plus', 'min' => 201, 'max' => null],
    ];

    /**
     * The certification levels a visitor can browse by.
     *
     * "Fully verified" is `SboVerificationScopes`' definition — approved, documents
     * compliant and fees settled — so this row and the directory's own Verified
     * filter can never disagree. Declined registrations are not public and so
     * are not a level.
     *
     * @var array<string, array{label: string, description: string, status: string}>
     */
    private const CERTIFICATION_LEVELS = [
        'verified' => [
            'label' => 'data.status.fully_verified',
            'description' => 'browse.certification.verified_description',
            'status' => 'verified',
        ],
        'registered' => [
            'label' => 'data.status.registered',
            'description' => 'browse.certification.registered_description',
            'status' => 'unverified',
        ],
    ];

    /** Whether a facet key is one this class knows. */
    public static function exists(string $facet): bool
    {
        return array_key_exists($facet, self::definitions());
    }

    /**
     * The five rows, in the order the home page lists them.
     *
     * @return array<int, array<string, string>>
     */
    public static function menu(): array
    {
        return collect(self::definitions())
            ->map(fn (array $definition, string $key): array => [
                'key' => $key,
                'label' => $definition['label'],
                'sub' => $definition['sub'],
                'url' => route('browse.show', $key),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    public static function definition(string $facet): array
    {
        return self::definitions()[$facet];
    }

    /**
     * The values of a facet, each with the count of listings behind it and the
     * directory URL that filters by it.
     *
     * Only values with at least one listing are returned, so a browse page
     * never offers a row that leads to an empty directory.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function options(string $facet): Collection
    {
        return match ($facet) {
            self::INDUSTRY => self::columnOptions('industry', 'industry'),
            self::COUNTRY => self::columnOptions('country', 'country'),
            self::SERVICE => self::columnOptions('category', 'category'),
            self::CERTIFICATION => self::certificationOptions(),
            self::SIZE => self::sizeOptions(),
        };
    }

    /**
     * Narrow a directory query to one band of the size facet.
     *
     * Lives here rather than in the controller so the band boundaries have one
     * definition — the browse page counts by them and the directory filters by
     * them, and the two drifting apart would show a count that the filtered
     * page then contradicts.
     *
     * @param  Builder<Sbo>  $query
     */
    public static function applySize(Builder $query, string $band): void
    {
        if ( ! array_key_exists($band, self::SIZE_BANDS)) {
            return;
        }

        ['min' => $min, 'max' => $max] = self::SIZE_BANDS[$band];

        $query->where('staff_count', '>=', $min)
            ->when(null !== $max, fn (Builder $q): Builder => $q->where('staff_count', '<=', $max));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function definitions(): array
    {
        /*
         * Labels are the design's own (1:211). Three of the five sub-labels are
         * copy-paste slips in the frame — "By Country", "By Service" and "By
         * Certification" all read as a repeat of a neighbouring row — so each
         * describes its own facet here, as the By Country row already did.
         */
        return [
            self::INDUSTRY => [
                'label' => __('browse.industry.label'),
                'sub' => __('browse.industry.sub'),
                'title' => __('browse.industry.title'),
                'blurb' => __('browse.industry.blurb'),
                'param' => 'industry',
            ],
            self::COUNTRY => [
                'label' => __('browse.country.label'),
                'sub' => __('browse.country.sub'),
                'title' => __('browse.country.title'),
                'blurb' => __('browse.country.blurb'),
                'param' => 'country',
            ],
            self::SERVICE => [
                'label' => __('browse.service.label'),
                'sub' => __('browse.service.sub'),
                'title' => __('browse.service.title'),
                'blurb' => __('browse.service.blurb'),
                'param' => 'category',
            ],
            self::CERTIFICATION => [
                'label' => __('browse.certification.label'),
                'sub' => __('browse.certification.sub'),
                'title' => __('browse.certification.title'),
                'blurb' => __('browse.certification.blurb'),
                'param' => 'status',
            ],
            self::SIZE => [
                'label' => __('browse.size.label'),
                'sub' => __('browse.size.sub'),
                'title' => __('browse.size.title'),
                'blurb' => __('browse.size.blurb'),
                'param' => 'size',
            ],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function columnOptions(string $column, string $param): Collection
    {
        /*
         * `value` is the stored string, because it is what `?industry=` carries
         * and what the directory query matches on. `label` is that same string
         * in the reader's language. Translating the value too would filter on a
         * word no row holds.
         */
        $label = self::labeller($column);

        return self::browsable()
            ->select($column)
            ->selectRaw('count(*) as total')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(fn ($row): array => [
                'value' => (string) $row->{$column},
                'label' => $label((string) $row->{$column}),
                'description' => null,
                'count' => (int) $row->total,
                'url' => route('businesses.index', [$param => $row->{$column}]),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function certificationOptions(): Collection
    {
        return collect(self::CERTIFICATION_LEVELS)
            ->map(function (array $level): array {
                $query = self::browsable();

                'verified' === $level['status']
                    ? SboVerificationScopes::fullyVerified($query)
                    : SboVerificationScopes::notFullyVerified($query);

                return [
                    'value' => $level['status'],
                    'label' => __($level['label']),
                    'description' => __($level['description']),
                    'count' => $query->count(),
                    'url' => route('businesses.index', ['status' => $level['status']]),
                ];
            })
            ->values()
            ->filter(fn (array $option): bool => $option['count'] > 0)
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function sizeOptions(): Collection
    {
        return collect(self::SIZE_BANDS)
            ->map(function (array $band, string $key): array {
                $query = self::browsable();
                self::applySize($query, $key);

                return [
                    'value' => $key,
                    'label' => __($band['label']),
                    'description' => null,
                    'count' => $query->count(),
                    'url' => route('businesses.index', ['size' => $key]),
                ];
            })
            ->values()
            ->filter(fn (array $option): bool => $option['count'] > 0)
            ->values();
    }

    /**
     * How a facet's stored values are shown to a reader.
     *
     * @return callable(string): string
     */
    private static function labeller(string $column): callable
    {
        return match ($column) {
            'industry' => fn (string $v): string => (string) DataLabels::get(DataLabels::INDUSTRY, $v),
            'category' => fn (string $v): string => (string) DataLabels::get(DataLabels::CATEGORY, $v),
            'country' => fn (string $v): string => (string) DataLabels::country($v),
            default => fn (string $v): string => $v,
        };
    }

    /**
     * The listings a browse page counts: the same set the directory shows, so
     * a count here matches the result page it leads to.
     *
     * @return Builder<Sbo>
     */
    private static function browsable(): Builder
    {
        return Sbo::query()->where('verification_status', '!=', 'declined');
    }
}
