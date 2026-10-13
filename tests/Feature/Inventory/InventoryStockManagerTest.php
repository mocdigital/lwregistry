<?php

declare(strict_types=1);

use App\Enums\InventoryOrderStatus;
use App\Models\BusinessProduct;
use App\Models\InventoryOrder;
use App\Models\InventoryOrderItem;
use App\Support\InventoryStockManager;

// Central-store order (business_id = null) with a single line item.
function makeOrderWithItem(int $stock, int $qty): array
{
    $product = BusinessProduct::factory()->withStock($stock)->create([
        'retail_price' => 100,
    ]);

    $order = InventoryOrder::factory()->create(['price_type' => 'retail']);

    InventoryOrderItem::create([
        'inventory_order_id' => $order->id,
        'business_product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $qty,
        'unit_price' => 100,
        'line_total' => 100 * $qty,
    ]);

    $order->recalculateTotal();

    return [$order->refresh(), $product];
}

it('marks an order paid and decrements product stock', function (): void {
    [$order, $product] = makeOrderWithItem(stock: 50, qty: 10);

    app(InventoryStockManager::class)->markPaid($order);

    expect($order->refresh()->status)->toBe(InventoryOrderStatus::PAID)
        ->and($order->paid_at)->not->toBeNull()
        ->and($product->refresh()->stock_quantity)->toBe(40);
});

it('refuses to oversell and leaves stock untouched', function (): void {
    [$order, $product] = makeOrderWithItem(stock: 5, qty: 10);

    expect(fn () => app(InventoryStockManager::class)->markPaid($order))
        ->toThrow(RuntimeException::class);

    expect($order->refresh()->status)->toBe(InventoryOrderStatus::PENDING)
        ->and($product->refresh()->stock_quantity)->toBe(5);
});

it('will not mark an already-paid order paid again (no double decrement)', function (): void {
    [$order, $product] = makeOrderWithItem(stock: 50, qty: 10);

    app(InventoryStockManager::class)->markPaid($order);
    expect($product->refresh()->stock_quantity)->toBe(40);

    expect(fn () => app(InventoryStockManager::class)->markPaid($order))
        ->toThrow(RuntimeException::class);

    expect($product->refresh()->stock_quantity)->toBe(40);
});

it('decrements every line item across a multi-item order', function (): void {
    $a = BusinessProduct::factory()->withStock(20)->create(['retail_price' => 100]);
    $b = BusinessProduct::factory()->withStock(30)->create(['retail_price' => 200]);

    $order = InventoryOrder::factory()->create(['price_type' => 'retail']);

    foreach ([[$a, 5, 100], [$b, 8, 200]] as [$product, $qty, $unit]) {
        InventoryOrderItem::create([
            'inventory_order_id' => $order->id,
            'business_product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $qty,
            'unit_price' => $unit,
            'line_total' => $unit * $qty,
        ]);
    }
    $order->recalculateTotal();

    app(InventoryStockManager::class)->markPaid($order->refresh());

    expect($a->refresh()->stock_quantity)->toBe(15)
        ->and($b->refresh()->stock_quantity)->toBe(22)
        ->and($order->refresh()->total_amount)->toBe(5 * 100 + 8 * 200);
});

it('cancels a pending order without touching stock', function (): void {
    [$order, $product] = makeOrderWithItem(stock: 50, qty: 10);

    app(InventoryStockManager::class)->cancel($order);

    expect($order->refresh()->status)->toBe(InventoryOrderStatus::CANCELLED)
        ->and($product->refresh()->stock_quantity)->toBe(50);
});
