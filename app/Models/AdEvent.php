<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AdEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ad_placement_id',
        'type',
        'ip_hash',
        'user_agent',
        'created_at',
    ];

    public function adPlacement(): BelongsTo
    {
        return $this->belongsTo(AdPlacement::class);
    }
}
