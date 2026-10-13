<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $business_id
 * @property int $day_of_week
 * @property string|null $open_time
 * @property string|null $close_time
 * @property bool $is_closed
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class BusinessHour extends Model
{
    protected $guarded = [];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function getDayName(): string
    {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        if ($this->day_of_week < 0 || $this->day_of_week > 6) {
            return 'Invalid Day';
        }

        return $days[$this->day_of_week];
    }

    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
        ];
    }
}
