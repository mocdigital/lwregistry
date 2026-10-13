<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ChatbotBlueprint;
use App\Models\ChatbotLead;
use App\Models\ChatbotNode;
use App\Models\ChatbotWire;
use App\Models\User;
use App\Support\Chatbot\BlueprintRunner;
use App\Support\Chatbot\DefaultBlueprint;
use App\Support\Chatbot\KnowledgeBank;
use App\Support\Chatbot\NodeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('chat');
});

/*
 * The conversation as a graph.
 *
 * Greeting somebody, asking their name, offering four things to ask about and
 * falling back to the knowledge bank are the same kind of step with different
 * settings. Written as code they are a state machine only a developer can
 * change; written as nodes and wires they are something the people who talk to
 * customers can rearrange.
 */

function runner(): BlueprintRunner
{
    return app(BlueprintRunner::class);
}

function said(array $turn): string
{
    return collect($turn['messages'])->pluck('text')->implode("\n");
}

function bankSays(string $text): void
{
    app(KnowledgeBank::class)->add(UploadedFile::fake()->createWithContent('kb.txt', $text), 'Guidance');
}

/* --------------------------------------------------------- the default --- */

test('the default flow greets, takes a name, and welcomes by it', function (): void {
    DefaultBlueprint::install();

    $session = 'flow-' . Str::random(6);

    expect(said(runner()->advance($session)))->toContain('what should I call you');

    $turn = runner()->advance($session, 'Ada');

    // :name in the node's text is filled from what the ask node stored, which
    // is how a flow greets somebody without every node type knowing about
    // names.
    expect(said($turn))->toContain('Welcome, Ada');
});

test('the email may be skipped, and a bad one is refused first', function (): void {
    DefaultBlueprint::install();
    $session = 'flow-' . Str::random(6);

    runner()->advance($session);
    runner()->advance($session, 'Ada');

    expect(said(runner()->advance($session, 'not-an-address')))->toContain('does not look like');

    /*
     * Empty is a skip. Refusing to help until somebody hands over an address
     * turns an assistant into a form, so the flow lets them past.
     */
    $turn = runner()->advance($session, '');

    expect($turn['choices'])->not->toBeEmpty()
        ->and(said($turn))->toContain('What can I help you with');
});

test('a name is enough to become a lead, with or without an address', function (): void {
    DefaultBlueprint::install();
    $session = 'flow-' . Str::random(6);

    runner()->advance($session);
    runner()->advance($session, 'Ada');
    runner()->advance($session, '');

    $lead = ChatbotLead::query()->firstOrFail();

    // A visitor who leaves after giving a name is still somebody who can be
    // followed up; captured as we go rather than only at the end.
    expect($lead->name)->toBe('Ada')
        ->and($lead->email)->toBeNull()
        ->and($lead->session_id)->toBe($session);
});

test('asking twice does not make a second lead', function (): void {
    DefaultBlueprint::install();
    $session = 'flow-' . Str::random(6);

    runner()->advance($session);
    runner()->advance($session, 'Ada');
    runner()->advance($session, 'ada@example.com');

    expect(ChatbotLead::query()->count())->toBe(1)
        ->and(ChatbotLead::query()->value('email'))->toBe('ada@example.com');
});

test('a pressed button is the question the bank answers', function (): void {
    DefaultBlueprint::install();
    bankSays('What does it cost? The registration fee is 750 Espees for a year.');

    $session = 'flow-' . Str::random(6);
    runner()->advance($session);
    runner()->advance($session, 'Ada');
    runner()->advance($session, '');

    /*
     * A button wired into the knowledge bank has to carry something for it to
     * answer, and what the visitor pressed is exactly what they asked.
     */
    /*
     * The menu's pins are the subjects now, and pressing one opens that
     * subject's submenu rather than answering immediately — there are too many
     * questions per subject for one button to stand for all of them.
     */
    $turn = runner()->advance($session, 'Fees and payment', 'fees');

    expect($turn['choices'])->not->toBeEmpty()
        ->and(said($turn))->toContain('Fees and payment');

    // The question itself is the next press, and that is what is answered.
    $answer = runner()->advance($session, 'How much does registration cost?', 'q0');

    expect(said($answer))->toContain('depends on the size');
});

test('a typed question at the menu is answered, not refused for missing a button', function (): void {
    bankSays('The registration fee depends on the size of the business and the country.');
    DefaultBlueprint::install();
    $session = 'flow-' . Str::random(6);

    runner()->advance($session);
    runner()->advance($session, 'Ada');
    runner()->advance($session, '');

    // Insisting on its own buttons would be an assistant refusing to listen:
    // a typed question is heard and answered.
    $turn = runner()->advance($session, 'How much does registration cost?');

    expect(said($turn))->toContain('depends on the size');
});

test('a follow-up already asked is not offered a second time', function (): void {
    DefaultBlueprint::install();
    // The bank must actually answer, so the flow reaches the "anything else?"
    // step where related follow-ups are offered rather than a handoff.
    bankSays('How much does registration cost? The fee depends on the size of your business and the country you register in.');
    bankSays('What does the fee cover? Verification of your documents, your entry in the directory, and your digital seal.');

    $session = 'flow-' . Str::random(6);

    runner()->advance($session);
    runner()->advance($session, 'Ada');
    runner()->advance($session, '');

    // A question with siblings, so there are related follow-ups to offer.
    $asked = 'How much does registration cost?';
    $turn = runner()->advance($session, $asked);

    $suggested = collect($turn['choices'])->where('pin', 'suggest')->pluck('label');

    expect($suggested)->not->toBeEmpty()
        // The question just asked is never suggested back as a follow-up.
        ->and($suggested)->not->toContain($asked);

    // Press the first suggestion; it is now a question this visitor has had.
    $clicked = $suggested->first();
    $next = runner()->advance($session, $clicked, 'suggest');

    $again = collect($next['choices'])->where('pin', 'suggest')->pluck('label');

    // Neither the clicked follow-up nor the original question comes back.
    expect($again)->not->toContain($clicked)
        ->and($again)->not->toContain($asked);
});

test('a question the bank cannot answer is handed to a person', function (): void {
    DefaultBlueprint::install();
    $session = 'flow-' . Str::random(6);

    runner()->advance($session);
    runner()->advance($session, 'Ada');
    runner()->advance($session, '');

    // Nobody online → the visitor is told support will follow up rather than
    // looped back to a menu that could not help either.
    $turn = runner()->advance($session, 'zzz qwxyz nonsense that matches nothing');

    expect($turn['handoff'])->not->toBeNull()
        ->and(said($turn))->toContain('member of our team');
});

/* ------------------------------------------------------- the machinery --- */

test('a blueprint that loops back on itself does not hang the request', function (): void {
    $blueprint = ChatbotBlueprint::create(['name' => 'Loop']);

    ChatbotNode::create([
        'blueprint_id' => $blueprint->id,
        'key' => 'a',
        'type' => NodeType::SAY,
        'config' => ['text' => 'round we go'],
        'x' => 0, 'y' => 0,
    ]);

    // An administrator can draw this on a canvas in two seconds.
    ChatbotWire::create([
        'blueprint_id' => $blueprint->id,
        'from_node' => 'a',
        'from_pin' => 'out',
        'to_node' => 'a',
    ]);

    $blueprint->publish();

    $turn = runner()->advance('loop-' . Str::random(6));

    // Capped rather than infinite: a mistake somebody can see, not a hang.
    expect($turn['finished'])->toBeTrue()
        ->and(count($turn['messages']))->toBeLessThan(60);
});

test('publishing one blueprint retires the other', function (): void {
    $first = ChatbotBlueprint::create(['name' => 'First']);
    $second = ChatbotBlueprint::create(['name' => 'Second']);

    $first->publish();
    $second->publish();

    // Two live blueprints would mean the assistant behaved differently
    // depending on which row a query returned first.
    expect($first->fresh()->is_active)->toBeFalse()
        ->and($second->fresh()->is_active)->toBeTrue()
        ->and(ChatbotBlueprint::query()->where('is_active', true)->count())->toBe(1);
});

test('republishing restarts a visitor rather than stranding them', function (): void {
    DefaultBlueprint::install();
    $session = 'flow-' . Str::random(6);

    runner()->advance($session);
    runner()->advance($session, 'Ada');

    // A different blueprint goes live mid-conversation.
    $other = ChatbotBlueprint::create(['name' => 'Other']);
    ChatbotNode::create([
        'blueprint_id' => $other->id,
        'key' => 'hello',
        'type' => NodeType::SAY,
        'config' => ['text' => 'A brand new conversation'],
        'x' => 0, 'y' => 0,
    ]);
    $other->publish();

    /*
     * Standing on a node that no longer exists would drop somebody into the
     * middle of a flow they have not seen the start of.
     */
    expect(said(runner()->advance($session)))->toContain('A brand new conversation');
});

test('with no blueprint at all it is still the knowledge bank', function (): void {
    bankSays('The registration fee is 750 Espees for a year.');

    // Nothing drawn yet is not a reason to stop answering questions.
    $turn = runner()->advance('bare-' . Str::random(6), 'What is the registration fee?');

    expect(said($turn))->toContain('750 Espees');
});

/* ----------------------------------------------------------- the canvas --- */

function blueprintAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);
}

test('the canvas is handed the graph and the pins each node has', function (): void {
    $blueprint = DefaultBlueprint::install();

    $response = $this->actingAs(blueprintAdmin())
        ->getJson(route('admin.chatbot.blueprints.show', $blueprint))
        ->assertOk();

    $menu = collect($response->json('nodes'))->firstWhere('key', 'menu');

    /*
     * The pins come from the server rather than being worked out in the
     * browser, so the canvas and the runtime cannot disagree about what can be
     * connected to what.
     */
    expect($menu['pins'])->toContain('fees')
        ->and($response->json('nodeTypes'))->toHaveKey(NodeType::CHOICE)
        ->and($response->json('wires'))->not->toBeEmpty();
});

test('a wire to a node that is not on the canvas is refused', function (): void {
    $blueprint = ChatbotBlueprint::create(['name' => 'Draft']);

    /*
     * A dangling reference is one the runner walks into and stops at, which
     * reads to a visitor as the assistant giving up mid-sentence.
     */
    $this->actingAs(blueprintAdmin())
        ->putJson(route('admin.chatbot.blueprints.save', $blueprint), [
            'nodes' => [['key' => 'a', 'type' => NodeType::SAY, 'config' => [], 'x' => 0, 'y' => 0]],
            'wires' => [['from' => 'a', 'pin' => 'out', 'to' => 'nowhere']],
        ])
        ->assertStatus(422);
});

test('two nodes sharing a key are refused', function (): void {
    $blueprint = ChatbotBlueprint::create(['name' => 'Draft']);

    // Every wire would be ambiguous.
    $this->actingAs(blueprintAdmin())
        ->putJson(route('admin.chatbot.blueprints.save', $blueprint), [
            'nodes' => [
                ['key' => 'a', 'type' => NodeType::SAY, 'config' => [], 'x' => 0, 'y' => 0],
                ['key' => 'a', 'type' => NodeType::END, 'config' => [], 'x' => 10, 'y' => 10],
            ],
            'wires' => [],
        ])
        ->assertStatus(422);
});

test('saving replaces the graph rather than adding to it', function (): void {
    $blueprint = DefaultBlueprint::install();

    $this->actingAs(blueprintAdmin())
        ->putJson(route('admin.chatbot.blueprints.save', $blueprint), [
            'nodes' => [['key' => 'only', 'type' => NodeType::SAY, 'config' => ['text' => 'Hi'], 'x' => 5, 'y' => 5]],
            'wires' => [],
        ])
        ->assertOk();

    expect($blueprint->nodes()->count())->toBe(1)
        ->and($blueprint->wires()->count())->toBe(0)
        ->and($blueprint->nodes()->value('key'))->toBe('only');
});

test('a visitor cannot read or rewrite a blueprint', function (): void {
    $blueprint = DefaultBlueprint::install();

    // A guest is turned away by `auth` before the page gate is reached, so
    // this is 401 rather than 403 — refused either way.
    $this->getJson(route('admin.chatbot.blueprints.show', $blueprint))->assertStatus(401);

    $this->actingAs(paidMember())
        ->getJson(route('admin.chatbot.blueprints.show', $blueprint))
        ->assertStatus(403);
});

/* ------------------------------------------------- breadth and retrieval --- */

test('the seeded conversation covers a hundred nodes, generated not typed', function (): void {
    $blueprint = DefaultBlueprint::install();

    /*
     * Twelve subjects, each with its own submenu and an answer node per
     * question. Generated from ConversationTopics rather than written out: a
     * hundred hand-written nodes is a hundred places for a coordinate or a
     * wire to be wrong, and adding a question would mean editing three of them.
     */
    expect($blueprint->nodes()->count())->toBeGreaterThanOrEqual(100)
        ->and($blueprint->wires()->count())->toBeGreaterThanOrEqual(100);
});

test('every question leads somewhere and every answer comes back', function (): void {
    $blueprint = DefaultBlueprint::install()->load(['nodes', 'wires']);

    $keys = $blueprint->nodes->pluck('key');

    /*
     * A wire to a node that does not exist is one the runner walks into and
     * stops at, which reads to a visitor as the assistant giving up
     * mid-sentence. With a hundred nodes that cannot be checked by eye.
     */
    foreach ($blueprint->wires as $wire) {
        expect($keys)->toContain($wire->from_node)
            ->and($keys)->toContain($wire->to_node);
    }

    // And every pin a node offers is wired, or it is a button that does
    // nothing when pressed.
    foreach ($blueprint->nodes as $node) {
        foreach ($node->pins() as $pin) {
            $wired = $blueprint->wires
                ->where('from_node', $node->key)
                ->where('from_pin', $pin)
                ->isNotEmpty();

            expect($wired)->toBeTrue("{$node->key} · {$pin} is not wired to anything");
        }
    }
});

test('a subject opens its own questions', function (): void {
    DefaultBlueprint::install();
    $session = 'topics-' . Str::random(6);

    runner()->advance($session);
    runner()->advance($session, 'Ada');
    runner()->advance($session, '');

    $turn = runner()->advance($session, 'Procurement and bidding', 'procurement');

    expect(collect($turn['choices'])->pluck('label')->implode(' | '))
        ->toContain('Who can bid?');
});

test('the registry describes itself into the knowledge bank', function (): void {
    // The application already knows its own fee currency, categories and
    // listing count; making somebody retype that into a PDF is asking them to
    // maintain a second copy of it.
    $written = App\Support\Chatbot\SiteKnowledge::refresh();

    expect($written)->toBeGreaterThan(10)
        ->and(App\Models\ChatbotPassage::query()->count())->toBeGreaterThan(40);
});

test('a question nobody scripted is still answered from the same content', function (): void {
    App\Support\Chatbot\SiteKnowledge::refresh();

    /*
     * The point of writing the scripted answers into the bank as well: the
     * graph answers the questions somebody anticipated, and the bank answers
     * the ones they did not. Neither phrasing below is a button.
     */
    foreach ([
        'what currency are budgets in',
        'who sees my compliance documents',
        'can I send files in a chat',
    ] as $question) {
        expect(app(App\Support\Chatbot\Responder::class)->answer($question)->answered)
            ->toBeTrue("nothing found for: {$question}");
    }
});

test('a misspelling still finds the answer', function (): void {
    App\Support\Chatbot\SiteKnowledge::refresh();

    /*
     * Matching substrings meant one transposed letter found nothing at all.
     * The candidate query widens to a prefix when nothing matches exactly, and
     * the scoring compares the raw words of both sides — stemming either side
     * moves a typo away from the word it is one edit from.
     */
    expect(app(App\Support\Chatbot\Responder::class)->answer('registraton')->answered)->toBeTrue()
        ->and(app(App\Support\Chatbot\Responder::class)->answer('verifcation')->answered)->toBeTrue();
});

test('a plural, a synonym and a greeting are each handled', function (): void {
    App\Support\Chatbot\SiteKnowledge::refresh();

    $responder = app(App\Support\Chatbot\Responder::class);

    // "fees" finding "fee", and "price" finding a passage that says neither.
    expect($responder->answer('what are the fees')->answered)->toBeTrue()
        ->and($responder->answer('what is the price of joining')->answered)->toBeTrue();

    // A greeting is not a question for the bank, and answering it from a
    // document about fees is how an assistant reads as a machine.
    $hello = $responder->answer('hi');

    expect($hello->answered)->toBeTrue()
        ->and($hello->text)->not->toContain('registration fee');
});

test('nothing found offers what there is rather than a refusal', function (): void {
    App\Support\Chatbot\SiteKnowledge::refresh();

    $answer = app(App\Support\Chatbot\Responder::class)->answer('do you sell tractors in Ulaanbaatar');

    // A visitor who asked the wrong question is better served by three
    // subjects than by "no".
    expect($answer->answered)->toBeFalse()
        ->and($answer->text)->toContain('I can help with');
});

/* ------------------------------------------------- a member is not a guest --- */

test('a signed-in member is greeted by name, not asked for one', function (): void {
    DefaultBlueprint::install();

    $member = User::factory()->create([
        'firstname' => 'Ada',
        'lastname' => 'Okafor',
        'email' => 'ada@example.com',
    ]);

    /*
     * Asking a member their name is asking for something the account is
     * already holding, which reads as an assistant that has not looked.
     */
    $turn = runner()->advance('known-' . Str::random(6), null, null, $member);

    expect(said($turn))->toContain('Welcome, Ada')
        ->and(said($turn))->not->toContain('what should I call you')
        ->and(said($turn))->not->toContain('best email')
        // Straight to the part that helps them.
        ->and($turn['choices'])->not->toBeEmpty();
});

test('the member is already a lead, without being asked twice', function (): void {
    DefaultBlueprint::install();

    $member = User::factory()->create([
        'firstname' => 'Ada',
        'lastname' => 'Okafor',
        'email' => 'ada@example.com',
    ]);

    runner()->advance('known-' . Str::random(6), null, null, $member);

    $lead = ChatbotLead::query()->firstOrFail();

    expect($lead->name)->toBe('Ada')
        ->and($lead->email)->toBe('ada@example.com')
        ->and($lead->user_id)->toBe($member->id);
});

test('a guest is still asked, because nothing is known about them', function (): void {
    DefaultBlueprint::install();

    // One blueprint serves both: the skip is "already answered", not "is a
    // member", so a guest walks the full path.
    $turn = runner()->advance('guest-' . Str::random(6));

    expect(said($turn))->toContain('what should I call you');
});

test('signing in mid-conversation fills in what is now known', function (): void {
    DefaultBlueprint::install();
    $session = 'mid-' . Str::random(6);

    // Opened as a guest.
    runner()->advance($session);

    $member = User::factory()->create([
        'firstname' => 'Ada',
        'lastname' => 'Okafor',
        'email' => 'ada@example.com',
    ]);

    // Then signs in, and the next turn should not re-ask what it now knows.
    $turn = runner()->advance($session, null, null, $member);

    expect(said($turn))->not->toContain('what should I call you');
});

/* ------------------------------------------------------------- restarting --- */

test('restarting puts a visitor back at the greeting', function (): void {
    DefaultBlueprint::install();
    $session = 'restart-' . Str::random(6);

    runner()->advance($session);
    runner()->advance($session, 'Ada');
    runner()->advance($session, '');

    // Deep in the flow, at the menu.
    expect(said(runner()->advance($session, 'Fees and payment', 'fees')))->toContain('Fees and payment');

    runner()->restart($session);

    // A conversation that has gone somewhere the visitor did not mean is
    // otherwise stuck: the flow remembers where they are.
    expect(said(runner()->advance($session)))->toContain('what should I call you');
});

test('restarting forgets the answers but not the lead', function (): void {
    DefaultBlueprint::install();
    $session = 'restart-' . Str::random(6);

    runner()->advance($session);
    runner()->advance($session, 'Ada');
    runner()->advance($session, 'ada@example.com');

    runner()->restart($session);

    /*
     * Somebody wanting to ask a different question does not undo the enquiry
     * they have already made — the lead is what the whole thing is for.
     */
    expect(ChatbotLead::query()->count())->toBe(1)
        ->and(ChatbotLead::query()->value('email'))->toBe('ada@example.com');
});

test('a member restarting is still not asked who they are', function (): void {
    DefaultBlueprint::install();

    $member = User::factory()->create([
        'firstname' => 'Ada',
        'lastname' => 'Okafor',
        'email' => 'ada@example.com',
    ]);

    $session = 'restart-' . Str::random(6);

    runner()->advance($session, null, null, $member);
    runner()->restart($session, $member);

    // What the account tells us was never something they typed, so restarting
    // does not throw it away.
    expect(said(runner()->advance($session, null, null, $member)))
        ->toContain('Welcome, Ada')
        ->not->toContain('what should I call you');
});

test('the restart endpoint answers with the fresh opening', function (): void {
    DefaultBlueprint::install();

    $this->postJson(route('chatbot.ask'), ['question' => 'hello'])->assertOk();

    $response = $this->postJson(route('chatbot.restart'))->assertOk();

    // Not an empty acknowledgement: the panel clears its transcript and needs
    // something to show in its place.
    expect($response->json('messages'))->not->toBeEmpty();
});
