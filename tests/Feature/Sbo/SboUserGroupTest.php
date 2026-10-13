<?php

declare(strict_types=1);

use App\Models\SboConnection;
use App\Models\SboConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Member-created group chats: a member names a group and seeds it with their
 * accepted connections, then manages the roster as its owner.
 */

function connectSbos(int $a, int $b): void
{
    SboConnection::create([
        'requester_sbo_id' => $a,
        'recipient_sbo_id' => $b,
        'status' => SboConnection::STATUS_ACCEPTED,
        'responded_at' => now(),
    ]);
}

/** @return array{0: User, 1: User, 2: User, 3: User} owner + two connections + a stranger */
function groupCast(): array
{
    $owner = paidMember([], ['name' => 'Owner Ltd']);
    $one = paidMember([], ['name' => 'Connection One']);
    $two = paidMember([], ['name' => 'Connection Two']);
    $stranger = paidMember([], ['name' => 'Stranger Ltd']);

    connectSbos($owner->sbos()->first()->id, $one->sbos()->first()->id);
    connectSbos($two->sbos()->first()->id, $owner->sbos()->first()->id);

    return [$owner, $one, $two, $stranger];
}

test('a member creates a group from their connections', function (): void {
    [$owner, $one, $two] = groupCast();

    $this->actingAs($owner)->post(route('sbo.groups.store'), [
        'name' => 'Trusted Suppliers',
        'member_sbo_ids' => [$one->sbos()->first()->id, $two->sbos()->first()->id],
    ])->assertRedirect();

    $group = SboConversation::where('type', SboConversation::TYPE_GROUP)->firstOrFail();

    expect($group->name)->toBe('Trusted Suppliers')
        ->and($group->created_by_sbo_id)->toBe($owner->sbos()->first()->id)
        ->and($group->participants()->pluck('sbos.id')->sort()->values()->all())
        ->toBe(collect([
            $owner->sbos()->first()->id,
            $one->sbos()->first()->id,
            $two->sbos()->first()->id,
        ])->sort()->values()->all());
});

test('a stranger who is not a connection cannot be seeded into a group', function (): void {
    [$owner, $one, , $stranger] = groupCast();

    $this->actingAs($owner)->post(route('sbo.groups.store'), [
        'name' => 'Mixed',
        'member_sbo_ids' => [$one->sbos()->first()->id, $stranger->sbos()->first()->id],
    ])->assertRedirect();

    $group = SboConversation::where('type', SboConversation::TYPE_GROUP)->firstOrFail();

    // The stranger is dropped; the connection stays.
    expect($group->participants()->whereKey($stranger->sbos()->first()->id)->exists())->toBeFalse()
        ->and($group->participants()->whereKey($one->sbos()->first()->id)->exists())->toBeTrue();
});

test('a group with no valid connections is refused', function (): void {
    [$owner, , , $stranger] = groupCast();

    $this->actingAs($owner)->post(route('sbo.groups.store'), [
        'name' => 'Nobody',
        'member_sbo_ids' => [$stranger->sbos()->first()->id],
    ])->assertStatus(422);

    expect(SboConversation::where('type', SboConversation::TYPE_GROUP)->exists())->toBeFalse();
});

test('the owner adds and removes members; others cannot', function (): void {
    [$owner, $one, $two] = groupCast();

    $this->actingAs($owner)->post(route('sbo.groups.store'), [
        'name' => 'Group',
        'member_sbo_ids' => [$one->sbos()->first()->id],
    ]);
    $group = SboConversation::where('type', SboConversation::TYPE_GROUP)->firstOrFail();

    // A member (not the owner) cannot manage the roster.
    $this->actingAs($one)->post(route('sbo.groups.members.add', $group), [
        'member_sbo_ids' => [$two->sbos()->first()->id],
    ])->assertForbidden();

    // The owner can add another connection.
    $this->actingAs($owner)->post(route('sbo.groups.members.add', $group), [
        'member_sbo_ids' => [$two->sbos()->first()->id],
    ])->assertRedirect();
    expect($group->participants()->whereKey($two->sbos()->first()->id)->exists())->toBeTrue();

    // And remove a member.
    $this->actingAs($owner)->delete(route('sbo.groups.members.remove', $group), [
        'member_sbo_id' => $two->sbos()->first()->id,
    ])->assertRedirect();
    expect($group->participants()->whereKey($two->sbos()->first()->id)->exists())->toBeFalse();
});

test('a member can leave, but the owner cannot leave their own group', function (): void {
    [$owner, $one] = groupCast();

    $this->actingAs($owner)->post(route('sbo.groups.store'), [
        'name' => 'Group',
        'member_sbo_ids' => [$one->sbos()->first()->id],
    ]);
    $group = SboConversation::where('type', SboConversation::TYPE_GROUP)->firstOrFail();

    $this->actingAs($owner)->delete(route('sbo.groups.leave', $group))->assertStatus(422);
    expect($group->participants()->whereKey($owner->sbos()->first()->id)->exists())->toBeTrue();

    $this->actingAs($one)->delete(route('sbo.groups.leave', $group))->assertRedirect();
    expect($group->participants()->whereKey($one->sbos()->first()->id)->exists())->toBeFalse();
});

test('the group shows in the inbox as an owned group', function (): void {
    [$owner, $one] = groupCast();

    $this->actingAs($owner)->post(route('sbo.groups.store'), [
        'name' => 'My Group',
        'member_sbo_ids' => [$one->sbos()->first()->id],
    ]);
    $group = SboConversation::where('type', SboConversation::TYPE_GROUP)->firstOrFail();

    $this->actingAs($owner)->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertInertia(function ($page) use ($group): void {
            $row = collect($page->toArray()['props']['conversations'])->firstWhere('id', (string) $group->id);
            expect($row)->not->toBeNull()
                ->and($row['name'])->toBe('My Group')
                ->and($row['kind'])->toBe('group')
                ->and($row['isOwner'])->toBeTrue();
        });

    // The member sees it too, but not as the owner.
    $this->actingAs($one)->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertInertia(function ($page) use ($group): void {
            $row = collect($page->toArray()['props']['conversations'])->firstWhere('id', (string) $group->id);
            expect($row['kind'])->toBe('group')->and($row['isOwner'])->toBeFalse();
        });
});
