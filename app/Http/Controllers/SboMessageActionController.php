<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SboMessage;
use App\Models\SboMessageReaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The things you can do to a message that are not writing another one.
 *
 * React, pin and delete. Each is small, and each needs the same question
 * answered first — is this a conversation you are in — so they live together
 * rather than being three endpoints repeating that check.
 *
 * Reply is deliberately NOT here: a reply is a message, and it goes through
 * the same endpoint every other message does, carrying the id of the one it
 * answers.
 */
final class SboMessageActionController extends Controller
{
    /**
     * Add or remove an emoji.
     *
     * The same emoji twice is a toggle. Reacting is a light gesture and taking
     * it back should be equally light — a separate "unreact" would mean the
     * interface has to show one control or the other depending on state.
     */
    public function react(Request $request, SboMessage $message): RedirectResponse
    {
        $this->authorizeMessage($request, $message);

        $data = $request->validate([
            'emoji' => ['required', 'string', Rule::in(SboMessageReaction::ALLOWED)],
        ]);

        $existing = SboMessageReaction::query()
            ->where('message_id', $message->id)
            ->where('user_id', $request->user()->id)
            ->where('emoji', $data['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();

            return back()->with('status', 'reaction-removed');
        }

        SboMessageReaction::create([
            'message_id' => $message->id,
            'user_id' => $request->user()->id,
            'emoji' => $data['emoji'],
        ]);

        return back()->with('status', 'reaction-added');
    }

    /**
     * Pin, for everyone in the conversation.
     *
     * One at a time: a list of pinned messages is a second thread to read, and
     * the point of pinning is to say "this one". Pinning something else moves
     * the pin rather than adding to it.
     */
    public function pin(Request $request, SboMessage $message): RedirectResponse
    {
        $this->authorizeMessage($request, $message);

        if (null !== $message->pinned_at) {
            $message->forceFill(['pinned_at' => null, 'pinned_by_user_id' => null])->save();

            return back()->with('status', 'message-unpinned');
        }

        SboMessage::query()
            ->where('conversation_id', $message->conversation_id)
            ->whereNotNull('pinned_at')
            ->update(['pinned_at' => null, 'pinned_by_user_id' => null]);

        $message->forceFill([
            'pinned_at' => now(),
            'pinned_by_user_id' => $request->user()->id,
        ])->save();

        return back()->with('status', 'message-pinned');
    }

    /**
     * Delete, for everyone.
     *
     * Only your own. A tombstone rather than a removed row: a reply that
     * quotes this message needs something to quote, and a thread that silently
     * loses a line reads as though it was never sent.
     */
    public function destroy(Request $request, SboMessage $message): RedirectResponse
    {
        $this->authorizeMessage($request, $message);

        abort_unless((string) $message->user_id === (string) $request->user()->id, 403);

        $message->forceFill([
            // The text goes; the row stays.
            'body' => '',
            'deleted_for_all_at' => now(),
        ])->save();

        // The files go with it — a deleted message that still serves its
        // attachment has not been deleted.
        $message->attachments()->delete();

        return back()->with('status', 'message-deleted');
    }

    /** Only a message in a conversation this user is part of. */
    private function authorizeMessage(Request $request, SboMessage $message): void
    {
        $message->loadMissing('conversation');

        abort_if(null === $message->conversation, 404);
        abort_if(null === $message->conversation->participantSboId($this->mySboIds($request)), 403);
    }

    /** @return Collection<int, int> */
    private function mySboIds(Request $request): Collection
    {
        return $request->user()->sbos()->pluck('id');
    }
}
