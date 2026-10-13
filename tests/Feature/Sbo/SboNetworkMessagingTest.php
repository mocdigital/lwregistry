<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\SboMessageSent;
use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\User;
use App\Notifications\SboConnectionAcceptedNotification;
use App\Notifications\SboConnectionRequestedNotification;
use App\Notifications\SboMessageReceivedNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * The direct thread specifically.
 *
 * `SboConversation::first()` used to be unambiguous. It is not any more: a
 * verified SBO is put into its industry's group chat on save, so these
 * fixtures create a room as well as the thread under test.
 */
function directConversation(): SboConversation
{
    return SboConversation::where('type', SboConversation::TYPE_DIRECT)->firstOrFail();
}

/** Direct threads only — the industry rooms are not what these count. */
function directConversationCount(): int
{
    return SboConversation::where('type', SboConversation::TYPE_DIRECT)->count();
}

/*
 * The SBO Network messaging surface: the composer, the Unread / Connections /
 * Groups categories, and live delivery.
 *
 * The read screen was 500ing before this — SboWorkspaceController eager-loaded
 * `requesterSbo` / `recipientSbo`, which are not the relation names — so the
 * first test here is simply that the page renders at all.
 */

function messagingUser(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

/** @param array<string, mixed> $overrides */
function messagingSbo(User $owner, string $name, array $overrides = []): Sbo
{
    return Sbo::create([...[
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
        'category' => 'Technology Services',
        'city' => 'Lagos',
        'country' => 'Nigeria',
    ], ...$overrides]);
}

/** Two owners, each with one SBO, already connected. */
function connectedPair(): array
{
    $aUser = messagingUser();
    $bUser = messagingUser();
    $a = messagingSbo($aUser, 'Alpha Systems');
    $b = messagingSbo($bUser, 'Beta Works');

    $connection = SboConnection::create([
        'requester_sbo_id' => $a->id,
        'recipient_sbo_id' => $b->id,
        'status' => SboConnection::STATUS_ACCEPTED,
        'responded_at' => now(),
    ]);

    return [$aUser, $a, $bUser, $b, $connection];
}

test('the network screen renders for a verified owner', function (): void {
    [$aUser] = connectedPair();

    $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'connections']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sbo/SboNetworkView')
            ->where('counts.connections', 1)
            ->where('connections.0.name', 'Beta Works'));
});

test('composing opens a conversation with an accepted connection', function (): void {
    Event::fake([SboMessageSent::class]);
    [$aUser, $a, , $b] = connectedPair();

    $this->actingAs($aUser)
        ->post(route('sbo.conversations.store'), [
            'sbo_id' => $a->id,
            'recipient_sbo_id' => $b->id,
            'body' => 'Hello from Alpha.',
        ])
        ->assertRedirect();

    $conversation = directConversation();

    expect($conversation)->not->toBeNull()
        ->and($conversation->type)->toBe(SboConversation::TYPE_DIRECT)
        ->and($conversation->participants()->pluck('sbos.id')->all())
        ->toEqualCanonicalizing([$a->id, $b->id])
        ->and(SboMessage::where('conversation_id', $conversation->id)->value('body'))
        ->toBe('Hello from Alpha.');

    Event::assertDispatched(SboMessageSent::class);
});

test('composing twice reuses the same conversation', function (): void {
    [$aUser, $a, , $b] = connectedPair();

    foreach (['first', 'second'] as $body) {
        $this->actingAs($aUser)->post(route('sbo.conversations.store'), [
            'sbo_id' => $a->id,
            'recipient_sbo_id' => $b->id,
            'body' => $body,
        ]);
    }

    expect(directConversationCount())->toBe(1)
        ->and(SboMessage::count())->toBe(2);
});

test('you cannot compose to an sbo you are not connected to', function (): void {
    $aUser = messagingUser();
    $a = messagingSbo($aUser, 'Alpha Systems');
    $stranger = messagingSbo(messagingUser(), 'Stranger Ltd');

    $this->actingAs($aUser)
        ->post(route('sbo.conversations.store'), [
            'sbo_id' => $a->id,
            'recipient_sbo_id' => $stranger->id,
        ])
        ->assertStatus(422);

    expect(directConversationCount())->toBe(0);
});

test('a message is attributed to the sbo that is party to the conversation', function (): void {
    [$aUser, $a, , $b, $connection] = connectedPair();

    // A second, unrelated listing owned by the same user. The old code posted
    // as `$mine->first()`, which is this one about half the time.
    $other = messagingSbo($aUser, 'Alpha Second Listing');
    expect($other->id)->not->toBe($a->id);

    $conversation = SboConversation::create([
        'connection_id' => $connection->id,
        'created_by_sbo_id' => $b->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);

    $this->actingAs($aUser)
        ->post(route('sbo.messages.store', $conversation), ['body' => 'Attributed correctly.'])
        ->assertRedirect();

    expect(SboMessage::first()->sbo_id)->toBe($a->id);
});

test('a stranger cannot post into or read a conversation', function (): void {
    [, , , $b, $connection] = connectedPair();

    $conversation = SboConversation::create([
        'connection_id' => $connection->id,
        'created_by_sbo_id' => $b->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);

    $stranger = messagingUser();
    messagingSbo($stranger, 'Stranger Ltd');

    $this->actingAs($stranger)
        ->post(route('sbo.messages.store', $conversation), ['body' => 'Let me in'])
        ->assertForbidden();

    $this->actingAs($stranger)
        ->post(route('sbo.messages.read', $conversation))
        ->assertForbidden();

    expect(SboMessage::count())->toBe(0);
});

test('the inbox reports unread counts from read_at, and opening a thread clears them', function (): void {
    [$aUser, $a, $bUser, $b, $connection] = connectedPair();

    $conversation = SboConversation::create([
        'connection_id' => $connection->id,
        'created_by_sbo_id' => $b->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);

    SboMessage::create([
        'conversation_id' => $conversation->id,
        'sbo_id' => $b->id,
        'user_id' => $bUser->id,
        'body' => 'Unread by Alpha',
    ]);

    /*
     * The connections tab does not open a thread, so the count survives.
     * Found by id rather than at position 0: the industry room is pinned above
     * everything, so the first row is no longer whatever this test created.
     */
    $response = $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'connections']))
        ->assertOk();

    $row = collect(inertiaProp($response, 'conversations'))
        ->firstWhere('id', (string) $conversation->id);

    expect(inertiaProp($response, 'counts.unread'))->toBe(1)
        ->and($row['unread'])->toBe(1)
        ->and($row['category'])->toBe('connections');

    // Opening the messages tab reads it.
    $this->actingAs($aUser)->get(route('sbo.network.index', ['tab' => 'messages']));

    expect(SboMessage::first()->read_at)->not->toBeNull();
    expect($a->id)->toBeInt();
});

test('conversation category is keyed off the conversation type', function (): void {
    [$aUser, $a, , , $connection] = connectedPair();

    SboConversation::create([
        'connection_id' => $connection->id,
        'created_by_sbo_id' => $a->id,
        'type' => SboConversation::TYPE_BID,
        'last_message_at' => now(),
    ]);

    $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertInertia(fn ($page) => $page->where('conversations.0.category', 'groups'));
});

test('a group conversation is visible through the participants pivot alone', function (): void {
    $owner = messagingUser();
    $sbo = messagingSbo($owner, 'Alpha Systems');
    $other = messagingSbo(messagingUser(), 'Beta Works');

    // No connection row at all — this is how a bid thread is shaped.
    $conversation = SboConversation::create([
        'created_by_sbo_id' => $other->id,
        'type' => SboConversation::TYPE_BID,
        'last_message_at' => now(),
    ]);
    $conversation->participants()->attach([$sbo->id, $other->id]);

    $response = $this->actingAs($owner)
        ->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertOk();

    expect(inertiaPluck($response, 'conversations', 'id'))->toContain((string) $conversation->id);

    $this->actingAs($owner)
        ->post(route('sbo.messages.store', $conversation), ['body' => 'On the bid thread'])
        ->assertRedirect();

    expect(SboMessage::first()->sbo_id)->toBe($sbo->id);
});

test('sending notifies the other side and broadcasts to the thread', function (): void {
    Notification::fake();
    Event::fake([SboMessageSent::class]);

    [$aUser, $a, $bUser, $b] = connectedPair();

    $this->actingAs($aUser)->post(route('sbo.conversations.store'), [
        'sbo_id' => $a->id,
        'recipient_sbo_id' => $b->id,
        'body' => 'Ping',
    ]);

    Notification::assertSentTo($bUser, SboMessageReceivedNotification::class);
    Notification::assertNotSentTo($aUser, SboMessageReceivedNotification::class);
    Event::assertDispatched(
        SboMessageSent::class,
        fn (SboMessageSent $e): bool => 'Ping' === $e->message->body,
    );
});

test('connecting and accepting notify the other owner', function (): void {
    Notification::fake();

    $aUser = messagingUser();
    $bUser = messagingUser();
    $a = messagingSbo($aUser, 'Alpha Systems');
    $b = messagingSbo($bUser, 'Beta Works');

    $this->actingAs($aUser)->post(route('sbo.connections.store'), [
        'requester_sbo_id' => $a->id,
        'recipient_sbo_id' => $b->id,
    ])->assertRedirect();

    Notification::assertSentTo($bUser, SboConnectionRequestedNotification::class);

    $connection = SboConnection::first();

    $this->actingAs($bUser)
        ->patch(route('sbo.connections.respond', $connection), ['status' => 'accepted'])
        ->assertRedirect();

    Notification::assertSentTo($aUser, SboConnectionAcceptedNotification::class);

    // Accepting opens the channel, with both sides in the pivot.
    $conversation = directConversation();
    expect($conversation)->not->toBeNull()
        ->and($conversation->participants()->pluck('sbos.id')->all())
        ->toEqualCanonicalizing([$a->id, $b->id]);
});

test('the broadcast channel only admits a party to the conversation', function (): void {
    [$aUser, , , $b, $connection] = connectedPair();

    $conversation = SboConversation::create([
        'connection_id' => $connection->id,
        'created_by_sbo_id' => $b->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);

    $stranger = messagingUser();
    messagingSbo($stranger, 'Stranger Ltd');

    /*
     * Switched only now that the fixtures are written — SboObserver broadcasts
     * on create, and a real broadcaster would try to reach a Reverb server.
     *
     * The switch is necessary at all because BROADCAST_CONNECTION is `log`
     * locally and LogBroadcaster::auth() is a no-op that authorises
     * everything, so the channel callback would never run. auth() itself only
     * signs a string; no network is involved.
     */
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        // Required, or the Pusher client builds "http://:8080" and cannot
        // parse its own URI.
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
    ]);

    /*
     * `Broadcast::channel()` forwards to whichever driver is *default at the
     * time*, so the registrations made at boot live on the log broadcaster.
     * Re-running the file puts them on the reverb one we just switched to;
     * without this the loop finds no matching channel and denies everyone,
     * which would make this test pass for the wrong reason.
     */
    require base_path('routes/channels.php');

    $channel = 'private-sbo.conversation.' . $conversation->id;

    $this->actingAs($aUser)
        ->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])
        ->assertOk();

    $this->actingAs($stranger)
        ->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])
        ->assertForbidden();
});

/* ------------------------------------------------ inbox registry search --- */

test('the inbox search finds businesses you have never messaged', function (): void {
    [$aUser, $a] = connectedPair();

    // A third business, unconnected and never messaged.
    messagingSbo(messagingUser(), 'Zenith Foods');

    $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'q' => 'Zenith']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('q', 'Zenith')
            ->count('people', 1)
            ->where('people.0.name', 'Zenith Foods')
            // Not connected, so the row offers Connect rather than Message.
            ->where('people.0.connection', 'none')
            ->where('people.0.conversationId', null));

    expect($a->id)->toBeInt();
});

test('a search hit you are connected to reports the open thread', function (): void {
    [$aUser, $a, , $b] = connectedPair();

    $this->actingAs($aUser)->post(route('sbo.conversations.store'), [
        'sbo_id' => $a->id,
        'recipient_sbo_id' => $b->id,
        'body' => 'Hi',
    ]);

    $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'q' => 'Beta']))
        ->assertInertia(fn ($page) => $page
            ->where('people.0.connection', 'accepted')
            ->where('people.0.conversationId', (string) directConversation()->id));
});

test('the search never returns your own listings, and needs two characters', function (): void {
    [$aUser] = connectedPair();

    $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'q' => 'Alpha']))
        ->assertInertia(fn ($page) => $page->where('people', []));

    // One character would match most of the registry and mean nothing.
    $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'q' => 'B']))
        ->assertInertia(fn ($page) => $page->where('people', []));
});

test('the search matches category, city and country as well as name', function (): void {
    [$aUser] = connectedPair();
    messagingSbo(messagingUser(), 'Unrelated Name Ltd');

    /*
     * A search hit keeps the category in its meta line: the row has no tag
     * under it, so this is the only thing distinguishing two businesses with
     * similar names. The network CARD drops it, because the card prints the
     * category as a chip immediately below.
     */
    $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'q' => 'Lagos']))
        ->assertInertia(fn ($page) => $page->where('people.0.meta', 'Technology Services, Lagos, Nigeria'));
});

test('a declined registration is not searchable from the inbox', function (): void {
    [$aUser] = connectedPair();
    messagingSbo(messagingUser(), 'Hidden Ltd', ['verification_status' => 'declined']);

    $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'q' => 'Hidden']))
        ->assertInertia(fn ($page) => $page->where('people', []));
});
