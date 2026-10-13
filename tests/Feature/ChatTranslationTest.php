<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\User;
use App\Support\Translation\TranslatedText;
use App\Support\Translation\TranslationFailed;
use App\Support\Translation\Translator;
use Illuminate\Support\Str;

/*
 * "See translation" on SBO Network messages.
 *
 * The provider is swapped for a fake here — these assert the behaviour around
 * the call (who may ask, what gets cached, what happens when it fails), not
 * DeepL's output. A test that hit a real API would be slow, billed, and would
 * fail for reasons that have nothing to do with this code.
 */

final class FakeTranslator implements Translator
{
    public int $calls = 0;

    public function __construct(
        private readonly bool $configured = true,
        private readonly ?string $throws = null,
        private readonly ?string $detects = 'fr',
    ) {}

    public function translate(string $text, string $target, ?string $source = null): TranslatedText
    {
        $this->calls++;

        if (null !== $this->throws) {
            throw new TranslationFailed($this->throws);
        }

        return new TranslatedText("[{$target}] {$text}", 'fr');
    }

    public function detect(string $text): ?string
    {
        return $this->detects;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}

function chatUser(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

function chatSbo(User $owner): Sbo
{
    return Sbo::create([
        'name' => 'Chat Co ' . Str::random(4),
        'slug' => 'chat-co-' . Str::random(6),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);
}

/**
 * A conversation between two businesses, with one message from the second.
 *
 * @return array{0: User, 1: SboMessage}
 */
function chatThread(): array
{
    $mine = chatUser();
    $theirs = chatUser();
    $mySbo = chatSbo($mine);
    $theirSbo = chatSbo($theirs);

    $conversation = SboConversation::create([
        'created_by_sbo_id' => $theirSbo->id,
        'type' => 'direct',
    ]);
    $conversation->participants()->attach([$mySbo->id, $theirSbo->id]);

    $message = SboMessage::create([
        'conversation_id' => $conversation->id,
        'sbo_id' => $theirSbo->id,
        'user_id' => $theirs->id,
        'body' => 'Bonjour, nous sommes intéressés par votre offre.',
    ]);

    return [$mine, $message];
}

test('a participant can translate a message into their language', function (): void {
    $fake = new FakeTranslator();
    app()->instance(Translator::class, $fake);

    [$reader, $message] = chatThread();

    $this->actingAs($reader)
        ->postJson("/sbo/messages/{$message->id}/translate", ['locale' => 'es'])
        ->assertOk()
        ->assertJson([
            'body' => '[es] Bonjour, nous sommes intéressés par votre offre.',
            'locale' => 'es',
            'sourceLocale' => 'fr',
            // Drives the "Translated from French" line under the bubble.
            'sourceName' => 'French',
        ]);
});

test('a second request for the same language is served from cache', function (): void {
    $fake = new FakeTranslator();
    app()->instance(Translator::class, $fake);

    [$reader, $message] = chatThread();

    $this->actingAs($reader)->postJson("/sbo/messages/{$message->id}/translate", ['locale' => 'es']);
    $this->actingAs($reader)->postJson("/sbo/messages/{$message->id}/translate", ['locale' => 'es'])
        ->assertOk();

    /*
     * Providers bill per character. Several readers sharing a language must
     * cost one translation, not one each.
     */
    expect($fake->calls)->toBe(1);
});

test('a different language is a different translation', function (): void {
    $fake = new FakeTranslator();
    app()->instance(Translator::class, $fake);

    [$reader, $message] = chatThread();

    $this->actingAs($reader)->postJson("/sbo/messages/{$message->id}/translate", ['locale' => 'es']);
    $this->actingAs($reader)->postJson("/sbo/messages/{$message->id}/translate", ['locale' => 'en'])
        ->assertOk()
        ->assertJson(['locale' => 'en']);

    expect($fake->calls)->toBe(2)
        ->and($message->translations()->count())->toBe(2);
});

test('someone outside the conversation cannot translate its messages', function (): void {
    app()->instance(Translator::class, new FakeTranslator());

    [, $message] = chatThread();

    $outsider = chatUser();
    chatSbo($outsider);

    /*
     * A disclosure check, not a politeness one: the response body contains the
     * message text, so without this the endpoint would read out any message in
     * the system by id.
     */
    $this->actingAs($outsider)
        ->postJson("/sbo/messages/{$message->id}/translate", ['locale' => 'es'])
        ->assertNotFound();
});

test('with no provider configured the endpoint refuses rather than erroring', function (): void {
    app()->instance(Translator::class, new FakeTranslator(configured: false));

    [$reader, $message] = chatThread();

    // 503, and the chat hides the control entirely — a button that always
    // fails teaches people the feature is broken rather than switched off.
    $this->actingAs($reader)
        ->postJson("/sbo/messages/{$message->id}/translate", ['locale' => 'es'])
        ->assertStatus(503);
});

test('a provider failure is reported without caching a broken translation', function (): void {
    app()->instance(Translator::class, new FakeTranslator(throws: 'upstream exploded'));

    [$reader, $message] = chatThread();

    $this->actingAs($reader)
        ->postJson("/sbo/messages/{$message->id}/translate", ['locale' => 'es'])
        ->assertStatus(502);

    // Nothing cached, so a retry after the provider recovers actually retries.
    expect($message->translations()->count())->toBe(0);
});

test('an over-long message is refused rather than truncated', function (): void {
    app()->instance(Translator::class, new FakeTranslator());
    config(['translation.max_characters' => 20]);

    [$reader, $message] = chatThread();

    // A half-translated message is worse than none.
    $this->actingAs($reader)
        ->postJson("/sbo/messages/{$message->id}/translate", ['locale' => 'es'])
        ->assertStatus(422);
});

test('an unsupported target language is refused', function (): void {
    app()->instance(Translator::class, new FakeTranslator());

    [$reader, $message] = chatThread();

    $this->actingAs($reader)
        ->postJson("/sbo/messages/{$message->id}/translate", ['locale' => 'de'])
        ->assertStatus(422);
});

/* ------------------------------------------- offering the control ------ */

test('a message already in the reader\'s language does not offer translation', function (): void {
    app()->instance(Translator::class, new FakeTranslator(detects: 'en'));

    [$reader, $message] = chatThread();

    // Reader is on English and the message is detected as English.
    $this->actingAs($reader)->post('/locale', ['locale' => 'en']);

    $this->actingAs($reader)
        ->get('/sbo/network/messages?conversation=' . $message->conversation_id)
        ->assertInertia(function ($page) use ($message): void {
            $row = collect($page->toArray()['props']['thread'])
                ->firstWhere('id', (string) $message->id);

            /*
             * The control used to appear under every incoming message,
             * including whole threads already in the reader's language — noise
             * that trains people to ignore the link.
             */
            expect($row['translatable'])->toBeFalse()
                ->and($row['detectedLocale'])->toBe('en');
        });
});

test('a message in another language does offer translation', function (): void {
    app()->instance(Translator::class, new FakeTranslator(detects: 'fr'));

    [$reader, $message] = chatThread();

    $this->actingAs($reader)->post('/locale', ['locale' => 'en']);

    $this->actingAs($reader)
        ->get('/sbo/network/messages?conversation=' . $message->conversation_id)
        ->assertInertia(function ($page) use ($message): void {
            $row = collect($page->toArray()['props']['thread'])
                ->firstWhere('id', (string) $message->id);

            expect($row['translatable'])->toBeTrue()
                ->and($row['detectedLocale'])->toBe('fr');
        });
});

test('detection happens once and is stored on the message', function (): void {
    app()->instance(Translator::class, new FakeTranslator(detects: 'fr'));

    [$reader, $message] = chatThread();

    $this->actingAs($reader)->get('/sbo/network/messages?conversation=' . $message->conversation_id);

    expect($message->fresh()->detected_locale)->toBe('fr')
        ->and($message->fresh()->detected_at)->not->toBeNull();
});

test('your own messages never offer translation', function (): void {
    app()->instance(Translator::class, new FakeTranslator(detects: 'fr'));

    [$reader, $message] = chatThread();

    $mySbo = $reader->sbos()->first();

    $mine = SboMessage::create([
        'conversation_id' => $message->conversation_id,
        'sbo_id' => $mySbo->id,
        'user_id' => $reader->id,
        'body' => 'Merci pour votre message.',
    ]);

    $this->actingAs($reader)
        ->get('/sbo/network/messages?conversation=' . $message->conversation_id)
        ->assertInertia(function ($page) use ($mine): void {
            $row = collect($page->toArray()['props']['thread'])
                ->firstWhere('id', (string) $mine->id);

            // Translating your own words tells you nothing.
            expect($row['translatable'])->toBeFalse();
        });
});
