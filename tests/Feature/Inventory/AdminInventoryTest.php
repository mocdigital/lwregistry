<?php

declare(strict_types=1);

use App\Enums\InventoryOrderStatus;
use App\Models\BusinessProduct;
use App\Models\InventoryOrder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

it('shows the Inventory link in the admin sidebar for a super admin', function (): void {
    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.inventory.index'))
        ->assertOk()
        ->assertSee('Inventory')
        ->assertSee(route('admin.inventory.orders.index'));
});

it('forbids a non-admin from the admin inventory page', function (): void {
    actingAs(User::factory()->create())
        ->get(route('admin.inventory.index'))
        ->assertForbidden();
});

it('lets a super admin add a store product with all attributes', function (): void {
    actingAs(User::factory()->superAdmin()->create());

    Volt::test('admin.inventory.index')
        ->set('name', 'Palm Oil 5L')
        ->set('code', 'PALM-5')
        ->set('category', 'Groceries')
        ->set('sku', 'PO-5L')
        ->set('size', '5L')
        ->set('colour', 'Gold')
        ->set('retail_price', 4500)
        ->set('bulk_price', 4000)
        ->set('bulk_min_quantity', 12)
        ->set('stock_quantity', 100)
        ->set('low_stock_threshold', 8)
        ->call('save')
        ->assertHasNoErrors();

    assertDatabaseHas('business_products', [
        'business_id' => null,
        'name' => 'Palm Oil 5L',
        'code' => 'PALM-5',
        'category' => 'Groceries',
        'sku' => 'PO-5L',
        'size' => '5L',
        'colour' => 'Gold',
        'retail_price' => 4500,
        'stock_quantity' => 100,
        'low_stock_threshold' => 8,
    ]);
});

it('bulk-uploads store products from a CSV matching the on-screen columns', function (): void {
    actingAs(User::factory()->superAdmin()->create());

    // Header order = the exact table sequence the admin sees.
    $csv = "Name,Code,Category,Status Price,Description,In Stock,Stock_warn limit,Size,Colour,Sku,Naira price\n"
        . "Rice 50kg,RC,Grains,Active,Long grain,40,10,50kg,White,RC-50,32000\n"
        . "Beans 25kg,BN,Grains,Inactive,Brown beans,25,5,25kg,Brown,BN-25,28000\n";

    Volt::test('admin.inventory.index')
        ->set('csv', UploadedFile::fake()->createWithContent('stock.csv', $csv))
        ->call('importCsv')
        ->assertHasNoErrors();

    expect(BusinessProduct::store()->count())->toBe(2);

    assertDatabaseHas('business_products', [
        'business_id' => null,
        'sku' => 'RC-50',
        'code' => 'RC',
        'category' => 'Grains',
        'size' => '50kg',
        'colour' => 'White',
        'stock_quantity' => 40,
        'low_stock_threshold' => 10,
        'retail_price' => 32000, // Naira price
        'is_active' => true,     // "Active" status
    ]);

    // "Inactive" status → is_active = false
    assertDatabaseHas('business_products', ['sku' => 'BN-25', 'is_active' => false]);
});

it('maps CSV columns by position, ignoring header names', function (): void {
    actingAs(User::factory()->superAdmin()->create());

    // Deliberately wrong/misleading header names — mapping is positional.
    $csv = "col_a,col_b,col_c,col_d,col_e,col_f,col_g,col_h,col_i,col_j,col_k\n"
        . "Garri 10kg,GR,Grains,Active,Fine white,60,15,10kg,Cream,GR-10,15000\n";

    Volt::test('admin.inventory.index')
        ->set('csv', UploadedFile::fake()->createWithContent('stock.csv', $csv))
        ->call('importCsv')
        ->assertHasNoErrors();

    assertDatabaseHas('business_products', [
        'business_id' => null,
        'name' => 'Garri 10kg',
        'code' => 'GR',
        'category' => 'Grains',
        'is_active' => true,
        'description' => 'Fine white',
        'stock_quantity' => 60,
        'low_stock_threshold' => 15,
        'size' => '10kg',
        'colour' => 'Cream',
        'sku' => 'GR-10',
        'retail_price' => 15000,
    ]);
});

it('imports a CSV with no header row (first line is data)', function (): void {
    actingAs(User::factory()->superAdmin()->create());

    // No header at all — first line is a product row.
    $csv = "Yam Tuber,YM,Tubers,Active,Fresh yam,20,4,Large,Brown,YM-1,3000\n";

    Volt::test('admin.inventory.index')
        ->set('csv', UploadedFile::fake()->createWithContent('stock.csv', $csv))
        ->call('importCsv')
        ->assertHasNoErrors();

    assertDatabaseHas('business_products', [
        'name' => 'Yam Tuber',
        'sku' => 'YM-1',
        'stock_quantity' => 20,
        'retail_price' => 3000,
    ]);
    expect(BusinessProduct::store()->count())->toBe(1);
});

it('creates an order and checks it out, decrementing stock', function (): void {
    actingAs(User::factory()->superAdmin()->create());

    $product = BusinessProduct::factory()->withStock(50)->create(['retail_price' => 1000]);

    Volt::test('admin.inventory.orders')
        ->set('price_type', 'retail')
        ->set('customer_name', 'Ada')
        ->set('lines', [['product_id' => $product->id, 'quantity' => 5]])
        ->call('createOrder')
        ->assertHasNoErrors();

    $order = InventoryOrder::first();
    expect($order->status)->toBe(InventoryOrderStatus::PENDING)
        ->and($order->total_amount)->toBe(5000)
        ->and($product->refresh()->stock_quantity)->toBe(50); // not yet reduced

    Volt::test('admin.inventory.orders')
        ->call('markPaid', $order->id)
        ->assertHasNoErrors();

    expect($order->refresh()->status)->toBe(InventoryOrderStatus::PAID)
        ->and($product->refresh()->stock_quantity)->toBe(45);
});

// Build a store order containing a single line item for the given product.
function orderForProduct(BusinessProduct $product, array $attributes = []): InventoryOrder
{
    $order = InventoryOrder::factory()->create($attributes);
    $order->items()->create([
        'business_product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 1,
        'unit_price' => $product->retail_price,
        'line_total' => $product->retail_price,
    ]);
    $order->recalculateTotal();

    return $order->refresh();
}

it('finds orders on the orders page by exact product code', function (): void {
    actingAs(User::factory()->superAdmin()->create());

    $ankara = BusinessProduct::factory()->create(['code' => 'ANK-01', 'name' => 'Ankara']);
    $rice = BusinessProduct::factory()->create(['code' => 'RC-50', 'name' => 'Rice']);

    $ankaraOrder = orderForProduct($ankara);
    $riceOrder = orderForProduct($rice);

    Volt::test('admin.inventory.orders')
        ->set('search', 'ANK-01')
        ->assertSee($ankaraOrder->reference)
        ->assertDontSee($riceOrder->reference);
});

it('matches product code exactly, not partially', function (): void {
    actingAs(User::factory()->superAdmin()->create());

    $product = BusinessProduct::factory()->create(['code' => 'ANK-01', 'name' => 'Ankara']);
    $order = orderForProduct($product);

    // A partial code should not match (exact match only for codes).
    Volt::test('admin.inventory.orders')
        ->set('search', 'ANK')
        ->assertDontSee($order->reference);
});

it('still finds orders by reference and customer name (partial)', function (): void {
    actingAs(User::factory()->superAdmin()->create());

    $product = BusinessProduct::factory()->create(['code' => 'ANK-01']);
    $order = orderForProduct($product, ['customer_name' => 'Adaeze Okafor']);

    Volt::test('admin.inventory.orders')
        ->set('search', 'Adaeze')
        ->assertSee($order->reference);

    Volt::test('admin.inventory.orders')
        ->set('search', mb_substr($order->reference, 0, 6))
        ->assertSee($order->reference);
});
