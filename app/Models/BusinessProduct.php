<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\BusinessProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $business_id
 * @property string $name
 * @property string|null $code
 * @property string|null $category
 * @property string|null $size
 * @property string|null $colour
 * @property string|null $sku
 * @property string|null $description
 * @property int $retail_price
 * @property int $bulk_price
 * @property int $bulk_min_quantity
 * @property int $stock_quantity
 * @property int $low_stock_threshold
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Business|null $business
 */
final class BusinessProduct extends Model
{
    /** @use HasFactory<BusinessProductFactory> */
    use HasFactory;

    protected $guarded = [];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The central admin-owned store: products not tied to any business.
     */
    public function scopeStore($query)
    {
        return $query->whereNull('business_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(InventoryOrderItem::class);
    }

    public function isOutOfStock(): bool
    {
        return $this->stock_quantity <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->low_stock_threshold > 0
            && $this->stock_quantity > 0
            && $this->stock_quantity <= $this->low_stock_threshold;
    }

    /**
     * Unit price for the given order price type ('bulk' or 'retail').
     */
    public function priceFor(string $priceType): int
    {
        return 'bulk' === $priceType ? $this->bulk_price : $this->retail_price;
    }

    protected function casts(): array
    {
        return [
            'retail_price' => 'integer',
            'bulk_price' => 'integer',
            'bulk_min_quantity' => 'integer',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
