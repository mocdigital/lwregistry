<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Key/value store for the tunable, non-banded fee settings: flat_fee,
 * currency, merchant_name, merchant_app. Admin-managed on the Fees page.
 *
 * Scoped by country: country_id = null is the global default; a specific
 * country_id overrides it for that country. Reads resolve country-specific
 * first, then fall back to global.
 *
 * @property int $id
 * @property int|null $country_id
 * @property string $scope_key
 * @property string $key
 * @property string|null $value
 */
final class SboFeeSetting extends Model
{
    protected $fillable = [
        'country_id',
        'scope_key',
        'key',
        'value',
    ];

    /**
     * Read a setting value. When $countryId is given, a country-specific value
     * takes precedence over the global default; falls back to $default if
     * neither exists.
     */
    public static function get(string $key, ?string $default = null, ?int $countryId = null): ?string
    {
        if (null !== $countryId) {
            $scoped = self::query()
                ->where('country_id', $countryId)
                ->where('key', $key)
                ->value('value');

            if (null !== $scoped) {
                return $scoped;
            }
        }

        return self::query()
            ->whereNull('country_id')
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    /** Create or update a setting for the given country scope (null = global). */
    public static function set(string $key, ?string $value, ?int $countryId = null): void
    {
        self::query()->updateOrCreate(
            ['country_id' => $countryId, 'key' => $key],
            ['value' => $value],
        );
    }

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
        self::saving(function (self $setting): void {
            $setting->scope_key = self::scopeKeyFor($setting->country_id);
        });
    }
}
