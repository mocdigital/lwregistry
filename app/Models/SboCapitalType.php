<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A share-capital type option offered for a share-bearing structure (e.g.
 * "Ordinary Shares" for private companies). Admin-managed on the Fees page.
 *
 * Scoped by country: country_id = null is the global default; a specific
 * country_id overrides it for that country.
 *
 * @property int $id
 * @property int|null $country_id
 * @property string $scope_key
 * @property string $structure
 * @property string $key
 * @property string $label
 * @property int $sort_order
 * @property bool $is_active
 */
final class SboCapitalType extends Model
{
    protected $fillable = [
        'country_id',
        'scope_key',
        'structure',
        'key',
        'label',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'country_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public static function scopeKeyFor(?int $countryId): string
    {
        return null === $countryId ? 'global' : 'country:' . $countryId;
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    #[Override]
    protected static function booted(): void
    {
        self::saving(function (self $type): void {
            $type->scope_key = self::scopeKeyFor($type->country_id);
        });

        self::addGlobalScope('ordered', function ($builder): void {
            $builder->orderBy('sort_order')->orderBy('label');
        });
    }
}
