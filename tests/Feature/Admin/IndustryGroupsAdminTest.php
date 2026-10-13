<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Sbo;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\User;
use App\Support\AdminPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * Industry group chats, in the admin.
 *
 * Members are placed in these rooms automatically on verification — no join
 * step, no invitation — and there was no admin surface at all: nobody could
 * see the rooms, who was in them, or what was being said.
 */

function groupsAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);
}

function groupsMemberSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Groups SBO ' . Str::random(5);
    $owner = $overrides['owner'] ?? User::factory()->create(['email_verified_at' => now()]);

    unset($overrides['owner']);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'category' => 'Software Development',
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('the page lists every category room with its real roster', function (): void {
    groupsMemberSbo(['name' => 'Tech One', 'category' => 'Software Development']);
    groupsMemberSbo(['name' => 'Tech Two', 'category' => 'Technology Services']);
    groupsMemberSbo(['name' => 'Bakery', 'category' => 'Restaurants & Cafés']);

    // One room per category now, not per broad industry.
    Volt::actingAs(groupsAdmin())
        ->test('admin.network.groups')
        ->assertOk()
        ->assertSee('Software Development')
        ->assertSee('Technology Services')
        ->assertSee('Restaurants & Cafés');
});

test('opening a room shows its members and messages', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $sbo = groupsMemberSbo(['owner' => $owner, 'name' => 'Chatty Business']);

    $room = SboConversation::where('type', SboConversation::TYPE_INDUSTRY)->firstOrFail();

    SboMessage::create([
        'conversation_id' => $room->id,
        'sbo_id' => $sbo->id,
        'user_id' => $owner->id,
        'body' => 'Anyone bidding on the Lagos RFQ?',
    ]);

    Volt::actingAs(groupsAdmin())
        ->test('admin.network.groups')
        ->call('select', $room->id)
        ->assertOk()
        ->assertSee('Chatty Business')
        ->assertSee('Anyone bidding on the Lagos RFQ?');
});

test('an admin can delete a message, and it is logged', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $sbo = groupsMemberSbo(['owner' => $owner]);
    $room = SboConversation::where('type', SboConversation::TYPE_INDUSTRY)->firstOrFail();

    $message = SboMessage::create([
        'conversation_id' => $room->id,
        'sbo_id' => $sbo->id,
        'user_id' => $owner->id,
        'body' => 'Something that needs removing',
    ]);

    Volt::actingAs(groupsAdmin())
        ->test('admin.network.groups')
        ->call('select', $room->id)
        ->call('deleteMessage', $message->id)
        ->assertOk();

    expect(SboMessage::find($message->id))->toBeNull();

    // Removing what a member wrote is an administrative act, so it is
    // attributable rather than silent.
    expect(ActivityLog::where('type', 'group_message_deleted')->exists())->toBeTrue();
});

test('the page cannot be used to read a direct conversation', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $sbo = groupsMemberSbo(['owner' => $owner]);
    $other = groupsMemberSbo(['name' => 'Other Party']);

    $connection = App\Models\SboConnection::create([
        'requester_sbo_id' => $sbo->id,
        'recipient_sbo_id' => $other->id,
        'status' => App\Models\SboConnection::STATUS_ACCEPTED,
        'responded_at' => now(),
    ]);

    $private = SboConversation::create([
        'connection_id' => $connection->id,
        'created_by_sbo_id' => $sbo->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);

    SboMessage::create([
        'conversation_id' => $private->id,
        'sbo_id' => $sbo->id,
        'user_id' => $owner->id,
        'body' => 'A private matter between two businesses',
    ]);

    // Selecting it is refused: this screen is scoped to industry rooms.
    Volt::actingAs(groupsAdmin())
        ->test('admin.network.groups')
        ->call('select', $private->id)
        ->assertOk()
        ->assertDontSee('A private matter between two businesses');
});

test('a private message cannot be deleted through this screen either', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $sbo = groupsMemberSbo(['owner' => $owner]);

    $private = SboConversation::create([
        'created_by_sbo_id' => $sbo->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);

    $message = SboMessage::create([
        'conversation_id' => $private->id,
        'sbo_id' => $sbo->id,
        'user_id' => $owner->id,
        'body' => 'Private',
    ]);

    Volt::actingAs(groupsAdmin())
        ->test('admin.network.groups')
        ->call('deleteMessage', $message->id);

    expect(SboMessage::find($message->id))->not->toBeNull();
});

test('the page is behind its own admin permission', function (): void {
    groupsMemberSbo();

    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'admin_page_permissions' => [AdminPages::NETWORK_GROUPS],
        'permissions' => AdminPages::capabilitiesForPages([AdminPages::NETWORK_GROUPS]),
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)->get(route('admin.network.groups'))->assertOk();

    // An admin without it does not reach the page.
    $other = User::factory()->create([
        'role' => UserRole::ADMIN,
        'admin_page_permissions' => [AdminPages::REPORTS],
        'permissions' => AdminPages::capabilitiesForPages([AdminPages::REPORTS]),
        'email_verified_at' => now(),
    ]);

    $this->actingAs($other)->get(route('admin.network.groups'))->assertForbidden();
});

test('a member cannot reach the admin group screen', function (): void {
    $this->actingAs(paidMember())->get(route('admin.network.groups'))->assertForbidden();
});

test('a guest is sent to sign in', function (): void {
    // Its own test: `actingAs` persists for the rest of a test, so a guest
    // assertion after an authenticated one is not actually a guest.
    $this->get(route('admin.network.groups'))->assertRedirect(route('login'));
});

test('the admin replies to the group as the registry', function (): void {
    groupsMemberSbo(['name' => 'Chatty Business']);
    $room = SboConversation::where('type', SboConversation::TYPE_INDUSTRY)->firstOrFail();

    Volt::actingAs(groupsAdmin())
        ->test('admin.network.groups')
        ->call('select', $room->id)
        ->set('reply', 'Welcome to the group, everyone.')
        ->call('sendReply')
        ->assertOk();

    $registry = App\Support\RegistrySbo::ensure();
    $message = SboMessage::where('conversation_id', $room->id)
        ->where('body', 'Welcome to the group, everyone.')->first();

    expect($message)->not->toBeNull()
        ->and($message->sbo_id)->toBe($registry->id);
});

test('the admin replies privately to a member, opening a direct thread', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $sbo = groupsMemberSbo(['owner' => $owner, 'name' => 'Member Biz']);
    $room = SboConversation::where('type', SboConversation::TYPE_INDUSTRY)->firstOrFail();
    $msg = SboMessage::create(['conversation_id' => $room->id, 'sbo_id' => $sbo->id, 'user_id' => $owner->id, 'body' => 'Question']);

    Volt::actingAs(groupsAdmin())
        ->test('admin.network.groups')
        ->call('select', $room->id)
        ->call('startPrivateReply', $msg->id)
        ->set('reply', 'Here privately.')
        ->call('sendReply')
        ->assertOk();

    $registry = App\Support\RegistrySbo::ensure();
    $direct = SboConversation::where('type', SboConversation::TYPE_DIRECT)
        ->whereHas('participants', fn ($q) => $q->whereKey($registry->id))
        ->whereHas('participants', fn ($q) => $q->whereKey($sbo->id))->first();

    expect($direct)->not->toBeNull()
        ->and(SboMessage::where('conversation_id', $direct->id)->where('body', 'Here privately.')->exists())->toBeTrue();
});

test('the admin can react to and pin a group message', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $sbo = groupsMemberSbo(['owner' => $owner]);
    $room = SboConversation::where('type', SboConversation::TYPE_INDUSTRY)->firstOrFail();
    $msg = SboMessage::create(['conversation_id' => $room->id, 'sbo_id' => $sbo->id, 'user_id' => $owner->id, 'body' => 'Hi']);

    $admin = groupsAdmin();
    $component = Volt::actingAs($admin)->test('admin.network.groups')->call('select', $room->id);

    $component->call('react', $msg->id, '👍')->assertOk();
    expect(App\Models\SboMessageReaction::where('message_id', $msg->id)->where('emoji', '👍')->where('user_id', $admin->id)->exists())->toBeTrue();

    $component->call('pinMessage', $msg->id)->assertOk();
    expect($msg->fresh()->pinned_at)->not->toBeNull();
});

test('the admin flags a group message into the reports queue', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $sbo = groupsMemberSbo(['owner' => $owner]);
    $room = SboConversation::where('type', SboConversation::TYPE_INDUSTRY)->firstOrFail();
    $msg = SboMessage::create(['conversation_id' => $room->id, 'sbo_id' => $sbo->id, 'user_id' => $owner->id, 'body' => 'Spammy']);

    Volt::actingAs(groupsAdmin())
        ->test('admin.network.groups')
        ->call('select', $room->id)
        ->call('reportMessage', $msg->id, 'spam')
        ->assertOk();

    $registry = App\Support\RegistrySbo::ensure();
    expect(App\Models\SboNetworkReport::where('message_id', $msg->id)
        ->where('reporter_sbo_id', $registry->id)->where('status', 'open')->exists())->toBeTrue();
});
