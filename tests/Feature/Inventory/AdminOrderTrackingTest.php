<?php

declare(strict_types=1);

use App\Enums\InventoryOrderStatus;
use App\Enums\UserRole;
use App\Models\BusinessProduct;
use App\Models\InventoryOrder;
use App\Models\User;
use App\Notifications\InventoryPaymentReceivedNotification;
use App\Support\AdminPages;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;

use function Pest\Laravel\actingAs;

// A regular admin who has been granted access to the store/inventory pages.
function admin(): User
{
    return User::factory()->create([
        'role' => UserRole::ADMIN,
        'admin_page_permissions' => [
            AdminPages::INVENTORY,
            AdminPages::INVENTORY_ORDERS,
            AdminPages::INVENTORY_ORDER_DETAILS,
        ],
    ]);
}

function superAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

function storeOrderBy(User $creator, array $attributes = []): InventoryOrder
{
    return InventoryOrder::factory()->create(array_merge([
        'created_by' => $creator->id,
    ], $attributes));
}

/* ---------------------------------------------------------------------------
 | 1. Admin-specific checkouts / per-admin scoping on the orders page
 * ------------------------------------------------------------------------- */

it('shows a regular admin only their own orders on the orders page', function (): void {
    $me = admin();
    $other = admin();

    $mine = storeOrderBy($me, ['customer_name' => 'My Customer']);
    $theirs = storeOrderBy($other, ['customer_name' => 'Their Customer']);

    actingAs($me);

    Volt::test('admin.inventory.orders')
        ->assertSee($mine->reference)
        ->assertDontSee($theirs->reference);
});

it('lets a super admin see every admin order on the orders page', function (): void {
    $a = admin();
    $b = admin();
    $orderA = storeOrderBy($a);
    $orderB = storeOrderBy($b);

    actingAs(superAdmin());

    Volt::test('admin.inventory.orders')
        ->assertSee($orderA->reference)
        ->assertSee($orderB->reference);
});

it('records paid_by as the admin who checks the order out', function (): void {
    $me = admin();
    $product = BusinessProduct::factory()->withStock(10)->create(['retail_price' => 100]);
    $order = storeOrderBy($me);
    $order->items()->create([
        'business_product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 2,
        'unit_price' => 100,
        'line_total' => 200,
    ]);
    $order->recalculateTotal();

    actingAs($me);
    Volt::test('admin.inventory.orders')->call('markPaid', $order->id)->assertHasNoErrors();

    expect($order->refresh()->status)->toBe(InventoryOrderStatus::PAID)
        ->and($order->paid_by)->toBe($me->id)
        ->and($product->refresh()->stock_quantity)->toBe(8);
});

it('forbids a regular admin from checking out another admin order', function (): void {
    $me = admin();
    $other = admin();
    $product = BusinessProduct::factory()->withStock(10)->create(['retail_price' => 100]);
    $order = storeOrderBy($other);
    $order->items()->create([
        'business_product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
    ]);

    actingAs($me);
    // Scoped findOrFail → the other admin's order is not reachable.
    expect(fn () => Volt::test('admin.inventory.orders')->call('markPaid', $order->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect($order->refresh()->status)->toBe(InventoryOrderStatus::PENDING);
});

/* ---------------------------------------------------------------------------
 | 2. CSV export on the inventory products page
 * ------------------------------------------------------------------------- */

function captureCsv(Symfony\Component\HttpFoundation\StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

it('exports the inventory products as CSV in column order', function (): void {
    actingAs(superAdmin());
    BusinessProduct::factory()->create([
        'name' => 'Ankara', 'code' => 'ANK-01', 'category' => 'Textiles',
        'sku' => 'ANK-SKU', 'size' => '6y', 'colour' => 'Red',
        'retail_price' => 7500, 'stock_quantity' => 30, 'low_stock_threshold' => 5,
        'is_active' => true,
    ]);

    $response = app(App\Support\InventoryCsvExporter::class)->products(BusinessProduct::store());
    $csv = captureCsv($response);

    // Header carries the on-screen columns (fputcsv quotes cells containing spaces).
    expect($csv)->toContain('Name,Code,Category,"Status Price",Description,"In Stock","Stock_warn limit",Size,Colour,Sku,"Naira price"')
        ->and($csv)->toContain('Ankara,ANK-01,Textiles,Active')
        ->and($csv)->toContain('7500');
});

/* ---------------------------------------------------------------------------
 | 3. Out-of-stock warning + inline restock in the New Order modal
 * ------------------------------------------------------------------------- */

it('flags an out-of-stock selected product in the order preview', function (): void {
    actingAs(admin());
    $product = BusinessProduct::factory()->outOfStock()->create();

    Volt::test('admin.inventory.orders')
        ->set('lines', [['product_id' => $product->id, 'quantity' => 1]])
        ->assertSee('is out of stock')
        ->assertSee('Add stock');
});

it('adds stock inline via restock so the item can be ordered', function (): void {
    actingAs(admin());
    $product = BusinessProduct::factory()->outOfStock()->create();

    Volt::test('admin.inventory.orders')
        ->set('lines', [['product_id' => $product->id, 'quantity' => 1, 'restock' => 12]])
        ->call('restock', 0)
        ->assertHasNoErrors();

    expect($product->refresh()->stock_quantity)->toBe(12);
});

/* ---------------------------------------------------------------------------
 | 4. Order Details page
 * ------------------------------------------------------------------------- */

it('order details page shows a regular admin only their own orders', function (): void {
    $me = admin();
    $other = admin();
    $mine = storeOrderBy($me);
    $theirs = storeOrderBy($other);

    actingAs($me)
        ->get(route('admin.inventory.order-details.index'))
        ->assertOk()
        ->assertSee($mine->reference)
        ->assertDontSee($theirs->reference);
});

it('order details page lets a super admin filter by a specific admin', function (): void {
    $a = admin();
    $b = admin();
    $orderA = storeOrderBy($a);
    $orderB = storeOrderBy($b);

    actingAs(superAdmin());

    Volt::test('admin.inventory.order-details')
        ->assertSee($orderA->reference)
        ->assertSee($orderB->reference)
        ->set('adminFilter', $a->id)
        ->assertSee($orderA->reference)
        ->assertDontSee($orderB->reference);
});

it('order details CSV export is scoped to the acting admin', function (): void {
    $me = admin();
    $other = admin();
    $mine = storeOrderBy($me, ['customer_name' => 'Mine Cust']);
    $theirs = storeOrderBy($other, ['customer_name' => 'Theirs Cust']);

    actingAs($me);
    // Export uses the same scoped query as the page: only the acting admin's orders.
    $response = app(App\Support\InventoryCsvExporter::class)
        ->orders(InventoryOrder::store()->visibleTo($me->fresh()));
    $csv = captureCsv($response);

    expect($csv)->toContain($mine->reference)
        ->and($csv)->not->toContain($theirs->reference);
});

it('order details page is forbidden for non-admins', function (): void {
    actingAs(User::factory()->create())
        ->get(route('admin.inventory.order-details.index'))
        ->assertForbidden();
});

/* ---------------------------------------------------------------------------
 | Espees conversion + print receipt after checkout
 * ------------------------------------------------------------------------- */

it('converts a Naira amount to Espees at the configured rate', function (): void {
    config()->set('espees.naira_per_espee', 2050);

    // 20,500 Naira / 2050 = 10 Espees
    expect(InventoryOrder::nairaToEspees(20500))->toBe(10.0);
    // 10,250 / 2050 = 5 Espees
    expect(InventoryOrder::nairaToEspees(10250))->toBe(5.0);
});

it('reports an order total in Espees', function (): void {
    config()->set('espees.naira_per_espee', 2050);
    $order = InventoryOrder::factory()->create(['total_amount' => 4100]);

    expect($order->totalInEspees())->toBe(2.0); // 4100 / 2050
});

it('offers a print receipt for the order after checkout', function (): void {
    $me = admin();
    $product = BusinessProduct::factory()->withStock(10)->create(['retail_price' => 1000]);
    $order = storeOrderBy($me, ['customer_name' => 'Walk-in']);
    $order->items()->create([
        'business_product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 3, 'unit_price' => 1000, 'line_total' => 3000,
    ]);
    $order->recalculateTotal();

    actingAs($me);

    $component = Volt::test('admin.inventory.orders')
        ->call('markPaid', $order->id)
        ->assertHasNoErrors();

    // The just-paid order is set as the receipt target and rendered.
    expect($component->get('receiptOrderId'))->toBe($order->id);
    $component->assertSee('Print receipt')
        ->assertSee($order->reference)
        ->assertSee('Sales Receipt');

    // Dismissing clears it.
    $component->call('clearReceipt');
    expect($component->get('receiptOrderId'))->toBeNull();
});

/* ---------------------------------------------------------------------------
 | Customer "Payment Received" email on checkout
 * ------------------------------------------------------------------------- */

function orderReadyToCheckout(User $creator, array $attributes = []): InventoryOrder
{
    $product = BusinessProduct::factory()->withStock(10)->create(['retail_price' => 1000]);
    $order = storeOrderBy($creator, $attributes);
    $order->items()->create([
        'business_product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 2, 'unit_price' => 1000, 'line_total' => 2000,
    ]);
    $order->recalculateTotal();

    return $order->refresh();
}

it('emails the customer a payment-received notice on checkout when an email is saved', function (): void {
    Notification::fake();
    $me = admin();
    $order = orderReadyToCheckout($me, [
        'customer_name' => 'Adaeze Okafor',
        'customer_email' => 'adaeze@example.com',
    ]);

    actingAs($me);
    Volt::test('admin.inventory.orders')->call('markPaid', $order->id)->assertHasNoErrors();

    Notification::assertSentOnDemand(
        InventoryPaymentReceivedNotification::class,
        fn ($notification, $channels, $notifiable) => in_array('mail', $channels, true)
                && 'adaeze@example.com' === $notifiable->routes['mail']
    );
});

it('does not email when the order has no customer email', function (): void {
    Notification::fake();
    $me = admin();
    $order = orderReadyToCheckout($me, ['customer_name' => 'Walk-in', 'customer_email' => null]);

    actingAs($me);
    Volt::test('admin.inventory.orders')->call('markPaid', $order->id)->assertHasNoErrors();

    Notification::assertNothingSent();
});

it('does not email when the saved email is invalid', function (): void {
    Notification::fake();
    $me = admin();
    $order = orderReadyToCheckout($me, ['customer_email' => 'not-an-email']);

    actingAs($me);
    Volt::test('admin.inventory.orders')->call('markPaid', $order->id)->assertHasNoErrors();

    Notification::assertNothingSent();
});

it('personalises the email greeting and thanks with the customer first name', function (): void {
    $order = orderReadyToCheckout(admin(), [
        'customer_name' => 'Adaeze Okafor',
        'customer_email' => 'adaeze@example.com',
        'paid_at' => now(),
    ]);

    $mail = (new InventoryPaymentReceivedNotification($order))
        ->toMail(new AnonymousNotifiable());

    expect($mail->greeting)->toBe('Hello Adaeze,');
    expect(implode(' ', $mail->introLines))->toContain('Thank you once again, Adaeze');
    // Transaction details present.
    expect(implode(' ', $mail->introLines))
        ->toContain($order->reference)
        ->toContain('Total paid:')
        ->toContain('Espees equivalent:');
});

it('falls back to a generic greeting when no name is provided', function (): void {
    $order = orderReadyToCheckout(admin(), [
        'customer_name' => null,
        'customer_email' => 'nobody@example.com',
    ]);

    $mail = (new InventoryPaymentReceivedNotification($order))
        ->toMail(new AnonymousNotifiable());

    expect($mail->greeting)->toBe('Hello,');
});
