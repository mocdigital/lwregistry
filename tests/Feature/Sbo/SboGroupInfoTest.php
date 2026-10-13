<?php

declare(strict_types=1);

use App\Models\SboConversation;
use App\Models\SboMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('chat');
});

/*
 * The Group Info panel.
 *
 * Who is in the room, and what has been shared in it. The media, files and
 * links are read back out of the conversation rather than tracked separately:
 * a second index of the same thing is a second thing to keep correct, and it
 * would be wrong the first time somebody deleted a message.
 *
 * There is deliberately no "N online" — nothing in the app tracks presence,
 * and a number that is always wrong is worse than no number.
 */

/** @return array{0: App\Models\User, 1: SboConversation} */
function infoRoom(): array
{
    $owner = paidMember([], ['name' => 'Info Member Ltd']);

    $room = SboConversation::create([
        'created_by_sbo_id' => $owner->sbos()->first()->id,
        'type' => SboConversation::TYPE_INDUSTRY,
        'industry' => 'Technology Services',
        'last_message_at' => now(),
    ]);

    $room->participants()->syncWithoutDetaching([$owner->sbos()->first()->id]);

    return [$owner, $room];
}

function openInfo(App\Models\User $user, SboConversation $room)
{
    return test()->actingAs($user)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $room->id]))
        ->assertOk();
}

test('the panel names the room and counts the businesses in it', function (): void {
    [$owner, $room] = infoRoom();

    openInfo($owner, $room)->assertInertia(fn ($page) => $page
        ->where('groupInfo.name', 'Technology Services Group')
        ->where('groupInfo.group', true)
        // Businesses, not staff: the registry sits in every room as its admin,
        // and this number tells a member how many peers are with them.
        ->where('groupInfo.members', 1));
});

test('media, files and links are read back out of the conversation', function (): void {
    [$owner, $room] = infoRoom();

    $this->actingAs($owner)->post(route('sbo.messages.store', $room), [
        'body' => 'The brief is at https://example.com/brief and the drawing is attached.',
        'attachments' => [
            UploadedFile::fake()->image('drawing.jpg'),
            UploadedFile::fake()->create('terms.pdf', 20, 'application/pdf'),
        ],
    ]);

    openInfo($owner, $room)->assertInertia(fn ($page) => $page
        ->where('groupInfo.media.0.name', 'drawing.jpg')
        ->where('groupInfo.files.0.name', 'terms.pdf')
        ->where('groupInfo.links.0.url', 'https://example.com/brief')
        ->where('groupInfo.links.0.author', 'Info Member Ltd'));
});

test('a trailing full stop is the sentence, not the link', function (): void {
    [$owner, $room] = infoRoom();

    $this->actingAs($owner)->post(route('sbo.messages.store', $room), [
        'body' => 'See https://example.com/terms.',
    ]);

    openInfo($owner, $room)->assertInertia(fn ($page) => $page
        ->where('groupInfo.links.0.url', 'https://example.com/terms'));
});

test('the same link posted twice is listed once', function (): void {
    [$owner, $room] = infoRoom();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($owner)->post(route('sbo.messages.store', $room), [
            'body' => 'Again: https://example.com/brief',
        ]);
    }

    openInfo($owner, $room)->assertInertia(function ($page): void {
        expect($page->toArray()['props']['groupInfo']['links'])->toHaveCount(1);
    });
});

test('a deleted message takes its links out of the panel', function (): void {
    [$owner, $room] = infoRoom();

    $this->actingAs($owner)->post(route('sbo.messages.store', $room), [
        'body' => 'Ignore this: https://example.com/wrong',
    ]);

    $message = SboMessage::query()->latest('id')->firstOrFail();
    $this->actingAs($owner)->delete(route('sbo.messages.destroy', $message));

    // This is the reason the panel reads the conversation rather than keeping
    // its own index: an index would still be advertising the link.
    openInfo($owner, $room)->assertInertia(function ($page): void {
        expect($page->toArray()['props']['groupInfo']['links'])->toBe([]);
    });
});

/* --------------------------------------------------------------- mute --- */

test('muting is a toggle, and it is one member\'s own decision', function (): void {
    [$owner, $room] = infoRoom();

    openInfo($owner, $room)->assertInertia(fn ($page) => $page->where('groupInfo.muted', false));

    $this->actingAs($owner)->post(route('sbo.conversations.mute', $room));
    openInfo($owner, $room)->assertInertia(fn ($page) => $page->where('groupInfo.muted', true));

    $this->actingAs($owner)->post(route('sbo.conversations.mute', $room));
    openInfo($owner, $room)->assertInertia(fn ($page) => $page->where('groupInfo.muted', false));
});

test('a muted member is not notified but still receives the message', function (): void {
    Notification::fake();

    [$owner, $room] = infoRoom();
    $other = paidMember([], ['name' => 'Noisy Ltd']);
    $room->participants()->syncWithoutDetaching([$other->sbos()->first()->id]);

    $this->actingAs($owner)->post(route('sbo.conversations.mute', $room));

    $this->actingAs($other)->post(route('sbo.messages.store', $room), ['body' => 'Anyone there?']);

    // Muting silences the bell; it does not leave the room.
    Notification::assertNotSentTo($owner, App\Notifications\SboMessageReceivedNotification::class);

    $this->actingAs($owner)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $room->id]))
        ->assertInertia(fn ($page) => $page->where('thread.0.body', 'Anyone there?'));
});

test('an unmuted member is still notified', function (): void {
    Notification::fake();

    [$owner, $room] = infoRoom();
    $other = paidMember([], ['name' => 'Noisy Ltd']);
    $room->participants()->syncWithoutDetaching([$other->sbos()->first()->id]);

    $this->actingAs($other)->post(route('sbo.messages.store', $room), ['body' => 'Anyone there?']);

    Notification::assertSentTo($owner, App\Notifications\SboMessageReceivedNotification::class);
});

test('someone outside the conversation cannot mute it', function (): void {
    [, $room] = infoRoom();

    $this->actingAs(paidMember())
        ->post(route('sbo.conversations.mute', $room))
        ->assertForbidden();
});
