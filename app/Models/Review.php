<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $business_id
 * @property int|null $sbo_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $comment
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The listing being reviewed.
     *
     * `business()` below is the legacy relation — `businesses` holds nothing
     * and no page renders one. Reviews written from the profile attach here.
     */
    public function sbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
