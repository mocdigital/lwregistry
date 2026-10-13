<?php

declare(strict_types=1);

use App\Models\SboConnection;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\SboMessageReaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('chat');
});

/*
 * Replying, reacting, and the marks a message can carry.
 *
 * A chat where every message is a flat statement cannot hold a conversation
 * with more than two people in it: by the time you answer, the thing you are
 * answering has scrolled away.
 *
 * Replies are a QUOTE, not a tree — every reply points at exactly one message,
 * so replying to a reply quotes the reply. That is what the chains people
 * actually write need, and it avoids indenting a column that is already narrow.
 */

/** @return array{0: App\Models\User, 1: App\Models\User, 2: SboConversation} */
function actionsPair(): array
{
    $aUser = paidMember([], ['name' => 'Alpha Actions Ltd']);
    $bUser = paidMember([], ['name' => 'Beta Actions Ltd']);

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

    return [$aUser, $bUser, $conversation];
}

function actionsMessage(SboConversation $c, App\Models\User $author, string $body = 'The original.'): SboMessage
{
    return SboMessage::create([
        'conversation_id' => $c->id,
        'sbo_id' => $author->sbos()->first()->id,
        'user_id' => $author->id,
        'body' => $body,
    ]);
}

/* ------------------------------------------------------------- replies --- */

test('a reply points at the message it answers', function (): void {
    [$aUser, $bUser, $conversation] = actionsPair();
    $original = actionsMessage($conversation, $aUser);

    $this->actingAs($bUser)
        ->post(route('sbo.messages.store', $conversation), [
            'body' => 'Answering that.',
            'reply_to_message_id' => $original->id,
        ])
        ->assertSessionHasNoErrors();

    $reply = SboMessage::query()->latest('id')->firstOrFail();

    expect($reply->reply_to_message_id)->toBe($original->id);
});

test('a reply to a reply quotes the reply', function (): void {
    [$aUser, $bUser, $conversation] = actionsPair();
    $original = actionsMessage($conversation, $aUser);

    $this->actingAs($bUser)->post(route('sbo.messages.store', $conversation), [
        'body' => 'First answer.',
        'reply_to_message_id' => $original->id,
    ]);

    $first = SboMessage::query()->latest('id')->firstOrFail();

    $this->actingAs($aUser)->post(route('sbo.messages.store', $conversation), [
        'body' => 'Answer to the answer.',
        'reply_to_message_id' => $first->id,
    ]);

    $second = SboMessage::query()->latest('id')->firstOrFail();

    // One level, always. The chain is as long as people make it; what is
    // RENDERED is the one message being answered.
    expect($second->reply_to_message_id)->toBe($first->id);
});

test('a reply cannot quote a message from another conversation', function (): void {
    [$aUser, , $conversation] = actionsPair();
    [$otherUser, , $otherConversation] = actionsPair();

    $elsewhere = actionsMessage($otherConversation, $otherUser, 'Not for you.');

    /*
     * The quote is rendered to everyone in the target conversation, so without
     * this a reply could republish a line from a thread the sender cannot even
     * read.
     */
    $this->actingAs($aUser)
        ->post(route('sbo.messages.store', $conversation), [
            'body' => 'Quoting something I should not have.',
            'reply_to_message_id' => $elsewhere->id,
        ])
        ->assertSessionHasErrors('reply_to_message_id');
});

test('the thread carries the quote to the screen', function (): void {
    [$aUser, $bUser, $conversation] = actionsPair();
    $original = actionsMessage($conversation, $aUser, 'The original.');

    $this->actingAs($bUser)->post(route('sbo.messages.store', $conversation), [
        'body' => 'Answering that.',
        'reply_to_message_id' => $original->id,
    ]);

    $this->actingAs($bUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('thread.1.replyTo.body', 'The original.')
            ->where('thread.1.replyTo.author', 'Alpha Actions Ltd')
            ->where('thread.0.replyTo', null));
});

/* ----------------------------------------------------------- reactions --- */

test('the same emoji twice is a toggle', function (): void {
    [$aUser, $bUser, $conversation] = actionsPair();
    $message = actionsMessage($conversation, $aUser);

    $this->actingAs($bUser)->post(route('sbo.messages.react', $message), ['emoji' => '👍']);
    expect(SboMessageReaction::query()->count())->toBe(1);

    // Reacting is a light gesture and taking it back should be equally light.
    $this->actingAs($bUser)->post(route('sbo.messages.react', $message), ['emoji' => '👍']);
    expect(SboMessageReaction::query()->count())->toBe(0);
});

test('two people reacting is two reactions', function (): void {
    [$aUser, $bUser, $conversation] = actionsPair();
    $message = actionsMessage($conversation, $aUser);

    $this->actingAs($aUser)->post(route('sbo.messages.react', $message), ['emoji' => '👍']);
    $this->actingAs($bUser)->post(route('sbo.messages.react', $message), ['emoji' => '👍']);

    expect(SboMessageReaction::query()->count())->toBe(2);
});

test('an emoji outside the set is refused', function (): void {
    [$aUser, $bUser, $conversation] = actionsPair();
    $message = actionsMessage($conversation, $aUser);

    // The column would take anything; an open field is a place to paste a
    // paragraph.
    $this->actingAs($bUser)
        ->post(route('sbo.messages.react', $message), ['emoji' => 'not an emoji at all'])
        ->assertSessionHasErrors('emoji');
});

test('reactions reach the screen grouped, counted, and marked as mine', function (): void {
    [$aUser, $bUser, $conversation] = actionsPair();
    $message = actionsMessage($conversation, $aUser);

    $this->actingAs($aUser)->post(route('sbo.messages.react', $message), ['emoji' => '👍']);
    $this->actingAs($bUser)->post(route('sbo.messages.react', $message), ['emoji' => '👍']);
    $this->actingAs($bUser)->post(route('sbo.messages.react', $message), ['emoji' => '❤️']);

    $this->actingAs($bUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id]))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $reactions = collect($page->toArray()['props']['thread'][0]['reactions']);
            $thumbs = $reactions->firstWhere('emoji', '👍');

            // `mine` is what lets one control both add and remove.
            expect($thumbs['count'])->toBe(2)
                ->and($thumbs['mine'])->toBeTrue()
                ->and($reactions)->toHaveCount(2);
        });
});

test('someone outside the conversation cannot react to it', function (): void {
    [$aUser, , $conversation] = actionsPair();
    $message = actionsMessage($conversation, $aUser);

    $this->actingAs(paidMember())
        ->post(route('sbo.messages.react', $message), ['emoji' => '👍'])
        ->assertForbidden();
});

/* ----------------------------------------------------------------- pin --- */

test('pinning something else moves the pin rather than adding to it', function (): void {
    [$aUser, $bUser, $conversation] = actionsPair();
    $first = actionsMessage($conversation, $aUser, 'First.');
    $second = actionsMessage($conversation, $aUser, 'Second.');

    $this->actingAs($bUser)->post(route('sbo.messages.pin', $first));
    $this->actingAs($bUser)->post(route('sbo.messages.pin', $second));

    // A list of pinned messages is a second thread to read; the point of
    // pinning is to say "this one".
    expect(SboMessage::query()->whereNotNull('pinned_at')->count())->toBe(1)
        ->and($second->fresh()->pinned_at)->not->toBeNull()
        ->and($first->fresh()->pinned_at)->toBeNull();
});

test('a pin is visible to everyone in the conversation', function (): void {
    [$aUser, $bUser, $conversation] = actionsPair();
    $message = actionsMessage($conversation, $aUser);

    $this->actingAs($bUser)->post(route('sbo.messages.pin', $message));

    $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id]))
        ->assertInertia(fn ($page) => $page->where('thread.0.pinned', true));
});

/* -------------------------------------------------------------- delete --- */

test('deleting leaves a tombstone, not a hole', function (): void {
    [$aUser, $bUser, $conversation] = actionsPair();
    $message = actionsMessage($conversation, $aUser, 'Said in haste.');

    $this->actingAs($bUser)->post(route('sbo.messages.store', $conversation), [
        'body' => 'Quoting it.',
        'reply_to_message_id' => $message->id,
    ]);

    $this->actingAs($aUser)
        ->delete(route('sbo.messages.destroy', $message))
        ->assertSessionHasNoErrors();

    $message->refresh();

    expect($message->isDeleted())->toBeTrue()
        ->and($message->body)->toBe('');

    // The reply still has something to quote, and the thread keeps its shape.
    $this->actingAs($bUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('thread.0.deleted', true)
            ->where('thread.1.replyTo.body', __('chat.message_deleted')));
});

test('deleting a message takes its files with it', function (): void {
    [$aUser, , $conversation] = actionsPair();

    $this->actingAs($aUser)->post(route('sbo.messages.store', $conversation), [
        'body' => 'With a file.',
        'attachments' => [UploadedFile::fake()->image('goods.jpg')],
    ]);

    $message = SboMessage::query()->latest('id')->firstOrFail();

    $this->actingAs($aUser)->delete(route('sbo.messages.destroy', $message));

    // A deleted message that still serves its attachment has not been deleted.
    expect($message->attachments()->count())->toBe(0);
});

test('you can only delete your own', function (): void {
    [$aUser, $bUser, $conversation] = actionsPair();
    $message = actionsMessage($conversation, $aUser);

    $this->actingAs($bUser)
        ->delete(route('sbo.messages.destroy', $message))
        ->assertForbidden();

    expect($message->fresh()->isDeleted())->toBeFalse();
});
