<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property int $id
 * @property string $name
 * @property string|null $iso2 ISO 3166-1 alpha-2 — key for country-scoped config
 */
final class Country extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function sbos(): HasMany
    {
        return $this->hasMany(Sbo::class);
    }

    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }

    public function complianceDocumentTypes(): HasMany
    {
        return $this->hasMany(ComplianceDocumentType::class);
    }

    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('ordered', function ($builder): void {
            $builder->orderBy('name');
        });
    }
}
