<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SboMessage;
use App\Models\SboMessageTranslation;
use App\Support\Locales;
use App\Support\Translation\TranslationFailed;
use App\Support\Translation\Translator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * "See translation" on a chat message.
 *
 * JSON rather than an Inertia redirect: this expands one bubble in place, and
 * re-rendering the whole thread would lose the reader's scroll position in a
 * conversation they are in the middle of reading.
 *
 * The translation is cached per (message, locale), so several readers sharing a
 * language pay for the sentence once — see the migration for why that matters
 * when providers bill per character.
 */
final class SboMessageTranslationController extends Controller
{
    /** Translations a single member may request per minute. */
    private const MAX_PER_MINUTE = 30;

    public function __construct(private readonly Translator $translator) {}

    public function store(Request $request, SboMessage $message): JsonResponse
    {
        $user = $request->user();

        /*
         * Only a participant may translate. Without this the endpoint would
         * read out any message in the system by id — a translation response
         * contains the message text, so this is a disclosure check, not a
         * politeness one.
         */
        abort_unless($this->mayRead($request, $message), 404);

        if ( ! $this->translator->isConfigured()) {
            return response()->json([
                'message' => 'Translation is not available.',
            ], 503);
        }

        $target = Locales::resolve($request, $user);

        $data = $request->validate([
            // The reader may override, e.g. to read a message in the original
            // language of the thread rather than their interface language.
            'locale' => ['sometimes', 'string', \Illuminate\Validation\Rule::in(Locales::codes())],
        ]);

        $target = $data['locale'] ?? $target;

        $body = (string) $message->body;

        if (mb_strlen($body) > (int) config('translation.max_characters', 5000)) {
            return response()->json([
                'message' => 'That message is too long to translate.',
            ], 422);
        }

        // Already cached: no provider call, no rate-limit charge.
        $cached = $message->translations()->where('locale', $target)->first();

        if (null !== $cached) {
            return response()->json($this->payload($cached));
        }

        $key = 'translate:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_MINUTE)) {
            return response()->json([
                'message' => 'Too many translations. Try again shortly.',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        try {
            $result = $this->translator->translate($body, $target);
        } catch (TranslationFailed $exception) {
            report($exception);

            return response()->json([
                'message' => 'That message could not be translated right now.',
            ], 502);
        }

        /*
         * firstOrCreate rather than create: two readers can ask for the same
         * language at the same moment, and the unique index would otherwise
         * turn the loser of that race into a 500.
         */
        try {
            $translation = SboMessageTranslation::firstOrCreate(
                ['message_id' => $message->id, 'locale' => $target],
                [
                    'body' => $result->text,
                    'source_locale' => $result->detectedSource,
                    'provider' => (string) config('translation.driver'),
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            // The translation itself succeeded; failing to cache it should not
            // deny the reader the text they already paid for.
            return response()->json([
                'body' => $result->text,
                'locale' => $target,
                'sourceLocale' => $result->detectedSource,
                'sourceName' => $this->languageName($result->detectedSource),
            ]);
        }

        return response()->json($this->payload($translation));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SboMessageTranslation $translation): array
    {
        return [
            'body' => $translation->body,
            'locale' => $translation->locale,
            'sourceLocale' => $translation->source_locale,
            'sourceName' => $this->languageName($translation->source_locale),
        ];
    }

    /** "fr" -> "French", for the "Translated from …" line. */
    private function languageName(?string $code): ?string
    {
        if (null === $code) {
            return null;
        }

        return Locales::supported()[$code]['name'] ?? mb_strtoupper($code);
    }

    /**
     * Whether the caller is in the conversation this message belongs to.
     *
     * Membership is by SBO, not by user: a conversation is between two
     * businesses, and anyone who can act for either side can read it.
     */
    private function mayRead(Request $request, SboMessage $message): bool
    {
        $mySboIds = $request->user()->sbos()->pluck('id');

        if ($mySboIds->isEmpty()) {
            return false;
        }

        $conversation = $message->conversation;

        if (null === $conversation) {
            return false;
        }

        // Membership lives in the sbo_conversation_participants pivot; a
        // conversation is not limited to two rows in the table.
        return $conversation->participants()
            ->whereIn('sbos.id', $mySboIds->all())
            ->exists();
    }
}
