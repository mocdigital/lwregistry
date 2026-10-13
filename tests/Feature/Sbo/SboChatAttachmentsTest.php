<?php

declare(strict_types=1);

use App\Models\SboConnection;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\SboMessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('chat');
});

/*
 * Files in a chat.
 *
 * `sbo_messages` held a body and nothing else, so the only thing two
 * businesses could send each other was a paragraph — no quotation, no drawing,
 * no photograph of the goods.
 *
 * The bytes go to the private `chat` disk and are read back through a route
 * that checks conversation membership. A public disk would make every file in
 * every private conversation readable by anyone who guessed the path, and
 * paths are guessable in a way conversations are not.
 */

/** @return array{0: User, 1: User, 2: SboConversation} */
function chatPair(): array
{
    $aUser = paidMember([], ['name' => 'Alpha Chat Ltd']);
    $bUser = paidMember([], ['name' => 'Beta Chat Ltd']);

    $a = $aUser->sbos()->first();
    $b = $bUser->sbos()->first();

    $connection = SboConnection::create([
        'requester_sbo_id' => $a->id,
        'recipient_sbo_id' => $b->id,
        'status' => SboConnection::STATUS_ACCEPTED,
        'responded_at' => now(),
    ]);

    $conversation = SboConversation::create([
        'connection_id' => $connection->id,
        'created_by_sbo_id' => $a->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);

    $conversation->participants()->syncWithoutDetaching([$a->id, $b->id]);

    return [$aUser, $bUser, $conversation];
}

test('a photo can be sent with no words at all', function (): void {
    [$aUser, , $conversation] = chatPair();

    // A picture of the goods is a message. An empty send is not — which the
    // next test covers.
    $this->actingAs($aUser)
        ->post(route('sbo.messages.store', $conversation), [
            'attachments' => [UploadedFile::fake()->image('goods.jpg')],
        ])
        ->assertSessionHasNoErrors();

    $attachment = SboMessageAttachment::query()->firstOrFail();

    expect($attachment->kind)->toBe(SboMessageAttachment::KIND_IMAGE)
        ->and($attachment->original_name)->toBe('goods.jpg')
        ->and($attachment->disk)->toBe('chat');

    Storage::disk('chat')->assertExists($attachment->path);
});

test('an empty message with nothing attached is refused', function (): void {
    [$aUser, , $conversation] = chatPair();

    $this->actingAs($aUser)
        ->post(route('sbo.messages.store', $conversation), [])
        ->assertSessionHasErrors('body');

    expect(SboMessage::query()->count())->toBe(0);
});

test('documents and video are accepted, and each is labelled', function (): void {
    [$aUser, , $conversation] = chatPair();

    $this->actingAs($aUser)
        ->post(route('sbo.messages.store', $conversation), [
            'body' => 'The quotation and a walkthrough.',
            'attachments' => [
                UploadedFile::fake()->create('quote.pdf', 40, 'application/pdf'),
                UploadedFile::fake()->create('walkthrough.mp4', 200, 'video/mp4'),
            ],
        ])
        ->assertSessionHasNoErrors();

    // Several files on one message, so a quotation and the drawing it refers
    // to arrive together rather than as two entries in the thread.
    $kinds = SboMessageAttachment::query()->pluck('kind', 'original_name');

    expect($kinds['quote.pdf'])->toBe(SboMessageAttachment::KIND_DOCUMENT)
        ->and($kinds['walkthrough.mp4'])->toBe(SboMessageAttachment::KIND_VIDEO)
        ->and(SboMessage::query()->count())->toBe(1);
});

test('an svg is refused however it is named', function (): void {
    [$aUser, , $conversation] = chatPair();

    /*
     * SVG is markup, browsers execute the script inside it, and it would be
     * served from our own origin. The accepted list is closed for this reason
     * rather than testing for an "image/" prefix.
     */
    $this->actingAs($aUser)
        ->post(route('sbo.messages.store', $conversation), [
            'attachments' => [UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')],
        ])
        ->assertSessionHasErrors('attachments.0');

    expect(SboMessageAttachment::query()->count())->toBe(0);
});

test('a script wearing an image extension is refused', function (): void {
    [$aUser, , $conversation] = chatPair();

    // The name says png; the file is not one. `mimetypes:` reads the file.
    $this->actingAs($aUser)
        ->post(route('sbo.messages.store', $conversation), [
            'attachments' => [UploadedFile::fake()->create('avatar.png', 4, 'application/x-httpd-php')],
        ])
        ->assertSessionHasErrors('attachments.0');

    expect(SboMessageAttachment::query()->count())->toBe(0);
});

test('the sender name never becomes the stored path', function (): void {
    [$aUser, , $conversation] = chatPair();

    $this->actingAs($aUser)
        ->post(route('sbo.messages.store', $conversation), [
            'attachments' => [UploadedFile::fake()->image('../../../etc/passwd.jpg')],
        ]);

    $attachment = SboMessageAttachment::query()->firstOrFail();

    // Kept as a label, generated as a path. A filename is attacker-controlled
    // text; building a path from it is how one conversation's upload lands in
    // another's directory.
    expect($attachment->path)->not->toContain('..')
        ->and($attachment->path)->toStartWith('conversations/' . $conversation->id . '/');
});

/* ---------------------------------------------------------- reading it --- */

test('the other side can open the file', function (): void {
    [$aUser, $bUser, $conversation] = chatPair();

    $this->actingAs($aUser)->post(route('sbo.messages.store', $conversation), [
        'attachments' => [UploadedFile::fake()->image('goods.jpg')],
    ]);

    $attachment = SboMessageAttachment::query()->firstOrFail();

    $this->actingAs($bUser)
        ->get(route('sbo.message-attachments.show', $attachment))
        ->assertOk();
});

test('someone outside the conversation cannot', function (): void {
    [$aUser, , $conversation] = chatPair();

    $this->actingAs($aUser)->post(route('sbo.messages.store', $conversation), [
        'attachments' => [UploadedFile::fake()->image('goods.jpg')],
    ]);

    $attachment = SboMessageAttachment::query()->firstOrFail();

    // The same rule as reading the thread: a conversation you cannot read is
    // one whose files you cannot read either.
    $this->actingAs(paidMember())
        ->get(route('sbo.message-attachments.show', $attachment))
        ->assertForbidden();
});

test('a guest cannot open one at all', function (): void {
    [$aUser, , $conversation] = chatPair();

    /*
     * Written through the models rather than posted: `actingAs` holds for the
     * rest of the test, so a "guest" request made after one would still arrive
     * authenticated and the assertion would prove nothing.
     */
    $message = SboMessage::create([
        'conversation_id' => $conversation->id,
        'sbo_id' => $aUser->sbos()->first()->id,
        'user_id' => $aUser->id,
        'body' => 'Here it is.',
    ]);

    $attachment = SboMessageAttachment::create([
        'message_id' => $message->id,
        'kind' => SboMessageAttachment::KIND_IMAGE,
        'disk' => 'chat',
        'path' => 'conversations/' . $conversation->id . '/x.jpg',
        'original_name' => 'goods.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
    ]);

    $this->get(route('sbo.message-attachments.show', $attachment))
        ->assertRedirect(route('login'));
});

test('an uploaded file is never served as something the browser will run', function (): void {
    [$aUser, $bUser, $conversation] = chatPair();

    $this->actingAs($aUser)->post(route('sbo.messages.store', $conversation), [
        'attachments' => [UploadedFile::fake()->image('goods.jpg')],
    ]);

    $response = $this->actingAs($bUser)
        ->get(route('sbo.message-attachments.show', SboMessageAttachment::query()->firstOrFail()))
        ->assertOk();

    /*
     * These are files uploaded by one business and read by another, served
     * from our own origin. Without the CSP and nosniff, anything the browser
     * decides to treat as HTML runs as us.
     */
    expect($response->headers->get('content-security-policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

test('the thread carries its attachments to the screen', function (): void {
    [$aUser, , $conversation] = chatPair();

    $this->actingAs($aUser)->post(route('sbo.messages.store', $conversation), [
        'body' => 'Here it is.',
        'attachments' => [UploadedFile::fake()->image('goods.jpg')],
    ]);

    $this->actingAs($aUser)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('thread.0.attachments.0.kind', 'image')
            ->where('thread.0.attachments.0.name', 'goods.jpg')
            // A gated route, never a disk path.
            ->where(
                'thread.0.attachments.0.url',
                route('sbo.message-attachments.show', SboMessageAttachment::query()->firstOrFail()),
            ));
});
