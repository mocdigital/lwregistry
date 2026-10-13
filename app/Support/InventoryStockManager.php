<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\InventoryOrderStatus;
use App\Models\BusinessProduct;
use App\Models\InventoryOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns every mutation of on-hand stock so the "mark as paid → reduce quantity"
 * rule lives in exactly one place. All stock changes run inside a transaction
 * with row-level locks to prevent two concurrent sales from overselling.
 */
final class InventoryStockManager
{
    /**
     * Mark an order as paid and atomically decrement stock for each line item.
     *
     * @throws RuntimeException if the order is not pending, or any line item
     *                          exceeds the available stock.
     */
    public function markPaid(InventoryOrder $order, ?int $paidByUserId = null): InventoryOrder
    {
        return DB::transaction(function () use ($order, $paidByUserId): InventoryOrder {
            // Re-fetch under a lock so status + stock can't shift mid-flight.
            $order = InventoryOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();

            if (InventoryOrderStatus::PENDING !== $order->status) {
                throw new RuntimeException('Only pending orders can be marked as paid.');
            }

            foreach ($order->items as $item) {
                if (null === $item->business_product_id) {
                    // Product was deleted after the order was raised; nothing
                    // to decrement, keep the historical line as-is.
                    continue;
                }

                $product = BusinessProduct::query()
                    ->whereKey($item->business_product_id)
                    ->lockForUpdate()
                    ->first();

                if (null === $product) {
                    continue;
                }

                if ($product->stock_quantity < $item->quantity) {
                    throw new RuntimeException(
                        "Insufficient stock for \"{$product->name}\": "
                        . "{$product->stock_quantity} in stock, {$item->quantity} requested."
                    );
                }

                $product->decrement('stock_quantity', $item->quantity);
            }

            $order->forceFill([
                'status' => InventoryOrderStatus::PAID,
                'paid_by' => $paidByUserId,
                'paid_at' => now(),
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * Cancel a pending order. Paid orders cannot be cancelled here because that
     * would require restocking logic; keep the flow explicit and simple.
     *
     * @throws RuntimeException if the order is not pending.
     */
    public function cancel(InventoryOrder $order): InventoryOrder
    {
        if (InventoryOrderStatus::PENDING !== $order->status) {
            throw new RuntimeException('Only pending orders can be cancelled.');
        }

        $order->forceFill([
            'status' => InventoryOrderStatus::CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        return $order->refresh();
    }

    /**
     * Apply a manual stock adjustment (restock or correction) to a product.
     * A negative delta reduces stock but never below zero.
     */
    public function adjustStock(BusinessProduct $product, int $delta): BusinessProduct
    {
        return DB::transaction(function () use ($product, $delta): BusinessProduct {
            $product = BusinessProduct::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $new = max(0, $product->stock_quantity + $delta);
            $product->forceFill(['stock_quantity' => $new])->save();

            return $product->refresh();
        });
    }
}
