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
 * @property string|null $description
 * @property string|null $discount_text
 * @property Carbon|null $valid_until
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class BusinessPromotion extends Model
{
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
        ];
    }
}
