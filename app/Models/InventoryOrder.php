<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryOrderStatus;
use Carbon\Carbon;
use Database\Factories\InventoryOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $business_id
 * @property int|null $created_by
 * @property string $reference
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_email
 * @property string $price_type
 * @property InventoryOrderStatus $status
 * @property int $total_amount
 * @property string|null $notes
 * @property int|null $paid_by
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Business|null $business
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InventoryOrderItem> $items
 */
final class InventoryOrder extends Model
{
    /** @use HasFactory<InventoryOrderFactory> */
    use HasFactory;

    protected $guarded = [];

    public static function generateReference(): string
    {
        do {
            $reference = 'INV-' . mb_strtoupper(Str::random(8));
        } while (self::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * Convert a Naira amount to its Espees equivalent (espees = naira / rate).
     */
    public static function nairaToEspees(int|float $naira): float
    {
        $rate = (float) config('espees.naira_per_espee', 2050);

        return $rate > 0 ? $naira / $rate : 0.0;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Orders raised against the central admin-owned store (no business).
     */
    public function scopeStore($query)
    {
        return $query->whereNull('business_id');
    }

    /**
     * Orders raised (created) by a specific admin — "their own desk".
     */
    public function scopeCreatedBy($query, int $userId)
    {
        return $query->where('created_by', $userId);
    }

    /**
     * Restrict to what an admin may see: super-admins see everything,
     * everyone else only their own orders.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where('created_by', $user->id);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryOrderItem::class);
    }

    public function isPending(): bool
    {
        return InventoryOrderStatus::PENDING === $this->status;
    }

    public function isPaid(): bool
    {
        return InventoryOrderStatus::PAID === $this->status;
    }

    /**
     * Recompute and persist total_amount from current line items.
     */
    public function recalculateTotal(): void
    {
        $this->total_amount = (int) $this->items()->sum('line_total');
        $this->save();
    }

    /**
     * This order's total expressed in Espees.
     */
    public function totalInEspees(): float
    {
        return self::nairaToEspees($this->total_amount);
    }

    protected static function booted(): void
    {
        self::creating(function (InventoryOrder $order): void {
            if (empty($order->reference)) {
                $order->reference = self::generateReference();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => InventoryOrderStatus::class,
            'total_amount' => 'integer',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
