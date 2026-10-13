<?php

declare(strict_types=1);

use App\Models\SboConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Taking a group conversation aside.
 *
 * "Message the admin privately", "the admin messages a member privately" and
 * "members message each other privately" are one act, not three: two
 * businesses who share a room open a direct thread. Splitting them by role
 * would be three endpoints enforcing a single rule, and the rule is about
 * sharing a room rather than about who is senior in it.
 *
 * Deliberately NOT gated on an accepted connection. Requiring one would mean
 * you may speak to someone in front of thirty people but not to them alone,
 * which is backwards — being in the room together is the relationship.
 */

/** @return array{0: User, 1: User, 2: User, 3: SboConversation} */
function groupOfThree(): array
{
    $adminUser = paidMember([], ['name' => 'Group Admin Ltd']);
    $oneUser = paidMember([], ['name' => 'Member One Ltd']);
    $twoUser = paidMember([], ['name' => 'Member Two Ltd']);

    $conversation = SboConversation::create([
        'created_by_sbo_id' => $adminUser->sbos()->first()->id,
        'type' => SboConversation::TYPE_INDUSTRY,
        'industry' => 'Technology Services',
        'last_message_at' => now(),
    ]);

    $conversation->participants()->syncWithoutDetaching([
        $adminUser->sbos()->first()->id,
        $oneUser->sbos()->first()->id,
        $twoUser->sbos()->first()->id,
    ]);

    return [$adminUser, $oneUser, $twoUser, $conversation];
}

test('a member can take the group admin aside', function (): void {
    [$adminUser, $oneUser, , $group] = groupOfThree();

    $this->actingAs($oneUser)
        ->post(route('sbo.conversations.private', $group), [
            'recipient_sbo_id' => $adminUser->sbos()->first()->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $private = SboConversation::query()
        ->where('type', SboConversation::TYPE_DIRECT)
        ->firstOrFail();

    expect($private->participants()->pluck('sbos.id')->sort()->values()->all())
        ->toBe(collect([$adminUser->sbos()->first()->id, $oneUser->sbos()->first()->id])->sort()->values()->all());
});

test('the admin can take a member aside', function (): void {
    [$adminUser, $oneUser, , $group] = groupOfThree();

    $this->actingAs($adminUser)
        ->post(route('sbo.conversations.private', $group), [
            'recipient_sbo_id' => $oneUser->sbos()->first()->id,
        ])
        ->assertSessionHasNoErrors();

    expect(SboConversation::query()->where('type', SboConversation::TYPE_DIRECT)->count())->toBe(1);
});

test('two members can speak without the admin', function (): void {
    [$adminUser, $oneUser, $twoUser, $group] = groupOfThree();

    $this->actingAs($oneUser)
        ->post(route('sbo.conversations.private', $group), [
            'recipient_sbo_id' => $twoUser->sbos()->first()->id,
        ])
        ->assertSessionHasNoErrors();

    $private = SboConversation::query()->where('type', SboConversation::TYPE_DIRECT)->firstOrFail();

    // The admin is not quietly a party to it. A private conversation that the
    // group's owner can read is not a private conversation.
    expect($private->participants()->pluck('sbos.id')->all())
        ->not->toContain($adminUser->sbos()->first()->id);
});

test('no connection is required, and none is invented', function (): void {
    [, $oneUser, $twoUser, $group] = groupOfThree();

    $this->actingAs($oneUser)->post(route('sbo.conversations.private', $group), [
        'recipient_sbo_id' => $twoUser->sbos()->first()->id,
    ]);

    $private = SboConversation::query()->where('type', SboConversation::TYPE_DIRECT)->firstOrFail();

    /*
     * Sharing a room authorises the thread; it does not make the two
     * businesses connections. Writing a connection row here would put a
     * relationship in the network that neither side agreed to.
     */
    expect($private->connection_id)->toBeNull()
        ->and(App\Models\SboConnection::query()->count())->toBe(0);
});

test('asking twice reopens the same thread', function (): void {
    [, $oneUser, $twoUser, $group] = groupOfThree();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($oneUser)->post(route('sbo.conversations.private', $group), [
            'recipient_sbo_id' => $twoUser->sbos()->first()->id,
        ]);
    }

    // Otherwise the history splits in two and neither half is the record.
    expect(SboConversation::query()->where('type', SboConversation::TYPE_DIRECT)->count())->toBe(1);
});

test('either side asking lands in the same thread', function (): void {
    [, $oneUser, $twoUser, $group] = groupOfThree();

    $this->actingAs($oneUser)->post(route('sbo.conversations.private', $group), [
        'recipient_sbo_id' => $twoUser->sbos()->first()->id,
    ]);

    $this->actingAs($twoUser)->post(route('sbo.conversations.private', $group), [
        'recipient_sbo_id' => $oneUser->sbos()->first()->id,
    ]);

    expect(SboConversation::query()->where('type', SboConversation::TYPE_DIRECT)->count())->toBe(1);
});

test('someone outside the group cannot use it as a way in', function (): void {
    [, $oneUser, , $group] = groupOfThree();

    // Not in the room, so there is nobody here to take aside.
    $this->actingAs(paidMember())
        ->post(route('sbo.conversations.private', $group), [
            'recipient_sbo_id' => $oneUser->sbos()->first()->id,
        ])
        ->assertForbidden();

    expect(SboConversation::query()->where('type', SboConversation::TYPE_DIRECT)->count())->toBe(0);
});

test('a business outside the group cannot be pulled into one', function (): void {
    [, $oneUser, , $group] = groupOfThree();

    $outsider = paidMember([], ['name' => 'Uninvolved Ltd']);

    /*
     * Without this the endpoint is a way to open a private channel with any
     * business in the registry, unsolicited, by naming them from a room they
     * have never been in.
     */
    $this->actingAs($oneUser)
        ->post(route('sbo.conversations.private', $group), [
            'recipient_sbo_id' => $outsider->sbos()->first()->id,
        ])
        ->assertStatus(422);

    expect(SboConversation::query()->where('type', SboConversation::TYPE_DIRECT)->count())->toBe(0);
});

test('you cannot take yourself aside', function (): void {
    [, $oneUser, , $group] = groupOfThree();

    $this->actingAs($oneUser)
        ->post(route('sbo.conversations.private', $group), [
            'recipient_sbo_id' => $oneUser->sbos()->first()->id,
        ])
        ->assertSessionHasErrors('recipient_sbo_id');
});

test('the private thread is a real one that can be posted into', function (): void {
    [$adminUser, $oneUser, , $group] = groupOfThree();

    $this->actingAs($oneUser)->post(route('sbo.conversations.private', $group), [
        'recipient_sbo_id' => $adminUser->sbos()->first()->id,
    ]);

    $private = SboConversation::query()->where('type', SboConversation::TYPE_DIRECT)->firstOrFail();

    // participantSboId is what authorises posting, and it answers for a
    // conversation with no connection row behind it.
    $this->actingAs($oneUser)
        ->post(route('sbo.messages.store', $private), ['body' => 'A word in private.'])
        ->assertSessionHasNoErrors();

    $this->actingAs($adminUser)
        ->post(route('sbo.messages.store', $private), ['body' => 'Go ahead.'])
        ->assertSessionHasNoErrors();

    expect(App\Models\SboMessage::query()->where('conversation_id', $private->id)->count())->toBe(2);
});

test('a group thread with two members is not mistaken for the private one', function (): void {
    /*
     * The private thread is found by "both are participants and nobody else
     * is". A two-person GROUP would match that pair test, so the type is part
     * of the key — otherwise asking for a private word would hand back the
     * group everyone can read.
     */
    $oneUser = paidMember([], ['name' => 'Pair One Ltd']);
    $twoUser = paidMember([], ['name' => 'Pair Two Ltd']);

    $group = SboConversation::create([
        'created_by_sbo_id' => $oneUser->sbos()->first()->id,
        'type' => SboConversation::TYPE_INDUSTRY,
        'industry' => 'Technology Services',
        'last_message_at' => now(),
    ]);

    $group->participants()->syncWithoutDetaching([
        $oneUser->sbos()->first()->id,
        $twoUser->sbos()->first()->id,
    ]);

    $this->actingAs($oneUser)->post(route('sbo.conversations.private', $group), [
        'recipient_sbo_id' => $twoUser->sbos()->first()->id,
    ]);

    $private = SboConversation::query()->where('type', SboConversation::TYPE_DIRECT)->firstOrFail();

    expect($private->id)->not->toBe($group->id);
});

test('the screen is told who else is in the room', function (): void {
    [$adminUser, $oneUser, $twoUser, $group] = groupOfThree();

    /*
     * The roster is what the "Message privately" control is drawn from, and it
     * is sent only for the conversation actually open — a roster per row would
     * be a query each to draw a menu nobody has clicked.
     */
    $this->actingAs($oneUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $group->id]))
        ->assertOk()
        ->assertInertia(function ($page) use ($adminUser, $oneUser, $twoUser): void {
            $names = collect($page->toArray()['props']['roster'])->pluck('name');

            expect($names)->toContain($adminUser->sbos()->first()->name)
                ->and($names)->toContain($twoUser->sbos()->first()->name)
                // Not yourself: you cannot take yourself aside, and offering
                // it would only produce a validation error.
                ->and($names)->not->toContain($oneUser->sbos()->first()->name);
        });
});

test('the conversation list carries the room members for the row menu', function (): void {
    [$adminUser, $oneUser, $twoUser, $group] = groupOfThree();

    /*
     * The row menu is the whole point of this: private messaging used to be
     * reachable only from inside an open group, which is not somewhere anyone
     * thinks to look. It is an action on a PERSON, so it belongs next to their
     * name in the list.
     */
    $this->actingAs($oneUser)
        ->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertOk()
        ->assertInertia(function ($page) use ($adminUser, $oneUser, $twoUser, $group): void {
            $row = collect($page->toArray()['props']['conversations'])
                ->firstWhere('id', (string) $group->id);

            $names = collect($row['members'])->pluck('name');

            expect($names)->toContain($adminUser->sbos()->first()->name)
                ->and($names)->toContain($twoUser->sbos()->first()->name)
                ->and($names)->not->toContain($oneUser->sbos()->first()->name);
        });
});

test('a direct row carries no member list to offer', function (): void {
    // A direct conversation IS the private one, so there is nobody to take
    // aside from it and the menu offers the profile instead.
    $aUser = paidMember([], ['name' => 'Direct One Ltd']);
    $bUser = paidMember([], ['name' => 'Direct Two Ltd']);

    $conversation = SboConversation::create([
        'created_by_sbo_id' => $aUser->sbos()->first()->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);

    $conversation->participants()->syncWithoutDetaching([
        $aUser->sbos()->first()->id,
        $bUser->sbos()->first()->id,
    ]);

    $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertOk()
        ->assertInertia(function ($page) use ($conversation): void {
            $row = collect($page->toArray()['props']['conversations'])
                ->firstWhere('id', (string) $conversation->id);

            expect($row['members'])->toBe([]);
        });
});

test('a guest cannot open a private thread out of a group', function (): void {
    [, $oneUser, , $group] = groupOfThree();

    $this->post(route('sbo.conversations.private', $group), [
        'recipient_sbo_id' => $oneUser->sbos()->first()->id,
    ])->assertRedirect(route('login'));
});
