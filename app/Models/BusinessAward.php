<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $business_id
 * @property string $title
 * @property int|null $year
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class BusinessAward extends Model
{
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
