<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\ChatbotSession;
use App\Support\AdminCapabilities;
use App\Support\Chatbot\AdminPresence;
use App\Support\Chatbot\LearningBank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live side of the assistant: watching conversations and taking them over.
 *
 * The console is JavaScript that polls these endpoints — there is no websocket
 * server on this deployment, so "live" is a short poll rather than a socket.
 * Everything is JSON: a list of conversations, one conversation's transcript,
 * and the three actions (take over, reply, end). A heartbeat marks the
 * administrator online so the visitor's "Talk to a person" knows someone is
 * here to take it.
 */
final class ChatbotLiveController extends Controller
{
    /** Keep the administrator counted as online. Called on a timer by the console. */
    public function ping(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        AdminPresence::heartbeat((int) $request->user()->id);

        return response()->json(['ok' => true]);
    }

    /**
     * Every conversation, split into the ones needing attention and the rest.
     *
     * `waiting` are the handoffs asking for a person — the console rings on a
     * new one. `active` are being handled or recently busy; `past` are the
     * finished and the bot-only. One query, shaped for the list.
     */
    public function sessions(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $sessions = ChatbotSession::query()
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        // Last line of each thread, so a row can show a preview without loading
        // every message. One grouped query rather than one per row.
        $lastHuman = ChatbotMessage::query()
            ->whereIn('session_id', $sessions->pluck('session_id'))
            ->orderByDesc('id')
            ->get()
            ->unique('session_id')
            ->keyBy('session_id');

        $rows = $sessions->map(function (ChatbotSession $s) use ($lastHuman): array {
            return [
                'session_id' => $s->session_id,
                'name' => $s->visitorName(),
                'email' => $s->visitorEmail(),
                'state' => $s->handoff_state,
                'requested_at' => $s->handoff_requested_at?->toIso8601String(),
                'admin_id' => $s->admin_id,
                'updated_at' => $s->updated_at?->toIso8601String(),
                'preview' => $lastHuman->get($s->session_id)?->body,
            ];
        });

        return response()->json([
            'waiting' => $rows->where('state', ChatbotSession::HANDOFF_REQUESTED)->values(),
            'live' => $rows->where('state', ChatbotSession::HANDOFF_LIVE)->values(),
            'missed' => $rows->where('state', ChatbotSession::HANDOFF_MISSED)->values(),
            'all' => $rows->values(),
        ]);
    }

    /**
     * One conversation's whole transcript — the bot's turns and the human ones,
     * merged in the order they happened.
     */
    public function thread(Request $request, string $session): JsonResponse
    {
        $this->authorizeAdmin($request);

        $items = [];

        foreach (ChatbotConversation::where('session_id', $session)->orderBy('id')->get() as $c) {
            $items[] = ['from' => 'visitor', 'text' => $c->question, 'at' => $c->created_at];

            if (filled($c->answer)) {
                $items[] = ['from' => 'bot', 'text' => $c->answer, 'at' => $c->created_at];
            }
        }

        foreach (ChatbotMessage::where('session_id', $session)->orderBy('id')->get() as $m) {
            $items[] = ['from' => $m->sender, 'text' => $m->body, 'at' => $m->created_at];
        }

        usort($items, static fn ($a, $b): int => ($a['at']?->timestamp ?? 0) <=> ($b['at']?->timestamp ?? 0));

        $record = ChatbotSession::where('session_id', $session)->first();

        return response()->json([
            'state' => $record?->handoff_state,
            'name' => $record?->visitorName() ?? 'Visitor',
            'email' => $record?->visitorEmail(),
            'messages' => array_map(static fn (array $i): array => [
                'from' => $i['from'],
                'text' => $i['text'],
            ], $items),
        ]);
    }

    /** Take a conversation over: the bot steps out and this administrator is on it. */
    public function takeover(Request $request, string $session): JsonResponse
    {
        $this->authorizeAdmin($request);

        $record = ChatbotSession::where('session_id', $session)->firstOrFail();

        $record->forceFill([
            'handoff_state' => ChatbotSession::HANDOFF_LIVE,
            'admin_id' => $request->user()->id,
            'handoff_acknowledged' => true,
            'current_node' => null,
        ])->save();

        // A line the visitor sees, so the takeover is announced rather than the
        // replies simply starting.
        ChatbotMessage::create([
            'session_id' => $session,
            'sender' => ChatbotMessage::SENDER_ADMIN,
            'admin_id' => $request->user()->id,
            'body' => __('chatbot.you_are_now_with'),
        ]);

        return response()->json(['ok' => true, 'state' => ChatbotSession::HANDOFF_LIVE]);
    }

    /** An administrator's reply in a live conversation. */
    public function send(Request $request, string $session): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $record = ChatbotSession::where('session_id', $session)->firstOrFail();

        // Sending is also taking over, if it was not already — an administrator
        // who just types is plainly handling it.
        if (ChatbotSession::HANDOFF_LIVE !== $record->handoff_state) {
            $record->forceFill([
                'handoff_state' => ChatbotSession::HANDOFF_LIVE,
                'admin_id' => $request->user()->id,
                'handoff_acknowledged' => true,
                'current_node' => null,
            ])->save();
        }

        $message = ChatbotMessage::create([
            'session_id' => $session,
            'sender' => ChatbotMessage::SENDER_ADMIN,
            'admin_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        // Learn from it: the reply, paired with the question it answered, is
        // captured for the assistant to use next time — reviewed first, or
        // written straight in where that has been switched on.
        LearningBank::capture($record->fresh(), $data['body']);

        return response()->json(['ok' => true, 'id' => $message->id]);
    }

    /** End the human conversation. The visitor keeps the transcript. */
    public function end(Request $request, string $session): JsonResponse
    {
        $this->authorizeAdmin($request);

        ChatbotSession::where('session_id', $session)->update([
            'handoff_state' => ChatbotSession::HANDOFF_ENDED,
        ]);

        return response()->json(['ok' => true]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->hasPermission(AdminCapabilities::VIEW_ADMIN),
            403,
        );
    }
}
