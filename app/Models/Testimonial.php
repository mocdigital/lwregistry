<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A won-bid testimonial: the winning business's words about the engagement,
 * with the consent flag that decides whether it is ever shown publicly.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int|null $bid_id
 * @property int $sbo_id
 * @property int $user_id
 * @property int $rating
 * @property string $headline
 * @property string $body
 * @property bool $display_publicly
 */
final class Testimonial extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'bid_id',
        'sbo_id',
        'user_id',
        'rating',
        'headline',
        'body',
        'display_publicly',
    ];

    protected $casts = [
        'rating' => 'integer',
        'display_publicly' => 'boolean',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderBid::class, 'bid_id');
    }

    public function sbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Only those the winner agreed to display on public pages. */
    public function scopePublic(Builder $query): void
    {
        $query->where('display_publicly', true);
    }
}
