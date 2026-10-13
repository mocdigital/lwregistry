<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Countries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One place a business trades from.
 *
 * The first is the primary — it mirrors the address columns on `sbos`, which
 * registration writes and every existing country filter reads. Keeping the two
 * in step is this model's job: an owner who edits their head office here should
 * not find the directory still filtering them into the old country.
 */
final class SboAddress extends Model
{
    protected $fillable = [
        'sbo_id',
        'label',
        'address',
        'city',
        'state',
        'country',
        'country_code',
        'latitude',
        'longitude',
        'is_primary',
        'position',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'position' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function sbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class);
    }

    /** "12 Adeola Odeku Street, Lagos, Lagos State, Nigeria" */
    public function line(): string
    {
        return mb_trim(implode(', ', array_filter([
            $this->address,
            $this->city,
            $this->state,
            $this->country,
        ])));
    }

    protected static function booted(): void
    {
        /*
         * The code is derived, never posted. A client that could set it could
         * put a Nigerian flag on a London office.
         */
        self::saving(function (self $address): void {
            $address->country_code = Countries::codeFor($address->country);
        });
    }
}
