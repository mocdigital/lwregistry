<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A ministry an SBO is affiliated with, chosen at registration.
 *
 * A plain admin-managed reference list — the registration wizard shows the
 * active ones as a dropdown (only when the entity type is SBO), and the choice
 * is stored on the Sbo as a name string, the same shape as its category.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Ministry extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    /** Active ministry names, alphabetically — what registration offers. */
    public static function activeNames(): \Illuminate\Support\Collection
    {
        return self::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->values();
    }

    /** A unique slug for a ministry name, so two "Healing School"s cannot clash. */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'ministry';
        $slug = $base;
        $n = 1;

        while (self::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
