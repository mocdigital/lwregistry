<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One view of an SBO's public profile.
 *
 * Rows rather than a counter, so the analytics page can show a trend. See the
 * create_sbo_views migration.
 */
final class SboView extends Model
{
    public $timestamps = false;

    protected $fillable = ['sbo_id', 'viewer_id', 'viewer_hash', 'viewed_at'];

    protected $casts = ['viewed_at' => 'datetime'];

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function sbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class);
    }
}
