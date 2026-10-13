<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\User;
use App\Support\IndustryGroupChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The category group chat.
 *
 * Every verified business belongs to the room for the category it registered
 * under — one room per category, nobody joins it and nobody is invited — and it
 * is pinned above the rest of the inbox.
 */

function groupChatSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Group Chat SBO ' . Str::random(5);
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

/** The room for a category (the group key lives in the `industry` column). */
function categoryRoom(string $category): ?SboConversation
{
    return SboConversation::where('type', SboConversation::TYPE_INDUSTRY)
        ->where('industry', $category)
        ->first();
}

test('a verified business is put into the room for the category it registered under', function (): void {
    $sbo = groupChatSbo(['category' => 'Software Development']);

    $room = categoryRoom('Software Development');

    expect($room)->not->toBeNull()
        ->and($room->participants()->pluck('sbos.id'))->toContain($sbo->id);
});

test('businesses in the same category share one room, and different categories do not', function (): void {
    $one = groupChatSbo(['name' => 'Software House', 'category' => 'Software Development']);
    $two = groupChatSbo(['name' => 'Second Software Co', 'category' => 'Software Development']);
    $bakery = groupChatSbo(['name' => 'Corner Bakery', 'category' => 'Restaurants & Cafés']);

    $software = categoryRoom('Software Development');

    expect(SboConversation::where('type', SboConversation::TYPE_INDUSTRY)->count())->toBe(2)
        ->and($software->participants()->pluck('sbos.id'))
        ->toContain($one->id)
        ->toContain($two->id)
        ->not->toContain($bakery->id);
});

test('an unverified business is not in the room', function (): void {
    $unpaid = groupChatSbo([
        'name' => 'Still Registering',
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
    ]);

    expect(categoryRoom('Software Development'))->toBeNull();

    // And it joins the moment verification completes.
    $unpaid->update(['payment_status' => 'paid', 'payment_confirmed_at' => now()]);

    expect(categoryRoom('Software Development')?->participants()->pluck('sbos.id'))->toContain($unpaid->id);
});

test('losing verification takes a business out of the room', function (): void {
    $sbo = groupChatSbo();
    expect(categoryRoom('Software Development')->participants()->pluck('sbos.id'))->toContain($sbo->id);

    $sbo->update(['verification_status' => 'declined']);

    expect(categoryRoom('Software Development')->participants()->pluck('sbos.id'))->not->toContain($sbo->id);
});

test('recategorising a business moves it between rooms', function (): void {
    $sbo = groupChatSbo(['category' => 'Software Development']);
    expect(categoryRoom('Software Development')->participants()->pluck('sbos.id'))->toContain($sbo->id);

    $sbo->update(['category' => 'Restaurants & Cafés']);

    expect(categoryRoom('Software Development')->participants()->pluck('sbos.id'))->not->toContain($sbo->id)
        ->and(categoryRoom('Restaurants & Cafés')->participants()->pluck('sbos.id'))->toContain($sbo->id);
});

test('rejoining does not mark every message unread again', function (): void {
    $sbo = groupChatSbo();
    $room = categoryRoom('Software Development');

    $sbo->conversations()->updateExistingPivot($room->id, ['last_read_at' => now()]);

    // Any save re-syncs membership; syncWithoutDetaching keeps the read mark.
    $sbo->update(['description' => 'Touched.']);
    $sbo->update(['verification_status' => 'approved', 'documents_status' => 'compliant']);

    $pivot = $sbo->conversations()->where('sbo_conversations.id', $room->id)->first();

    expect($pivot->pivot->last_read_at)->not->toBeNull();
});

test('the room is pinned above every other conversation', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $sbo = groupChatSbo(['owner' => $owner, 'name' => 'Pinned Room Member']);
    $other = groupChatSbo(['name' => 'Someone Else']);

    // A direct thread with a much newer message than the room.
    $connection = App\Models\SboConnection::create([
        'requester_sbo_id' => $sbo->id,
        'recipient_sbo_id' => $other->id,
        'status' => App\Models\SboConnection::STATUS_ACCEPTED,
        'responded_at' => now(),
    ]);

    $direct = SboConversation::create([
        'connection_id' => $connection->id,
        'created_by_sbo_id' => $other->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);

    $response = $this->actingAs($owner)
        ->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertOk();

    $rows = collect(inertiaProp($response, 'conversations'));

    // Nobody opened the room deliberately, so left in date order it would sink
    // out of sight the first time two people had a quiet week.
    expect($rows->first()['group'])->toBeTrue()
        ->and($rows->first()['pinned'])->toBeTrue()
        ->and($rows->pluck('id'))->toContain((string) $direct->id);

    // Pinned position is not the same as open-by-default: the thread somebody
    // just wrote in is the one that opens.
    expect(inertiaProp($response, 'activeConversationId'))->toBe((string) $direct->id);
});

test('the room row carries its real roster, not a fixed count', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    groupChatSbo(['owner' => $owner, 'name' => 'Member One']);
    groupChatSbo(['name' => 'Member Two']);
    groupChatSbo(['name' => 'Member Three']);

    $response = $this->actingAs($owner)
        ->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertOk();

    $room = collect(inertiaProp($response, 'conversations'))->firstWhere('group', true);

    // The "+40" on the design's avatar stack is the member count.
    expect($room['participants'])->toBe(3)
        ->and($room['name'])->toBe('Software Development Group')
        ->and($room['org'])->toBe('Software Development · 3 members')
        ->and($room['category'])->toBe('groups');
});

test('a member can post into the room and everyone in it sees it', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $mine = groupChatSbo(['owner' => $owner, 'name' => 'Poster']);
    groupChatSbo(['name' => 'Reader']);

    $room = categoryRoom('Software Development');

    $this->actingAs($owner)
        ->post(route('sbo.messages.store', $room), ['body' => 'Anyone bidding on the Lagos RFQ?'])
        ->assertRedirect();

    $message = SboMessage::where('conversation_id', $room->id)->firstOrFail();

    // Attributed to the member's own SBO, not to whoever created the room.
    expect($message->sbo_id)->toBe($mine->id)
        ->and($message->body)->toBe('Anyone bidding on the Lagos RFQ?');
});

test('a business outside the category cannot post into the room', function (): void {
    groupChatSbo(['name' => 'Tech Member']);
    $room = categoryRoom('Software Development');

    $outsiderOwner = User::factory()->create(['email_verified_at' => now()]);
    groupChatSbo(['owner' => $outsiderOwner, 'name' => 'Baker', 'category' => 'Restaurants & Cafés']);

    $this->actingAs($outsiderOwner)
        ->post(route('sbo.messages.store', $room), ['body' => 'Wrong room'])
        ->assertForbidden();
});

test('the room is created once, however many businesses verify into it', function (): void {
    groupChatSbo(['name' => 'One']);
    groupChatSbo(['name' => 'Two']);
    groupChatSbo(['name' => 'Three']);

    expect(
        SboConversation::where('type', SboConversation::TYPE_INDUSTRY)
            ->where('industry', 'Software Development')
            ->count()
    )->toBe(1);
});

test('the backfill puts already-verified businesses into their rooms', function (): void {
    $sbo = groupChatSbo();

    // As if the rooms had not existed when this business was verified.
    $sbo->conversations()->detach();
    SboConversation::where('type', SboConversation::TYPE_INDUSTRY)->delete();

    expect(categoryRoom('Software Development'))->toBeNull();

    IndustryGroupChat::backfillAll();

    expect(categoryRoom('Software Development')?->participants()->pluck('sbos.id'))->toContain($sbo->id);
});

/* ------------------------------------------------------- chat header --- */

test('the star is a real per-viewer bookmark', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    groupChatSbo(['owner' => $owner, 'name' => 'Star Member']);
    $room = categoryRoom('Software Development');

    $this->actingAs($owner)
        ->post(route('sbo.conversations.star', $room))
        ->assertRedirect();

    $response = $this->actingAs($owner)
        ->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertOk();

    expect(collect(inertiaProp($response, 'conversations'))->firstWhere('group', true)['starred'])
        ->toBeTrue();

    // Somebody else's inbox is unaffected — a star is a personal bookmark.
    $otherOwner = User::factory()->create(['email_verified_at' => now()]);
    groupChatSbo(['owner' => $otherOwner, 'name' => 'Other Member']);

    $theirs = $this->actingAs($otherOwner)
        ->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertOk();

    expect(collect(inertiaProp($theirs, 'conversations'))->firstWhere('group', true)['starred'])
        ->toBeFalse();

    $this->actingAs($owner)->delete(route('sbo.conversations.unstar', $room))->assertRedirect();

    $after = $this->actingAs($owner)->get(route('sbo.network.index', ['tab' => 'messages']));
    expect(collect(inertiaProp($after, 'conversations'))->firstWhere('group', true)['starred'])
        ->toBeFalse();
});

test('a conversation you cannot read is one you cannot star', function (): void {
    groupChatSbo(['name' => 'Tech Member']);
    $room = categoryRoom('Software Development');

    $outsider = User::factory()->create(['email_verified_at' => now()]);
    groupChatSbo(['owner' => $outsider, 'name' => 'Baker', 'category' => 'Restaurants & Cafés']);

    $this->actingAs($outsider)->post(route('sbo.conversations.star', $room))->assertForbidden();
});
