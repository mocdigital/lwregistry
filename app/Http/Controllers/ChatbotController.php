<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\ChatbotSession;
use App\Support\Chatbot\BlueprintRunner;
use App\Support\Chatbot\HumanHandoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The sales assistant a visitor talks to.
 *
 * Open to guests. Someone deciding whether to register is exactly who this is
 * for, and putting it behind a login would mean it only ever answered people
 * who had already joined.
 *
 * JSON rather than an Inertia redirect: the widget is a conversation on top of
 * whatever page the visitor is reading, and a redirect would take them off it.
 *
 * The conversation itself is a blueprint an administrator drew — this only
 * hands the runner what was said and returns what to show. `open` starts one;
 * `ask` continues it.
 */
final class ChatbotController extends Controller
{
    /** Begin, and return the greeting and whatever it asks for first. */
    public function open(Request $request, BlueprintRunner $runner): JsonResponse
    {
        return response()->json(
            $runner->advance($this->sessionId($request), null, null, $request->user()),
        );
    }

    /**
     * Start again.
     *
     * A conversation that has ended, or gone somewhere the visitor did not
     * mean, is otherwise stuck: the flow remembers where they are, so
     * reopening the panel put them back exactly where they gave up.
     *
     * What is FORGOTTEN is the position and the answers, not the lead. A
     * visitor who gave their name once should not be asked for it again
     * because they restarted, and the enquiry they generated is not undone by
     * their wanting to ask something else.
     */
    public function restart(Request $request, BlueprintRunner $runner): JsonResponse
    {
        $runner->restart($this->sessionId($request), $request->user());

        return response()->json(
            $runner->advance($this->sessionId($request), null, null, $request->user()),
        );
    }

    public function ask(Request $request, BlueprintRunner $runner): JsonResponse
    {
        $data = $request->validate([
            // One or the other: typed words, or the pin of a button pressed.
            'question' => ['nullable', 'string', 'max:500'],
            'pin' => ['nullable', 'string', 'max:64'],
        ]);

        $said = $data['question'] ?? null;

        /*
         * Once a conversation is with a person, the visitor is talking to them,
         * not to the bot: their line is stored in the human thread and the bot
         * does not answer over the top of it. The admin console polls for these
         * and replies; the widget polls back for the reply.
         */
        $session = ChatbotSession::where('session_id', $this->sessionId($request))->first();

        if ($session && $session->isWithHuman() && filled($said)) {
            app(HumanHandoff::class)->visitorSaid($session, (string) $said);

            return response()->json([
                'messages' => [],
                'choices' => [],
                'awaiting' => null,
                'finished' => false,
                'handoff' => $session->handoff_state,
                'live' => true,
            ]);
        }

        $result = $runner->advance(
            $this->sessionId($request),
            $said,
            $data['pin'] ?? null,
            $request->user(),
        );

        /*
         * Every question is recorded, answered or not. The unanswered ones are
         * the point: they are a list of what customers want to know and the
         * material that does not yet exist.
         *
         * Only what was TYPED — a button press is the flow working, not a
         * question the bank failed to cover.
         */
        if (filled($said)) {
            $answered = collect($result['messages'])->contains(fn (array $m): bool => (bool) $m['answered']);

            ChatbotConversation::create([
                'session_id' => $this->sessionId($request),
                'user_id' => $request->user()?->id,
                'question' => $said,
                'answer' => collect($result['messages'])->pluck('text')->implode("\n\n"),
                'answered' => $answered,
                // Which passages answered it, so an answer can be checked
                // against its source.
                'passage_ids' => $result['passageIds'] ?? [],
            ]);
        }

        return response()->json($result);
    }

    /**
     * What the widget checks for while a person is handling the conversation.
     *
     * Returns the current handoff state and any admin messages the widget has
     * not seen yet, identified by the last id it holds — so a poll is cheap and
     * never replays a message twice.
     */
    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $sessionId = $this->sessionId($request);
        $session = ChatbotSession::where('session_id', $sessionId)->first();

        $messages = ChatbotMessage::query()
            ->where('session_id', $sessionId)
            ->where('sender', ChatbotMessage::SENDER_ADMIN)
            ->when($data['after'] ?? null, fn ($q, $after) => $q->where('id', '>', $after))
            ->orderBy('id')
            ->get(['id', 'body', 'admin_id', 'created_at']);

        return response()->json([
            'handoff' => $session?->handoff_state,
            'messages' => $messages->map(fn (ChatbotMessage $m): array => [
                'id' => $m->id,
                'text' => $m->body,
            ])->all(),
        ]);
    }

    /**
     * Answer a question from the home screen, straight from the bank.
     *
     * The home screen's cards and its composer post here rather than walking
     * the guided flow. Once a person is handling the conversation the question
     * goes to them, exactly as the scripted path does; otherwise it is answered
     * and recorded — the unanswered ones being the list of gaps to fill.
     */
    public function direct(Request $request, BlueprintRunner $runner): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        $said = $data['question'];
        $sessionId = $this->sessionId($request);
        $session = ChatbotSession::where('session_id', $sessionId)->first();

        if ($session && $session->isWithHuman()) {
            app(HumanHandoff::class)->visitorSaid($session, $said);

            return response()->json([
                'messages' => [], 'choices' => [], 'awaiting' => null,
                'finished' => false, 'handoff' => $session->handoff_state, 'live' => true,
            ]);
        }

        $result = $runner->directAnswer($sessionId, $said, $request->user());

        $answered = collect($result['messages'])->contains(fn (array $m): bool => (bool) $m['answered']);

        ChatbotConversation::create([
            'session_id' => $sessionId,
            'user_id' => $request->user()?->id,
            'question' => $said,
            'answer' => collect($result['messages'])->pluck('text')->implode("\n\n"),
            'answered' => $answered,
            'passage_ids' => $result['passageIds'] ?? [],
        ]);

        return response()->json($result);
    }

    /**
     * "Talk to a support person", from anywhere in the conversation.
     *
     * A direct request for a human that does not depend on the flow reaching a
     * particular node — the control is always there, so this always works.
     */
    public function handoff(Request $request, BlueprintRunner $runner): JsonResponse
    {
        return response()->json(
            $runner->handoff($this->sessionId($request), $request->user()),
        );
    }

    private function sessionId(Request $request): string
    {
        return mb_substr((string) $request->session()->getId(), 0, 64);
    }
}
