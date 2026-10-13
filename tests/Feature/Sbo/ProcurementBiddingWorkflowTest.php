<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\SubmitBidModal;
use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\Sbo;
use App\Models\User;
use App\Notifications\BidStatusNotification;
use App\Notifications\NewProcurementRequestNotification;
use App\Support\AdminCapabilities;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

function procurementWorkflowUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::USER,
        'email_verified_at' => now(),
    ], $overrides));
}

function procurementWorkflowAdmin(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
        'permissions' => [
            AdminCapabilities::MANAGE_RFQS,
            AdminCapabilities::AWARD_BIDS,
        ],
    ], $overrides));
}

function procurementWorkflowSbo(User $manager, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Procurement Workflow SBO ' . Str::random(6);

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

test('admin can create a rich procurement request and eligible vendors are notified', function (): void {
    Notification::fake();

    $admin = procurementWorkflowAdmin();
    $category = Category::factory()->create();
    $sbo = procurementWorkflowSbo($admin);
    $eligibleVendor = procurementWorkflowUser(['role' => UserRole::SBO_MANAGER]);
    $ineligibleVendor = procurementWorkflowUser(['role' => UserRole::SBO_MANAGER]);

    procurementWorkflowSbo($eligibleVendor, ['name' => 'Eligible Vendor SBO']);
    procurementWorkflowSbo($ineligibleVendor, [
        'name' => 'Ineligible Vendor SBO',
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
    ]);

    Livewire::actingAs($admin)
        ->test('admin.purchase-orders.index')
        ->set('sbo_id', $sbo->id)
        ->set('category_id', $category->id)
        ->set('request_type', 'product_service')
        ->set('title', 'Media equipment supply and installation')
        ->set('description', 'Supply and install media equipment for a conference.')
        ->set('budget', '500000')
        ->set('quantity', '10 units')
        ->set('delivery_location', 'Lagos Zone 1')
        ->set('service_scope', 'Install, test, and hand over all equipment.')
        ->set('order_deadline', now()->addDays(10)->toDateString())
        ->call('createRfq')
        ->assertHasNoErrors();

    $request = PurchaseOrder::firstOrFail();

    expect($request->request_type)->toBe('product_service');
    expect($request->quantity)->toBe('10 units');
    expect($request->delivery_location)->toBe('Lagos Zone 1');
    expect($request->service_scope)->toBe('Install, test, and hand over all equipment.');

    Notification::assertSentTo($eligibleVendor, NewProcurementRequestNotification::class);
    Notification::assertNotSentTo($ineligibleVendor, NewProcurementRequestNotification::class);
});

test('vendor cannot bid without a verified sbo or against its own request', function (): void {
    Storage::fake('public');

    $vendor = procurementWorkflowUser(['role' => UserRole::SBO_MANAGER]);
    $category = Category::factory()->create();
    $sbo = procurementWorkflowSbo(procurementWorkflowAdmin());
    $request = PurchaseOrder::create([
        'sbo_id' => $sbo->id,
        'category_id' => $category->id,
        'request_type' => 'product',
        'title' => 'LED screen supply',
        'description' => 'Supply LED screens.',
        'order_deadline' => now()->addDays(5)->toDateString(),
        'status' => 'open',
    ]);

    Livewire::actingAs($vendor)
        ->test(SubmitBidModal::class)
        ->call('open', $request->id)
        ->assertForbidden();

    $bidderSbo = procurementWorkflowSbo($vendor, ['name' => 'Own Request Bidder SBO']);
    $ownRequest = PurchaseOrder::create([
        'sbo_id' => $bidderSbo->id,
        'category_id' => $category->id,
        'request_type' => 'product',
        'title' => 'Own request',
        'description' => 'A company cannot bid on its own request.',
        'order_deadline' => now()->addDays(5)->toDateString(),
        'status' => 'open',
    ]);

    Livewire::actingAs($vendor)
        ->test(SubmitBidModal::class)
        ->call('open', $ownRequest->id)
        ->set('bidder_sbo_id', $bidderSbo->id)
        ->set('proposal', 'We can supply the requested LED screens within the required timeline.')
        ->set('attachment', UploadedFile::fake()->create('proposal.pdf', 200, 'application/pdf'))
        ->call('save')
        ->assertHasErrors(['bidder_sbo_id']);

    expect(PurchaseOrderBid::count())->toBe(0);
});

test('unpaid sbo managers cannot directly submit bids through the modal', function (): void {
    Storage::fake('public');

    $vendor = procurementWorkflowUser(['role' => UserRole::SBO_MANAGER]);
    procurementWorkflowSbo($vendor, [
        'name' => 'Unpaid Bidder SBO',
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
    ]);
    $category = Category::factory()->create();
    $sbo = procurementWorkflowSbo(procurementWorkflowAdmin());
    $request = PurchaseOrder::create([
        'sbo_id' => $sbo->id,
        'category_id' => $category->id,
        'request_type' => 'product',
        'title' => 'Locked bid request',
        'description' => 'This request should not accept bids from unpaid managers.',
        'order_deadline' => now()->addDays(5)->toDateString(),
        'status' => 'open',
    ]);

    Livewire::actingAs($vendor)
        ->test(SubmitBidModal::class)
        ->call('open', $request->id)
        ->assertForbidden();

    expect(PurchaseOrderBid::count())->toBe(0);
});

test('vendor cannot bid after a procurement request deadline has passed', function (): void {
    Storage::fake('public');

    $vendor = procurementWorkflowUser();
    $category = Category::factory()->create();
    $sbo = procurementWorkflowSbo(procurementWorkflowAdmin());
    $request = PurchaseOrder::create([
        'sbo_id' => $sbo->id,
        'category_id' => $category->id,
        'request_type' => 'product',
        'title' => 'Expired request',
        'description' => 'This should not accept bids.',
        'order_deadline' => now()->subDay()->toDateString(),
        'status' => 'open',
    ]);
    $bidderSbo = procurementWorkflowSbo($vendor, ['name' => 'Expired Request Bidder SBO']);

    Livewire::actingAs($vendor)
        ->test(SubmitBidModal::class)
        ->call('open', $request->id)
        ->set('bidder_sbo_id', $bidderSbo->id)
        ->set('proposal', 'We can handle this request even though the deadline has passed.')
        ->set('attachment', UploadedFile::fake()->create('proposal.pdf', 200, 'application/pdf'))
        ->call('save')
        ->assertHasErrors(['bidder_sbo_id']);

    expect(PurchaseOrderBid::count())->toBe(0);
});

test('admin award accepts one bid rejects the rest and notifies bidders', function (): void {
    Notification::fake();

    $admin = procurementWorkflowAdmin();
    $category = Category::factory()->create();
    $sbo = procurementWorkflowSbo($admin);
    $request = PurchaseOrder::create([
        'sbo_id' => $sbo->id,
        'category_id' => $category->id,
        'request_type' => 'service',
        'title' => 'Event logistics',
        'description' => 'Provide event logistics support.',
        'service_scope' => 'Transport and setup support.',
        'order_deadline' => now()->addDays(7)->toDateString(),
        'status' => 'open',
    ]);
    $winner = procurementWorkflowUser(['email' => 'winner@example.test', 'role' => UserRole::SBO_MANAGER]);
    $runnerUp = procurementWorkflowUser(['email' => 'runner-up@example.test', 'role' => UserRole::SBO_MANAGER]);
    $winningSbo = procurementWorkflowSbo($winner, ['name' => 'Winning Bidder SBO']);
    $runnerUpSbo = procurementWorkflowSbo($runnerUp, ['name' => 'Runner Up Bidder SBO']);
    $winningBid = PurchaseOrderBid::create([
        'purchase_order_id' => $request->id,
        'user_id' => $winner->id,
        'bidder_sbo_id' => $winningSbo->id,
        'proposal' => 'Winning proposal',
        'status' => 'pending',
        'submitted_at' => now(),
    ]);
    $runnerUpBid = PurchaseOrderBid::create([
        'purchase_order_id' => $request->id,
        'user_id' => $runnerUp->id,
        'bidder_sbo_id' => $runnerUpSbo->id,
        'proposal' => 'Runner up proposal',
        'status' => 'pending',
        'submitted_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test('admin.purchase-orders.show', ['purchaseOrder' => $request])
        ->set('award_note', 'Best technical response and availability.')
        ->call('awardBid', $winningBid->id)
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe('awarded');
    expect($request->fresh()->awarded_bid_id)->toBe($winningBid->id);
    expect($request->fresh()->award_note)->toBe('Best technical response and availability.');
    expect($winningBid->fresh()->status)->toBe('accepted');
    expect($runnerUpBid->fresh()->status)->toBe('rejected');

    Notification::assertSentTo($winner, BidStatusNotification::class);
    Notification::assertSentTo($runnerUp, BidStatusNotification::class);
});
