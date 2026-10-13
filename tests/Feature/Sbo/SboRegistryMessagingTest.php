<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\User;
use App\Support\RegistrySbo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * A member and the registry, privately.
 *
 * The chat is SBO-to-SBO throughout — conversations, participants,
 * attachments, notifications and the broadcast channel all key on an SBO — so
 * the registry is given an SBO to be rather than teaching every one of those
 * about a second kind of party.
 *
 * One thread per member whichever end starts it: a room's "Message admin" and
 * an administrator reaching out land in the same conversation, or neither side
 * holds the record.
 */

function registryAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);
}

test('the registry listing exists and is kept out of public lists', function (): void {
    // Created by the adoption migration, so it is already there — every room
    // needs an owner from the moment the schema supports one.
    $registry = RegistrySbo::ensure();

    expect($registry->is_registry)->toBeTrue()
        /*
         * The directory, the categories page and the home page's featured row
         * all filter on `approved`, so not being approved is what keeps this
         * listing out of every one of them without a single query having to
         * learn the flag exists.
         */
        ->and($registry->verification_status)->not->toBe('approved');

    $this->get('/')
        ->assertOk()
        ->assertInertia(function ($page) use ($registry): void {
            $ids = collect($page->toArray()['props']['featured'])->pluck('id');

            expect($ids)->not->toContain($registry->id);
        });
});

test('asking twice does not make a second registry', function (): void {
    $first = RegistrySbo::ensure();
    $second = RegistrySbo::ensure();

    expect($second->id)->toBe($first->id)
        ->and(Sbo::query()->where('is_registry', true)->count())->toBe(1);
});

test('a member opens one thread with the registry', function (): void {
    $member = paidMember([], ['name' => 'Member Ltd']);

    $this->actingAs($member)
        ->post(route('sbo.conversations.admin'))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $conversation = SboConversation::query()->firstOrFail();
    $ids = $conversation->participants()->pluck('sbos.id');

    expect($ids)->toContain(RegistrySbo::current()->id)
        ->and($ids)->toContain($member->sbos()->first()->id)
        ->and($ids)->toHaveCount(2);
});

test('asking again reopens it rather than opening another', function (): void {
    $member = paidMember();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($member)->post(route('sbo.conversations.admin'));
    }

    expect(SboConversation::query()->count())->toBe(1);
});

test('the admin reaching out lands in the same thread the member would', function (): void {
    $member = paidMember([], ['name' => 'Member Ltd']);
    $sboId = $member->sbos()->first()->id;

    // The member writes first.
    $this->actingAs($member)->post(route('sbo.conversations.admin'));

    // Then an administrator starts one from the backend. One relationship, so
    // one thread — otherwise each side holds half the history.
    Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->call('startWith', $sboId);

    expect(SboConversation::query()->count())->toBe(1);
});

test('an admin reply is sent as the registry, not as the person typing', function (): void {
    $member = paidMember([], ['name' => 'Member Ltd']);
    $sboId = $member->sbos()->first()->id;
    $admin = registryAdmin();

    Volt::actingAs($admin)
        ->test('admin.messages.index')
        ->call('startWith', $sboId)
        ->set('body', 'We have your documents.')
        ->call('send')
        ->assertHasNoErrors();

    $message = SboMessage::query()->firstOrFail();

    // A member is writing to the registry, and staff change — so the listing
    // speaks, while user_id keeps who actually typed it for the audit trail.
    expect($message->sbo_id)->toBe(RegistrySbo::current()->id)
        ->and((string) $message->user_id)->toBe((string) $admin->id);
});

test('the member sees the reply in their own workspace', function (): void {
    $member = paidMember([], ['name' => 'Member Ltd']);
    $sboId = $member->sbos()->first()->id;

    Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->call('startWith', $sboId)
        ->set('body', 'We have your documents.')
        ->call('send');

    $conversation = SboConversation::query()->firstOrFail();

    $this->actingAs($member)
        ->get(route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('thread.0.body', 'We have your documents.')
            ->where('thread.0.author', RegistrySbo::current()->name)
            // Not the member's own message.
            ->where('thread.0.mine', false));
});

test('the member can write back into it', function (): void {
    $member = paidMember();

    $this->actingAs($member)->post(route('sbo.conversations.admin'));
    $conversation = SboConversation::query()->firstOrFail();

    // No connection row behind this thread, which is the case participantSboId
    // exists to answer.
    $this->actingAs($member)
        ->post(route('sbo.messages.store', $conversation), ['body' => 'Here they are.'])
        ->assertSessionHasNoErrors();

    expect(SboMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(1);
});

test('a member with no listing has no side to write from', function (): void {
    $user = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('sbo.conversations.admin'))
        ->assertForbidden();

    expect(SboConversation::query()->count())->toBe(0);
});

test('a guest cannot open one', function (): void {
    $this->post(route('sbo.conversations.admin'))->assertRedirect(route('login'));
});

test('the admin screen lists the threads the registry is in', function (): void {
    $member = paidMember([], ['name' => 'Findable Ltd']);

    $this->actingAs($member)->post(route('sbo.conversations.admin'));

    Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->assertSee('Findable Ltd');
});

test('the registry cannot be made to write to itself', function (): void {
    $registry = RegistrySbo::ensure();

    Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->call('startWith', $registry->id)
        ->assertStatus(422);
});

/*
 * The per-message actions on the inbox — the same set the category groups
 * screen offers, sharing App\Concerns\ModeratesSboMessages.
 */

function inboxThreadWithMemberMessage(): array
{
    $member = paidMember([], ['name' => 'Member Ltd']);
    $sbo = $member->sbos()->first();
    $registry = RegistrySbo::ensure();

    $conversation = SboConversation::create([
        'created_by_sbo_id' => $registry->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);
    $conversation->participants()->syncWithoutDetaching([$registry->id, $sbo->id]);

    $message = SboMessage::create([
        'conversation_id' => $conversation->id,
        'sbo_id' => $sbo->id,
        'user_id' => $member->id,
        'body' => 'A question from the member.',
    ]);

    return [$conversation, $message, $sbo];
}

test('the admin can react to and pin an inbox message', function (): void {
    [$conversation, $message] = inboxThreadWithMemberMessage();
    $admin = registryAdmin();

    $component = Volt::actingAs($admin)
        ->test('admin.messages.index')
        ->call('select', $conversation->id);

    $component->call('react', $message->id, '👍')->assertOk();
    expect(App\Models\SboMessageReaction::where('message_id', $message->id)
        ->where('emoji', '👍')->where('user_id', $admin->id)->exists())->toBeTrue();

    $component->call('pinMessage', $message->id)->assertOk();
    expect($message->fresh()->pinned_at)->not->toBeNull();
});

test('replying quotes the message in the thread', function (): void {
    [$conversation, $message] = inboxThreadWithMemberMessage();

    Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->call('select', $conversation->id)
        ->call('startReplyTo', $message->id)
        ->assertSet('replyingToId', $message->id)
        ->set('body', 'Here is the answer.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('replyingToId', null);

    $reply = SboMessage::where('conversation_id', $conversation->id)
        ->where('body', 'Here is the answer.')->firstOrFail();

    expect($reply->reply_to_message_id)->toBe($message->id);
});

test('replying privately opens the direct thread with the author', function (): void {
    // The seed thread is already direct, so a private reply lands in the same
    // one; assert the screen switches its selection to it.
    [$conversation, $message, $sbo] = inboxThreadWithMemberMessage();

    $registry = RegistrySbo::ensure();

    Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->call('startPrivateReply', $message->id)
        ->assertOk();

    $direct = SboConversation::where('type', SboConversation::TYPE_DIRECT)
        ->whereHas('participants', fn ($q) => $q->whereKey($registry->id))
        ->whereHas('participants', fn ($q) => $q->whereKey($sbo->id))
        ->firstOrFail();

    expect($direct->id)->toBe($conversation->id);
});

test('the inbox listens on the open thread channel for live messages', function (): void {
    [$conversation] = inboxThreadWithMemberMessage();

    $component = Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->call('select', $conversation->id);

    // The channel and event must match SboMessageSent exactly, or realtime
    // silently does nothing and the admin is back to refreshing.
    expect($component->instance()->getListeners())
        ->toHaveKey("echo-private:sbo.conversation.{$conversation->id},.message.sent");
});

test('a message notification points an admin to the backend inbox, a member to the workspace', function (): void {
    [, $message] = inboxThreadWithMemberMessage();

    $notification = new App\Notifications\SboMessageReceivedNotification($message);

    $admin = registryAdmin();
    $member = paidMember()->fresh();

    $adminUrl = $notification->toArray($admin)['url'];
    $memberUrl = $notification->toArray($member)['url'];

    // The administrator's copy opens the admin dashboard inbox on this thread.
    expect($adminUrl)->toContain('admin/messages')
        ->and($adminUrl)->toContain('conversation=' . $message->conversation_id)
        // The member's copy stays in the member workspace.
        ->and($memberUrl)->toContain('network')
        ->and($memberUrl)->not->toContain('admin/messages');
});

test('the admin inbox opens the thread a notification deep-links to', function (): void {
    [$conversation, $message] = inboxThreadWithMemberMessage();

    $this->actingAs(registryAdmin())
        ->get(route('admin.messages.index', ['conversation' => $conversation->id]))
        ->assertOk()
        ->assertSee('A question from the member.');
});

test('reporting a message goes through the modal and records the details', function (): void {
    [$conversation, $message] = inboxThreadWithMemberMessage();
    $registry = RegistrySbo::ensure();

    Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->call('select', $conversation->id)
        ->call('openReport', $message->id)
        ->assertSet('reportingId', $message->id)
        ->set('reportReason', 'spam')
        ->set('reportDetails', 'Posting the same link repeatedly.')
        ->call('submitReport')
        ->assertHasNoErrors()
        ->assertSet('reportingId', null);

    $report = App\Models\SboNetworkReport::where('message_id', $message->id)
        ->where('reporter_sbo_id', $registry->id)->firstOrFail();

    expect($report->status)->toBe('open')
        ->and($report->details)->toBe('Posting the same link repeatedly.');
});

test('the report modal requires a reason', function (): void {
    [$conversation, $message] = inboxThreadWithMemberMessage();

    Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->call('select', $conversation->id)
        ->call('openReport', $message->id)
        ->call('submitReport')
        ->assertHasErrors(['reportReason']);

    expect(App\Models\SboNetworkReport::where('message_id', $message->id)->exists())->toBeFalse();
});

test('the admin can send a message with an attachment', function (): void {
    Illuminate\Support\Facades\Storage::fake('chat');
    [$conversation] = inboxThreadWithMemberMessage();

    Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->call('select', $conversation->id)
        ->set('body', 'The document you asked for.')
        ->set('files', [Illuminate\Http\Testing\File::create('receipt.pdf', 10, 'application/pdf')])
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('files', []);

    $registry = RegistrySbo::ensure();
    $message = SboMessage::where('conversation_id', $conversation->id)
        ->where('sbo_id', $registry->id)->latest('id')->firstOrFail();

    expect($message->attachments()->count())->toBe(1)
        ->and($message->attachments()->first()->original_name)->toBe('receipt.pdf');
});

test('an attachment alone, with no text, is allowed', function (): void {
    Illuminate\Support\Facades\Storage::fake('chat');
    [$conversation] = inboxThreadWithMemberMessage();

    Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->call('select', $conversation->id)
        ->set('files', [Illuminate\Http\Testing\File::create('scan.png', 10, 'image/png')])
        ->call('send')
        ->assertHasNoErrors();

    $registry = RegistrySbo::ensure();
    expect(SboMessage::where('conversation_id', $conversation->id)
        ->where('sbo_id', $registry->id)->whereHas('attachments')->exists())->toBeTrue();
});

test('the admin can delete an inbox message, and it is logged', function (): void {
    [$conversation, $message] = inboxThreadWithMemberMessage();

    Volt::actingAs(registryAdmin())
        ->test('admin.messages.index')
        ->call('select', $conversation->id)
        ->call('deleteMessage', $message->id)
        ->assertOk();

    expect(SboMessage::find($message->id))->toBeNull()
        ->and(App\Models\ActivityLog::where('type', 'network_message_deleted')->exists())->toBeTrue();
});
