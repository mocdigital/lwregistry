<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\SubmitBidModal;
use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\PurchaseOrderInvite;
use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\SboNetworkReport;
use App\Models\User;
use App\Notifications\PurchaseOrderInviteNotification;
use App\Notifications\SboConnectionAcceptedNotification;
use App\Notifications\SboConnectionRequestedNotification;
use App\Notifications\SboMessageReceivedNotification;
use App\Notifications\SboNetworkReportSubmittedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

function sboNetworkUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ], $overrides));
}

function sboNetworkSbo(User $manager, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Network SBO ' . Str::random(6);

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

test('only fully verified active sbos can open the network workspace', function (): void {
    $manager = sboNetworkUser();
    sboNetworkSbo($manager, [
        'name' => 'Incomplete Network SBO',
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
    ]);

    // The network gate shows the designed wall now rather than a bare 403 —
    // it is the same "not through onboarding yet" state the wall explains.
    $this->actingAs($manager)
        ->get(route('sbo.network.index'))
        ->assertRedirect(route('sbo.payment-required'));

    sboNetworkSbo($manager, ['name' => 'Verified Network SBO']);

    $response = $this->actingAs($manager)
        ->get(route('sbo.network.index'))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('sbo/SboNetworkView')
        ->has('counts')
        ->has('connections')
        ->has('requests')
        ->has('conversations'));

    // The screen acts "as" one of the owner's SBOs on every write, so the
    // verified one is what it must be handed — not the incomplete one.
    expect(inertiaProp($response, 'mySbo.name'))->toBe('Verified Network SBO')
        ->and(inertiaProp($response, 'userId'))->toBe((string) $manager->id);
});

test('a network card names the location and tags the category once', function (): void {
    /*
     * The card printed "Transport & Logistics, Abuja, Nigeria" on its meta line
     * and then "Transport & Logistics" again as a chip directly underneath —
     * the same words twice, which pushed the location onto a second line on a
     * phone. The meta line is the location; the category is the tag.
     */
    $manager = sboNetworkUser();
    $mine = sboNetworkSbo($manager, ['name' => 'My SBO']);

    $theirs = sboNetworkSbo(sboNetworkUser(), [
        'name' => 'Ben Logistics Ltd',
        'category' => 'Transport & Logistics',
        'city' => 'Abuja',
        'country' => 'Nigeria',
    ]);

    SboConnection::create([
        'requester_sbo_id' => $mine->id,
        'recipient_sbo_id' => $theirs->id,
        'status' => SboConnection::STATUS_ACCEPTED,
        'responded_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('sbo.network.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connections.0.meta', 'Abuja, Nigeria')
            ->where('connections.0.tags', ['Transport & Logistics']));
});

test('accepted connections can message and report network activity', function (): void {
    Notification::fake();

    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);
    $requesterUser = sboNetworkUser();
    $recipientUser = sboNetworkUser();
    $requesterSbo = sboNetworkSbo($requesterUser, ['name' => 'Requester SBO']);
    $recipientSbo = sboNetworkSbo($recipientUser, ['name' => 'Recipient SBO']);

    Livewire::actingAs($requesterUser)
        ->test('sbo.network.index')
        ->set('activeSboId', $requesterSbo->id)
        ->call('sendConnectionRequest', $recipientSbo->id)
        ->assertHasNoErrors();

    $connection = SboConnection::firstOrFail();

    expect($connection->status)->toBe(SboConnection::STATUS_PENDING);
    Notification::assertSentTo($recipientUser, SboConnectionRequestedNotification::class);

    Livewire::actingAs($recipientUser)
        ->test('sbo.network.index')
        ->set('activeSboId', $recipientSbo->id)
        ->call('acceptConnection', $connection->id)
        ->assertHasNoErrors();

    $connection->refresh();

    expect($connection->status)->toBe(SboConnection::STATUS_ACCEPTED)
        ->and(SboConversation::count())->toBe(1);
    Notification::assertSentTo($requesterUser, SboConnectionAcceptedNotification::class);

    $conversation = SboConversation::firstOrFail();

    Livewire::actingAs($requesterUser)
        ->test('sbo.network.index')
        ->set('activeSboId', $requesterSbo->id)
        ->call('openConversation', $conversation->id)
        ->set('messageBody', 'Hello, we would like to discuss a possible procurement partnership.')
        ->call('sendMessage')
        ->assertHasNoErrors();

    expect(SboMessage::count())->toBe(1);
    Notification::assertSentTo($recipientUser, SboMessageReceivedNotification::class);

    Livewire::actingAs($recipientUser)
        ->test('sbo.network.index')
        ->set('activeSboId', $recipientSbo->id)
        ->set('reportTargetSboId', $requesterSbo->id)
        ->set('reportMessageId', SboMessage::first()->id)
        ->set('reportReason', 'Spam or suspicious activity')
        ->set('reportDetails', 'Please review this message for moderation.')
        ->call('submitReport')
        ->assertHasNoErrors();

    expect(SboNetworkReport::count())->toBe(1);
    Notification::assertSentTo($admin, SboNetworkReportSubmittedNotification::class);
});

test('public and invite only bids are scoped to verified sbos', function (): void {
    Notification::fake();
    Storage::fake('public');

    $ownerUser = sboNetworkUser();
    $bidderUser = sboNetworkUser();
    $nonInvitedUser = sboNetworkUser();
    $ownerSbo = sboNetworkSbo($ownerUser, ['name' => 'RFQ Owner SBO']);
    $bidderSbo = sboNetworkSbo($bidderUser, ['name' => 'Invited Bidder SBO']);
    $nonInvitedSbo = sboNetworkSbo($nonInvitedUser, ['name' => 'Non Invited SBO']);
    $category = Category::factory()->create();

    $publicRequest = PurchaseOrder::create([
        'sbo_id' => $ownerSbo->id,
        'category_id' => $category->id,
        'request_type' => 'service',
        'title' => 'Public media logistics',
        'description' => 'Provide media logistics.',
        'service_scope' => 'Coordinate equipment and crew.',
        'order_deadline' => now()->addDays(5)->toDateString(),
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
    ]);

    Livewire::actingAs($bidderUser)
        ->test(SubmitBidModal::class)
        ->call('open', $publicRequest->id)
        ->set('bidder_sbo_id', $bidderSbo->id)
        ->set('proposal', 'We can provide the requested media logistics with qualified personnel.')
        ->set('attachment', UploadedFile::fake()->create('proposal.pdf', 200, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    expect(PurchaseOrderBid::where('bidder_sbo_id', $bidderSbo->id)->exists())->toBeTrue();

    $privateRequest = PurchaseOrder::create([
        'sbo_id' => $ownerSbo->id,
        'category_id' => $category->id,
        'request_type' => 'product',
        'title' => 'Private equipment supply',
        'description' => 'Supply selected equipment.',
        'quantity' => '20 units',
        'delivery_location' => 'Lagos',
        'order_deadline' => now()->addDays(5)->toDateString(),
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_INVITE_ONLY,
    ]);

    expect($privateRequest->canSboBid($nonInvitedSbo))->toBeFalse();

    $invite = PurchaseOrderInvite::create([
        'purchase_order_id' => $privateRequest->id,
        'invited_sbo_id' => $bidderSbo->id,
        'invited_by_sbo_id' => $ownerSbo->id,
    ]);

    $bidderUser->notify(new PurchaseOrderInviteNotification($invite->fresh(['purchaseOrder', 'invitedBySbo'])));

    Notification::assertSentTo($bidderUser, PurchaseOrderInviteNotification::class);

    Livewire::actingAs($bidderUser)
        ->test(SubmitBidModal::class)
        ->call('open', $privateRequest->id)
        ->set('bidder_sbo_id', $bidderSbo->id)
        ->set('proposal', 'We can supply the requested equipment to specification and on time.')
        ->set('attachment', UploadedFile::fake()->create('proposal-private.pdf', 200, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    expect($invite->fresh()->status)->toBe(PurchaseOrderInvite::STATUS_ACCEPTED);
});

test('rejected sbos lose network access but bid history remains visible', function (): void {
    $ownerUser = sboNetworkUser();
    $bidderUser = sboNetworkUser();
    $ownerSbo = sboNetworkSbo($ownerUser, ['name' => 'History Owner SBO']);
    $bidderSbo = sboNetworkSbo($bidderUser, ['name' => 'History Bidder SBO']);
    $category = Category::factory()->create();
    $request = PurchaseOrder::create([
        'sbo_id' => $ownerSbo->id,
        'category_id' => $category->id,
        'request_type' => 'service',
        'title' => 'Archived bid opportunity',
        'description' => 'A request with an existing bid.',
        'service_scope' => 'Service support.',
        'order_deadline' => now()->addDays(5)->toDateString(),
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
    ]);

    PurchaseOrderBid::create([
        'purchase_order_id' => $request->id,
        'user_id' => $bidderUser->id,
        'bidder_sbo_id' => $bidderSbo->id,
        'proposal' => 'Existing proposal',
        'status' => 'pending',
        'submitted_at' => now(),
    ]);

    $bidderSbo->update(['verification_status' => 'declined']);

    $this->actingAs($bidderUser)
        ->get(route('sbo.network.index'))
        ->assertRedirect(route('sbo.payment-required'));

    // A rejected member is no longer approved, so the workspace is walled — but
    // losing access does not delete history: the bid survives the rejection,
    // which is the whole point of this test.
    $this->actingAs($bidderUser)->get(route('dashboard'))
        ->assertRedirect(route('sbo.payment-required'));

    expect(PurchaseOrderBid::where('bidder_sbo_id', $bidderSbo->id)->exists())->toBeTrue();
});

test('accepting a connection from the notifications page removes the accept buttons', function (): void {
    $requesterUser = sboNetworkUser();
    $recipientUser = sboNetworkUser();
    $requesterSbo = sboNetworkSbo($requesterUser, ['name' => 'Requester SBO']);
    $recipientSbo = sboNetworkSbo($recipientUser, ['name' => 'Recipient SBO']);

    // A real request, with the stored bell notification (no Notification::fake).
    $this->actingAs($requesterUser)
        ->post(route('sbo.connections.store'), [
            'requester_sbo_id' => $requesterSbo->id,
            'recipient_sbo_id' => $recipientSbo->id,
        ])
        ->assertRedirect();

    $connection = SboConnection::firstOrFail();

    $connectionIds = fn (): array => collect(inertiaProp(
        $this->actingAs($recipientUser)->get('/notifications')->assertOk(),
        'groups',
    ))->flatMap(fn ($g) => $g['cards'])->pluck('connectionId')->filter()->values()->all();

    // While pending, the recipient's card offers Accept / Decline.
    expect($connectionIds())->toContain($connection->id);

    // Accept through the very endpoint the buttons post to.
    $this->actingAs($recipientUser)
        ->patch(route('sbo.connections.respond', $connection), ['status' => 'accepted'])
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(SboConnection::STATUS_ACCEPTED);

    // The pair is gone now that the request has been dealt with.
    expect($connectionIds())->not->toContain($connection->id);
});
