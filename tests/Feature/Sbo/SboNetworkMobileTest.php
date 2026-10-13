<?php

declare(strict_types=1);

use App\Models\SboConnection;
use App\Models\SboConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The messages screen on a phone.
 *
 * Both panes used to render stacked below lg, so the inbox pushed the open
 * conversation a full screen down — opening a thread meant scrolling past
 * every other one to reach it. One pane is on screen at a time now, the way
 * every phone messaging app works: the list is the screen until you pick a
 * conversation, then the conversation is.
 *
 * Which pane is showing is decided by `activeConversationId`, so it is the URL
 * that carries it — the browser's back button and a shared link both keep
 * working, and the server stops sending a thread nobody is reading.
 */

/** @return array{0: App\Models\User, 1: SboConversation} */
function mobileChat(): array
{
    $aUser = paidMember([], ['name' => 'Alpha Mobile Ltd']);
    $bUser = paidMember([], ['name' => 'Beta Mobile Ltd']);

    $connection = SboConnection::create([
        'requester_sbo_id' => $aUser->sbos()->first()->id,
        'recipient_sbo_id' => $bUser->sbos()->first()->id,
        'status' => SboConnection::STATUS_ACCEPTED,
        'responded_at' => now(),
    ]);

    $conversation = SboConversation::create([
        'connection_id' => $connection->id,
        'created_by_sbo_id' => $aUser->sbos()->first()->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);

    $conversation->participants()->syncWithoutDetaching([
        $aUser->sbos()->first()->id,
        $bUser->sbos()->first()->id,
    ]);

    return [$aUser, $conversation];
}

test('the inbox alone is the screen until a conversation is picked', function (): void {
    [$owner] = mobileChat();

    $this->actingAs($owner)
        ->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            /*
             * The server still opens the newest conversation, which is right
             * for a desktop pane that would otherwise be empty. What tells the
             * phone to show the list is that nobody ASKED for that thread.
             */
            ->where('conversationRequested', false)
            ->has('conversations'));
});

test('picking one puts it in the URL, so back works', function (): void {
    [$owner, $conversation] = mobileChat();

    $this->actingAs($owner)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activeConversationId', (string) $conversation->id)
            ->where('conversationRequested', true)
            ->has('roster')
            ->has('groupInfo'));
});

test('going back is a real visit, not a hidden local flag', function (): void {
    [$owner, $conversation] = mobileChat();

    // Open it, then go back the way the header's arrow does.
    $this->actingAs($owner)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id]))
        ->assertOk();

    $this->actingAs($owner)
        ->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertOk()
        // Back on the list: the thread is no longer what was asked for.
        ->assertInertia(fn ($page) => $page->where('conversationRequested', false));
});

test('opening a conversation still marks it read', function (): void {
    [$owner, $conversation] = mobileChat();

    // Somebody else's message, which is the only kind that can be unread.
    $incoming = App\Models\SboMessage::create([
        'conversation_id' => $conversation->id,
        'sbo_id' => $conversation->participants()->whereKeyNot($owner->sbos()->first()->id)->value('sbos.id'),
        'user_id' => App\Models\User::factory()->create()->id,
        'body' => 'Are you there?',
    ]);

    expect($incoming->read_at)->toBeNull();

    /*
     * The phone layout changes which pane is visible, not what opening one
     * means — a conversation you have looked at is read whichever width you
     * looked at it on.
     */
    $this->actingAs($owner)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id]))
        ->assertOk();

    expect($incoming->fresh()->read_at)->not->toBeNull();
});
