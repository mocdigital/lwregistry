<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A banded registration fee for a share-bearing structure. A band matches when
 * the SBO's equity size is <= max_equity; a null max_equity is the open-ended
 * top band. Admin-managed on the Fees page.
 *
 * Scoped by country: country_id = null is the global default; a specific
 * country_id overrides it for that country.
 *
 * @property int $id
 * @property int|null $country_id
 * @property string $structure
 * @property int|null $max_equity
 * @property int $fee
 * @property string $label
 * @property int $sort_order
 * @property bool $is_active
 */
final class SboFeeBand extends Model
{
    protected $fillable = [
        'country_id',
        'structure',
        'max_equity',
        'fee',
        'label',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'country_id' => 'integer',
        'max_equity' => 'integer',
        'fee' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    #[Override]
    protected static function booted(): void
    {
        // Bands are evaluated in ascending order of max_equity; nulls (open-ended)
        // must come last. Order by a computed "null-last" key then max_equity.
        self::addGlobalScope('ordered', function ($builder): void {
            $builder->orderByRaw('CASE WHEN max_equity IS NULL THEN 1 ELSE 0 END')
                ->orderBy('max_equity')
                ->orderBy('sort_order');
        });
    }
}
