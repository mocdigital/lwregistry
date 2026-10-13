<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

function procurementRequestManager(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

function procurementRequestSbo(User $manager, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Procurement SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $manager->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('only fully verified sbos can post procurement requests', function (): void {
    $manager = procurementRequestManager();
    $category = Category::factory()->create();
    $unpaidSbo = procurementRequestSbo($manager, [
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
    ]);

    Livewire::actingAs($manager)
        ->test('sbo.rfqs.index')
        ->set('sbo_id', $unpaidSbo->id)
        ->set('category_id', $category->id)
        ->set('request_type', 'product')
        ->set('title', 'Projector Supply')
        ->set('description', 'Supply projectors for ministry events.')
        ->set('quantity', '10 units')
        ->set('delivery_location', 'Lagos Zone 1')
        ->set('order_deadline', now()->addDays(7)->toDateString())
        ->call('createRfq')
        ->assertHasErrors(['sbo_id']);

    expect(PurchaseOrder::count())->toBe(0);
});

test('unpaid sbo managers are redirected from procurement requests to complete payment', function (): void {
    $manager = procurementRequestManager();
    procurementRequestSbo($manager, [
        'name' => 'Unpaid Procurement Gate SBO',
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
    ]);

    $this->actingAs($manager)
        ->get(route('sbo.rfqs.index'))
        ->assertRedirect(route('sbo.payment-required'));
});

test('paid but not fully validated sbo managers cannot open procurement requests', function (): void {
    $manager = procurementRequestManager();
    procurementRequestSbo($manager, [
        'name' => 'Paid Pending Documents SBO',
        'verification_status' => 'approved',
        'documents_status' => 'pending',
        'documents_compliant_at' => null,
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    // The verification gate shows the designed wall now rather than a bare 403;
    // it is the same "not through onboarding yet" state the wall explains.
    $this->actingAs($manager)
        ->get(route('sbo.rfqs.index'))
        ->assertRedirect(route('sbo.payment-required'));
});

test('procurement requests unlock when any managed sbo is fully validated', function (): void {
    $manager = procurementRequestManager();
    procurementRequestSbo($manager, [
        'name' => 'Unpaid Procurement SBO',
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
    ]);
    procurementRequestSbo($manager, ['name' => 'Paid Procurement SBO']);

    $response = $this->actingAs($manager)
        ->get(route('sbo.rfqs.index'))
        ->assertOk();

    // One paid SBO is enough to unlock the screen, even while another of the
    // owner's applications is still outstanding.
    $response->assertInertia(fn ($page) => $page
        ->component('procurement/ProcurementListView')
        ->where('scope', 'mine')
        ->has('requests.data'));
});

test('product procurement request saves product-specific fields', function (): void {
    $manager = procurementRequestManager();
    $category = Category::factory()->create();
    $sbo = procurementRequestSbo($manager);

    Livewire::actingAs($manager)
        ->test('sbo.rfqs.index')
        ->set('sbo_id', $sbo->id)
        ->set('category_id', $category->id)
        ->set('request_type', 'product')
        ->set('title', 'LED Screen Supply')
        ->set('description', 'Supply LED screens for a conference.')
        ->set('quantity', '4 units')
        ->set('unit_specification', 'Outdoor P3 LED screen')
        ->set('delivery_location', 'Loveworld Convocation Arena')
        ->set('order_deadline', now()->addDays(10)->toDateString())
        ->call('createRfq')
        ->assertHasNoErrors();

    $request = PurchaseOrder::firstOrFail();

    expect($request->request_type)->toBe('product');
    expect($request->quantity)->toBe('4 units');
    expect($request->unit_specification)->toBe('Outdoor P3 LED screen');
    expect($request->delivery_location)->toBe('Loveworld Convocation Arena');
    expect($request->service_scope)->toBeNull();
});

test('service procurement request saves service-specific fields', function (): void {
    $manager = procurementRequestManager();
    $category = Category::factory()->create();
    $sbo = procurementRequestSbo($manager);

    Livewire::actingAs($manager)
        ->test('sbo.rfqs.index')
        ->set('sbo_id', $sbo->id)
        ->set('category_id', $category->id)
        ->set('request_type', 'service')
        ->set('title', 'Event Logistics Support')
        ->set('description', 'Provide logistics support for a ministry event.')
        ->set('service_scope', 'Coordinate transport, loading, setup, and breakdown.')
        ->set('service_duration', '3 days')
        ->set('required_expertise', 'Event logistics')
        ->set('order_deadline', now()->addDays(10)->toDateString())
        ->call('createRfq')
        ->assertHasNoErrors();

    $request = PurchaseOrder::firstOrFail();

    expect($request->request_type)->toBe('service');
    expect($request->quantity)->toBeNull();
    expect($request->delivery_location)->toBeNull();
    expect($request->service_scope)->toBe('Coordinate transport, loading, setup, and breakdown.');
    expect($request->service_duration)->toBe('3 days');
    expect($request->required_expertise)->toBe('Event logistics');
});
