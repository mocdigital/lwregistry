<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use App\Models\ChatbotMessage;
use App\Models\ChatbotSession;

/**
 * Handing a conversation to a person, and what the visitor is told about it.
 *
 * "Talk to a person" has to mean a person. If one is online the conversation is
 * put in the queue for them to take over; if nobody is, the visitor is not left
 * waiting on a chat that will never be answered — they are told support will
 * follow up, and the session is left on record for that to happen. Either way
 * the bot steps out: it does not answer over the top of a request for a human.
 */
final class HumanHandoff
{
    /**
     * Move a session into handoff, and return what to say to the visitor.
     *
     * @return array{state: string, text: string}
     */
    public function request(ChatbotSession $session): array
    {
        if (AdminPresence::anyOnline()) {
            $session->forceFill([
                'handoff_state' => ChatbotSession::HANDOFF_REQUESTED,
                'handoff_requested_at' => now(),
                'handoff_acknowledged' => false,
                // Bot steps out — no node to run while a person is being fetched.
                'current_node' => null,
            ])->save();

            return [
                'state' => ChatbotSession::HANDOFF_REQUESTED,
                'text' => __('chatbot.connecting_to_person'),
            ];
        }

        $session->forceFill([
            'handoff_state' => ChatbotSession::HANDOFF_MISSED,
            'handoff_requested_at' => now(),
            'handoff_acknowledged' => false,
            'current_node' => null,
        ])->save();

        // A note in the thread, so the transcript an administrator opens later
        // shows the visitor asked for someone and why no one answered.
        ChatbotMessage::create([
            'session_id' => $session->session_id,
            'sender' => ChatbotMessage::SENDER_VISITOR,
            'body' => __('chatbot.handoff_requested_offline'),
        ]);

        return [
            'state' => ChatbotSession::HANDOFF_MISSED,
            'text' => __('chatbot.no_one_available'),
        ];
    }

    /** A visitor's line while they wait for, or talk to, a person. */
    public function visitorSaid(ChatbotSession $session, string $body): void
    {
        ChatbotMessage::create([
            'session_id' => $session->session_id,
            'sender' => ChatbotMessage::SENDER_VISITOR,
            'body' => $body,
        ]);
    }
}
