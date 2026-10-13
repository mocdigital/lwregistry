<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $inventory_order_id
 * @property int|null $business_product_id
 * @property string $product_name
 * @property int $quantity
 * @property int $unit_price
 * @property int $line_total
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read InventoryOrder $order
 * @property-read BusinessProduct|null $product
 */
final class InventoryOrderItem extends Model
{
    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(InventoryOrder::class, 'inventory_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(BusinessProduct::class, 'business_product_id');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'line_total' => 'integer',
        ];
    }
}
