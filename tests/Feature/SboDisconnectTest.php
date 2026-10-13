<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\SboConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Disconnecting.
 *
 * The network card's (x) posted `respond(declined)`, which only the recipient
 * of the original request may do — so whoever *sent* the request got a bare 403
 * when they tried to remove the connection. Disconnect is its own verb now,
 * open to either side.
 */

function disconnectSbo(User $owner, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Disconnect SBO ' . Str::random(6);

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

/** @return array{0: User, 1: Sbo, 2: User, 3: Sbo, 4: SboConnection} */
function disconnectPair(string $status = SboConnection::STATUS_ACCEPTED): array
{
    $requesterUser = User::factory()->create(['email_verified_at' => now()]);
    $recipientUser = User::factory()->create(['email_verified_at' => now()]);

    $requesterSbo = disconnectSbo($requesterUser, ['name' => 'Requester SBO']);
    $recipientSbo = disconnectSbo($recipientUser, ['name' => 'Recipient SBO']);

    $connection = SboConnection::create([
        'requester_sbo_id' => $requesterSbo->id,
        'recipient_sbo_id' => $recipientSbo->id,
        'status' => $status,
        'responded_at' => SboConnection::STATUS_PENDING === $status ? null : now(),
    ]);

    return [$requesterUser, $requesterSbo, $recipientUser, $recipientSbo, $connection];
}

test('the side that sent the request can disconnect', function (): void {
    // This is the exact case that returned 403.
    [$requesterUser, , , , $connection] = disconnectPair();

    $this->actingAs($requesterUser)
        ->delete(route('sbo.connections.destroy', $connection))
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(SboConnection::STATUS_REMOVED);
});

test('the side that accepted can disconnect too', function (): void {
    [, , $recipientUser, , $connection] = disconnectPair();

    $this->actingAs($recipientUser)
        ->delete(route('sbo.connections.destroy', $connection))
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(SboConnection::STATUS_REMOVED);
});

test('an unrelated member cannot disconnect two other businesses', function (): void {
    [, , , , $connection] = disconnectPair();

    $this->actingAs(paidMember())
        ->delete(route('sbo.connections.destroy', $connection))
        ->assertForbidden();

    expect($connection->fresh()->status)->toBe(SboConnection::STATUS_ACCEPTED);
});

test('a pending request is refused: that is what accept and decline are for', function (): void {
    [$requesterUser, , , , $connection] = disconnectPair(SboConnection::STATUS_PENDING);

    $this->actingAs($requesterUser)
        ->delete(route('sbo.connections.destroy', $connection))
        ->assertStatus(422);
});

test('disconnecting keeps the conversation and its history', function (): void {
    [$requesterUser, $requesterSbo, , , $connection] = disconnectPair();

    $conversation = SboConversation::create([
        'connection_id' => $connection->id,
        'created_by_sbo_id' => $requesterSbo->id,
        'type' => SboConversation::TYPE_DIRECT,
    ]);

    $this->actingAs($requesterUser)
        ->delete(route('sbo.connections.destroy', $connection))
        ->assertRedirect();

    // The row survives rather than being deleted, which is what keeps
    // `sbo_conversations.connection_id` pointing somewhere — deleting it would
    // null the FK and the thread would lose the name of who it is with.
    $conversation->refresh();

    expect($conversation->connection_id)->toBe($connection->id)
        ->and($conversation->sboConnection)->not->toBeNull();
});

test('two businesses can connect again after disconnecting', function (): void {
    [$requesterUser, $requesterSbo, , $recipientSbo, $connection] = disconnectPair();

    $this->actingAs($requesterUser)->delete(route('sbo.connections.destroy', $connection));

    /*
     * The connect endpoint refused whenever *any* row existed, whatever its
     * status, so a disconnect used to be permanent and a declined request could
     * never be raised again.
     */
    $this->actingAs($requesterUser)
        ->post(route('sbo.connections.store'), [
            'requester_sbo_id' => $requesterSbo->id,
            'recipient_sbo_id' => $recipientSbo->id,
        ])
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(SboConnection::STATUS_PENDING)
        ->and($connection->fresh()->responded_at)->toBeNull()
        // Reused, not duplicated.
        ->and(SboConnection::count())->toBe(1);
});

test('a declined request can be raised again', function (): void {
    [$requesterUser, $requesterSbo, , $recipientSbo, $connection] = disconnectPair(
        SboConnection::STATUS_DECLINED,
    );

    $this->actingAs($requesterUser)
        ->post(route('sbo.connections.store'), [
            'requester_sbo_id' => $requesterSbo->id,
            'recipient_sbo_id' => $recipientSbo->id,
        ])
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(SboConnection::STATUS_PENDING);
});

test('a live connection still cannot be requested twice', function (): void {
    [$requesterUser, $requesterSbo, , $recipientSbo] = disconnectPair();

    $this->actingAs($requesterUser)
        ->post(route('sbo.connections.store'), [
            'requester_sbo_id' => $requesterSbo->id,
            'recipient_sbo_id' => $recipientSbo->id,
        ])
        ->assertSessionHas('status', 'connection-exists');

    expect(SboConnection::count())->toBe(1);
});

test('the business profile hands its hero buttons what they need', function (): void {
    [$requesterUser, , , $recipientSbo, $connection] = disconnectPair();

    $response = $this->actingAs($requesterUser)
        ->get(route('businesses.show', $recipientSbo->slug))
        ->assertOk();

    // Connect, Visit Website and Disconnect were all inert: two bare buttons
    // and an href="#".
    expect(inertiaProp($response, 'connection'))->toBe('accepted')
        ->and(inertiaProp($response, 'connectionId'))->toBe($connection->id)
        ->and(inertiaProp($response, 'mySboId'))->not->toBeNull()
        ->and(inertiaProp($response, 'business.website'))->toBeNull();
});

test('the profile offers Connect when there is no connection, and a website when there is one', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $target = disconnectSbo($owner, ['name' => 'Unconnected SBO', 'website' => 'example.com']);

    $response = $this->actingAs(paidMember())
        ->get(route('businesses.show', $target->slug))
        ->assertOk();

    expect(inertiaProp($response, 'connection'))->toBe('none')
        ->and(inertiaProp($response, 'connectionId'))->toBeNull()
        ->and(inertiaProp($response, 'business.website'))->toBe('example.com');
});

test('the profile knows when the listing is the viewer own', function (): void {
    $owner = paidMember([], ['name' => 'My Own Listing']);
    $mine = $owner->sbos()->firstOrFail();

    $response = $this->actingAs($owner)
        ->get(route('businesses.show', $mine->slug))
        ->assertOk();

    // No Connect button against yourself.
    expect(inertiaProp($response, 'connection'))->toBe('self');
});
