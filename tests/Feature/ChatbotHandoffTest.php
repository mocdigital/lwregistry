<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ChatbotMessage;
use App\Models\ChatbotSession;
use App\Models\User;
use App\Support\Chatbot\AdminPresence;
use App\Support\Chatbot\DefaultBlueprint;
use App\Support\Chatbot\HumanHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function handoffAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);
}

/*
 * Handing a conversation from the bot to a person.
 *
 * "Talk to a person" has to mean a person: connect one if any are online, and
 * promise a follow-up rather than a silent wait if none are. Once handed over,
 * the visitor talks to the administrator, not the bot.
 */

function handoffSession(string $id = 'sess'): ChatbotSession
{
    $blueprint = DefaultBlueprint::install();

    return ChatbotSession::create([
        'session_id' => $id,
        'blueprint_id' => $blueprint->id,
        'data' => ['name' => 'Ada'],
    ]);
}

test('with nobody online, a handoff promises a follow-up rather than a wait', function (): void {
    $result = app(HumanHandoff::class)->request(handoffSession());

    expect($result['state'])->toBe(ChatbotSession::HANDOFF_MISSED)
        ->and($result['text'])->toContain('24 hours');

    // Left on record so support can actually follow up.
    $this->assertDatabaseHas('chatbot_sessions', [
        'session_id' => 'sess',
        'handoff_state' => ChatbotSession::HANDOFF_MISSED,
    ]);
});

test('with someone online, a handoff connects them', function (): void {
    AdminPresence::heartbeat(User::factory()->create()->id);

    $result = app(HumanHandoff::class)->request(handoffSession());

    expect($result['state'])->toBe(ChatbotSession::HANDOFF_REQUESTED);
    $this->assertDatabaseHas('chatbot_sessions', [
        'session_id' => 'sess',
        'handoff_state' => ChatbotSession::HANDOFF_REQUESTED,
    ]);
});

test('presence lapses, so a stale heartbeat does not count as online', function (): void {
    AdminPresence::heartbeat(User::factory()->create()->id);
    expect(AdminPresence::anyOnline())->toBeTrue();

    // Push the one heartbeat past the window.
    DB::table('chatbot_admin_presence')->update(['last_seen_at' => now()->subMinutes(5)]);
    expect(AdminPresence::anyOnline())->toBeFalse();
});

test('an administrator can take a conversation over and reply', function (): void {
    $admin = handoffAdmin();
    $session = handoffSession();
    $session->forceFill(['handoff_state' => ChatbotSession::HANDOFF_REQUESTED])->save();

    $this->actingAs($admin)
        ->postJson(route('admin.assistant.live.takeover', $session->session_id))
        ->assertOk();

    $this->assertDatabaseHas('chatbot_sessions', [
        'session_id' => $session->session_id,
        'handoff_state' => ChatbotSession::HANDOFF_LIVE,
        'admin_id' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->postJson(route('admin.assistant.live.send', $session->session_id), ['body' => 'Hi Ada, how can I help?'])
        ->assertOk();

    $this->assertDatabaseHas('chatbot_messages', [
        'session_id' => $session->session_id,
        'sender' => ChatbotMessage::SENDER_ADMIN,
        'body' => 'Hi Ada, how can I help?',
    ]);
});

test('the assistant learns from an admin reply and answers the next visitor', function (): void {
    $admin = handoffAdmin();
    $session = handoffSession();
    $session->forceFill(['handoff_state' => ChatbotSession::HANDOFF_LIVE, 'admin_id' => $admin->id])->save();
    $session->remember('last_question', 'Do you offer discounts for churches?');

    // The admin answers the question the bank could not.
    $this->actingAs($admin)
        ->postJson(route('admin.assistant.live.send', $session->session_id), [
            'body' => 'Yes — registered churches and ministries get 20% off the registration fee.',
        ])
        ->assertOk();

    // Captured as a pending lesson, paired with the question.
    $lesson = App\Models\ChatbotLesson::firstOrFail();
    expect($lesson->status)->toBe(App\Models\ChatbotLesson::STATUS_PENDING)
        ->and($lesson->question)->toContain('churches');

    // Approved, it is written into the bank...
    App\Support\Chatbot\LearningBank::approve($lesson);

    // ...and a different visitor asking the same thing is now answered by it.
    $answer = app(App\Support\Chatbot\KnowledgeBaseResponder::class)
        ->answer('is there a discount for churches?');

    expect($answer->answered)->toBeTrue()
        ->and($answer->text)->toContain('20%');
});

test('auto-learn writes an admin reply straight into the bank', function (): void {
    App\Models\ChatbotSetting::put(App\Models\ChatbotSetting::AUTO_LEARN, true);

    $admin = handoffAdmin();
    $session = handoffSession();
    $session->forceFill(['handoff_state' => ChatbotSession::HANDOFF_LIVE, 'admin_id' => $admin->id])->save();
    $session->remember('last_question', 'What are your support hours?');

    $this->actingAs($admin)
        ->postJson(route('admin.assistant.live.send', $session->session_id), [
            'body' => 'Support is available Monday to Friday, 9am to 5pm West Africa Time.',
        ])
        ->assertOk();

    expect(App\Models\ChatbotLesson::first()->status)->toBe(App\Models\ChatbotLesson::STATUS_APPROVED);
});
