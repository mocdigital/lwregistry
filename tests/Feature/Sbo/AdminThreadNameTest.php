<?php

declare(strict_types=1);

use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Support\RegistrySbo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The registry thread has no connection behind it, so the inbox used to name it
 * after the last message's sender. On the member's own first message that was
 * the member — so "message admin" opened a thread labelled with the member's
 * own name instead of the registry's.
 */

test('the admin thread is named after the registry, even on the members own first message', function (): void {
    $member = paidMember([], ['name' => 'Radiant Logistics']);
    $registry = RegistrySbo::ensure();
    $mySbo = $member->sbos()->first();

    // The member↔registry direct thread, with the member's first message the
    // only one in it.
    $conversation = SboConversation::create([
        'created_by_sbo_id' => $mySbo->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);
    $conversation->participants()->syncWithoutDetaching([$mySbo->id, $registry->id]);

    SboMessage::create([
        'conversation_id' => $conversation->id,
        'sbo_id' => $mySbo->id,
        'user_id' => $member->id,
        'body' => 'Hello admin, I need help.',
    ]);

    $this->actingAs($member)
        ->get('/sbo/network/messages?conversation=' . $conversation->id)
        ->assertOk()
        ->assertInertia(function ($page) use ($conversation, $registry, $mySbo) {
            $row = collect($page->toArray()['props']['conversations'])
                ->firstWhere('id', (string) $conversation->id);

            expect($row)->not->toBeNull()
                ->and($row['name'])->toBe($registry->name)
                ->and($row['name'])->not->toBe($mySbo->name);
        });
});
