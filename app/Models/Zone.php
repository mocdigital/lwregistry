<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Override;

/**
 * A Loveworld church zone. Seeded from {@see \App\Support\ChurchZones} and then
 * admin-managed. Each zone has a unique public `code` used to build a referral
 * link (/join/{code}) that pre-selects the zone on the join form and tags the
 * resulting registration to the zone.
 *
 * @property int $id
 * @property string $name
 * @property string|null $slug
 * @property string $code
 * @property string|null $pastor_name
 * @property string|null $pastor_email
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
final class Zone extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'code',
        'pastor_name',
        'pastor_email',
        'is_active',
        'sent_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sent_at' => 'datetime',
    ];

    /**
     * Generate a short, unique, human-readable referral code (e.g. "AB4K2").
     * Uniqueness is checked in a loop, mirroring
     * {@see Sbo::generateMocRegistrationNumber()}.
     */
    public static function generateCode(): string
    {
        do {
            // Uppercase alphanumeric, ambiguous chars removed for readability.
            $code = mb_strtoupper(Str::password(6, true, true, false, false));
            $code = str_replace(['O', '0', 'I', 'L', '1'], ['A', '2', 'X', 'Y', '3'], $code);
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /** SBOs that registered under this zone (linked or self-selected). */
    public function sbos(): HasMany
    {
        return $this->hasMany(Sbo::class);
    }

    /** The public referral URL a pastor shares to drive registrations. */
    public function referralUrl(): string
    {
        return route('join.referral', $this->code);
    }

    #[Override]
    protected static function booted(): void
    {
        self::creating(function (Zone $zone): void {
            if (empty($zone->code)) {
                $zone->code = self::generateCode();
            }

            if (empty($zone->slug) && ! empty($zone->name)) {
                $zone->slug = Str::slug($zone->name);
            }
        });
    }
}
