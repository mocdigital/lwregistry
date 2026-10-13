<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property int $id
 * @property int $country_id
 * @property string $name
 * @property-read Country $country
 */
final class State extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('ordered', function ($builder): void {
            $builder->orderBy('name');
        });
    }
}
