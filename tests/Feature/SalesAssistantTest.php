<?php

declare(strict_types=1);

use App\Models\ChatbotConversation;
use App\Models\ChatbotDocument;
use App\Models\ChatbotPassage;
use App\Support\Chatbot\KnowledgeBank;
use App\Support\Chatbot\PassageSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('chat');
});

/*
 * The sales assistant.
 *
 * It answers from documents an administrator uploaded and from nothing else —
 * retrieval, not generation. That is the design, not a limitation: an
 * assistant that invents an answer about price or eligibility does more damage
 * than one that says it will find out, and everything it says is something
 * somebody wrote and can be pointed at.
 *
 * It also means no question and no document ever leaves this server, which is
 * the same rule the self-hosted translator follows.
 */

function knowledgeFile(string $body, string $name = 'guide.txt'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $body);
}

function addKnowledge(string $body, string $title = 'Guidance'): ChatbotDocument
{
    return app(KnowledgeBank::class)->add(knowledgeFile($body), $title);
}

/* ------------------------------------------------------- reading a file --- */

test('a document is read once and split into passages', function (): void {
    $document = addKnowledge(
        "Registration fees\n\n"
        . 'The registration fee is 750 Espees and covers verification of your documents, '
        . "your entry in the directory, and your digital seal for one year.\n\n"
        . 'Renewals are due each January and are charged at the same rate.',
    );

    // Read at upload, not per question: parsing is expensive, the file does
    // not change, and doing it on the answering path makes the first visitor
    // of the day wait for it.
    expect($document->isReady())->toBeTrue()
        ->and($document->passage_count)->toBeGreaterThan(0)
        ->and(ChatbotPassage::query()->count())->toBe($document->passage_count);
});

test('a document with no readable text fails visibly', function (): void {
    $document = addKnowledge('   ');

    // A scanned PDF uploads cleanly and answers nothing. Silence would leave
    // an administrator believing the bank covers something it does not.
    expect($document->status)->toBe(ChatbotDocument::STATUS_FAILED)
        ->and($document->error)->not->toBeNull()
        ->and($document->passage_count)->toBe(0);
});

test('re-reading replaces the passages rather than adding to them', function (): void {
    $document = addKnowledge('The registration fee is 750 Espees and covers verification for a year.');
    $before = ChatbotPassage::query()->count();

    app(KnowledgeBank::class)->index($document);

    // Otherwise the old passages answer alongside the new ones after any
    // change to how documents are split.
    expect(ChatbotPassage::query()->count())->toBe($before);
});

test('a heading is kept with the paragraph it introduces', function (): void {
    $passages = (new PassageSplitter())->split(
        "Refunds\n\nA registration fee is refundable within fourteen days of payment provided "
        . 'the verification process has not yet started on your application.',
    );

    /*
     * "Refunds" alone answers nothing, but it is the word somebody asking
     * about refunds will use — so it has to travel with the paragraph that
     * explains them.
     */
    expect($passages)->toHaveCount(1)
        ->and($passages[0])->toContain('Refunds')
        ->and($passages[0])->toContain('fourteen days');
});

/* ------------------------------------------------------------ answering --- */

/**
 * The assistant answers in TURNS now — a greeting and a question arrive
 * together, because that is how the flow reads to the person on the other end.
 * These two flatten a turn to what the old assertions were about.
 */
function assistantSays($response): string
{
    return collect($response->json('messages'))->pluck('text')->implode("\n");
}

function assistantAnswered($response): bool
{
    return collect($response->json('messages'))->contains(fn (array $m): bool => (bool) $m['answered']);
}

test('a question is answered from the uploaded material', function (): void {
    addKnowledge(
        'The registration fee is 750 Espees and covers verification of your documents, '
        . 'your entry in the directory, and your digital seal for one year.',
    );

    $response = $this->postJson(route('chatbot.ask'), ['question' => 'How much is the registration fee?'])
        ->assertOk();

    expect(assistantAnswered($response))->toBeTrue()
        ->and(assistantSays($response))->toContain('750 Espees');
});

test('it says it does not know rather than inventing something', function (): void {
    addKnowledge('The registration fee is 750 Espees and covers verification for one year.');

    /*
     * The whole argument for retrieval over generation: a made-up answer about
     * shipping would be indistinguishable from a real one to the person
     * reading it.
     */
    $response = $this->postJson(route('chatbot.ask'), [
        'question' => 'Do you deliver refrigerated cargo to Mombasa on Sundays?',
    ])->assertOk();

    expect(assistantAnswered($response))->toBeFalse()
        ->and(assistantSays($response))->not->toContain('750');
});

test('an empty bank answers nothing rather than erroring', function (): void {
    $response = $this->postJson(route('chatbot.ask'), ['question' => 'What are the fees?'])
        ->assertOk();

    expect(assistantAnswered($response))->toBeFalse();
});

test('the passage that addresses the question wins, not the longest one', function (): void {
    addKnowledge(
        'Our history began in 2016 with a handful of businesses meeting in Lagos to talk about '
        . "trade, fellowship, standards, training, mentoring and the long work of building trust.\n\n"
        . 'Renewals are due each January and are charged at the same rate as the first year.',
    );

    $response = $this->postJson(route('chatbot.ask'), ['question' => 'When are renewals due?'])
        ->assertOk();

    expect(assistantSays($response))->toContain('January');
});

test('a question is matched however it is punctuated or accented', function (): void {
    addKnowledge('Verification normally takes five working days once documents are complete.');

    // The passage and the question go through the same normalisation, which is
    // why "Verification?" matches "verification".
    $response = $this->postJson(route('chatbot.ask'), ['question' => '  VERIFICATION?  '])
        ->assertOk();

    expect(assistantAnswered($response))->toBeTrue();
});

/* ------------------------------------------------------- what was asked --- */

test('every question is recorded, answered or not', function (): void {
    addKnowledge('The registration fee is 750 Espees.');

    $this->postJson(route('chatbot.ask'), ['question' => 'How much is the fee?']);
    $this->postJson(route('chatbot.ask'), ['question' => 'Do you ship to Mombasa?']);

    /*
     * The unanswered ones are the valuable half: a list of what customers want
     * to know and the sales material that does not yet exist.
     */
    expect(ChatbotConversation::query()->count())->toBe(2)
        ->and(ChatbotConversation::query()->where('answered', false)->count())->toBe(1);
});

test('an answer records the passages it came from', function (): void {
    addKnowledge('The registration fee is 750 Espees and covers one year.');

    $this->postJson(route('chatbot.ask'), ['question' => 'What is the registration fee?']);

    // An assistant that cannot show its working is one nobody can correct.
    $logged = ChatbotConversation::query()->firstOrFail();

    expect($logged->passage_ids)->not->toBeEmpty()
        ->and(ChatbotPassage::query()->whereKey($logged->passage_ids)->count())
        ->toBe(count($logged->passage_ids));
});

/* -------------------------------------------------------------- limits --- */

test('a guest can ask, because a guest is who it is for', function (): void {
    addKnowledge('The registration fee is 750 Espees.');

    // Behind a login it would only ever answer people who had already joined.
    $this->postJson(route('chatbot.ask'), ['question' => 'What is the fee?'])
        ->assertOk();
});

test('an over-long question is refused', function (): void {
    $this->postJson(route('chatbot.ask'), ['question' => str_repeat('a', 501)])
        ->assertStatus(422);
});

test('the home screen answers a question directly, without the greeting flow', function (): void {
    addKnowledge('The registration fee is 750 Espees and is paid with Espees or by transfer.');

    // The home screen's cards and composer post here — a straight answer, no
    // name-and-email preamble first.
    $response = $this->postJson(route('chatbot.direct'), ['question' => 'What are the fees?'])
        ->assertOk()
        ->json();

    expect($response['messages'][0]['text'])->toContain('750 Espees')
        ->and($response['handoff'])->toBeNull();

    // And it is recorded as answered, so it does not land on the gaps-to-fill list.
    $this->assertDatabaseHas('chatbot_conversations', [
        'question' => 'What are the fees?',
        'answered' => true,
    ]);
});

test('a home-screen question the bank cannot answer is handed to a person', function (): void {
    $response = $this->postJson(route('chatbot.direct'), ['question' => 'zzz qwxyz nothing matches'])
        ->assertOk()
        ->json();

    expect($response['handoff'])->not->toBeNull();
    $this->assertDatabaseHas('chatbot_conversations', [
        'question' => 'zzz qwxyz nothing matches',
        'answered' => false,
    ]);
});

test('an empty answer is a skip, not a rejection', function (): void {
    /*
     * The flow decides whether a question may be walked past — the email
     * usually can — so nothing is the answer "I would rather not", and
     * refusing it here would make the skip button impossible.
     */
    $this->postJson(route('chatbot.ask'), ['question' => ''])
        ->assertOk();
});

test('asking is rate limited, since it is an unauthenticated write', function (): void {
    addKnowledge('The registration fee is 750 Espees.');

    for ($i = 0; $i < 60; $i++) {
        $this->postJson(route('chatbot.ask'), ['question' => 'Fee ' . $i . '?']);
    }

    $this->postJson(route('chatbot.ask'), ['question' => 'One too many?'])
        ->assertStatus(429);
});

test('an answer is followed by a related question to keep the conversation going', function (): void {
    addKnowledge('To register a business, create an account and add your business details.');

    $response = $this->postJson(route('chatbot.direct'), ['question' => 'how do I register'])
        ->assertOk()
        ->json();

    // The answer, a lead-in, and up to three related questions offered as
    // tappable quick replies.
    expect($response['messages'])->toHaveCount(2)
        ->and($response['messages'][1]['text'])->toContain('You might also want to know:')
        ->and($response['choices'])->not->toBeEmpty()
        ->and(count($response['choices']))->toBeLessThanOrEqual(3)
        ->and($response['choices'][0])->toHaveKeys(['label', 'pin']);
});

test('a typed request for a person connects to support instead of answering', function (): void {
    addKnowledge('The registration fee is 750 Espees.');

    $response = $this->postJson(route('chatbot.direct'), ['question' => 'can I talk to a person please'])
        ->assertOk()
        ->json();

    // Handed to a person rather than answered from the bank.
    expect($response['handoff'])->not->toBeNull()
        ->and($response['messages'][0]['text'])->not->toContain('750');

    expect(App\Models\ChatbotSession::first()->handoff_state)->toBeIn(['requested', 'missed']);
});
