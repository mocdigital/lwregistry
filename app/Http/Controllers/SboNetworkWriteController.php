<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\SboMessageSent;
use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Models\SboConnection;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\SboMessageAttachment;
use App\Models\SupportEnquiry;
use App\Notifications\SboConnectionAcceptedNotification;
use App\Notifications\SboConnectionRequestedNotification;
use App\Notifications\SboMessageReceivedNotification;
use App\Support\Privacy;
use App\Support\RegistrySbo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Network, support and compliance writes.
 *
 * All of these had a designed control and no endpoint — Connect, Accept,
 * Decline, Send, the support form and the document upload on application
 * step 3. The models already existed; only the routes were missing.
 */
final class SboNetworkWriteController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        $mine = $this->mySboIds($request);

        $data = $request->validate([
            'requester_sbo_id' => ['required', Rule::in($mine)],
            'recipient_sbo_id' => ['required', 'integer', 'exists:sbos,id', 'different:requester_sbo_id'],
        ]);

        /*
         * A business that has closed connection requests in Settings › Privacy
         * is refused here, not only in the button's disabled state — the button
         * is a courtesy and this is the rule.
         */
        $recipient = Sbo::find($data['recipient_sbo_id']);

        if ( ! Privacy::allowsFor($recipient, Privacy::CONNECTIONS)) {
            return back()->with('status', 'connection-closed');
        }

        // The pair is unique regardless of direction, so an existing row in
        // either orientation is reused instead of creating a mirror request.
        $existing = SboConnection::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('requester_sbo_id', $data['requester_sbo_id'])
                    ->where('recipient_sbo_id', $data['recipient_sbo_id']))
                ->orWhere(fn ($w) => $w->where('requester_sbo_id', $data['recipient_sbo_id'])
                    ->where('recipient_sbo_id', $data['requester_sbo_id'])))
            ->first();

        /*
         * A row in either direction used to refuse the request outright,
         * whatever its status — so a declined request could never be raised
         * again, and two SBOs who disconnected could never reconnect. A row
         * that is no longer a live connection is reused instead.
         */
        if ($existing && ! $existing->isReconnectable()) {
            return back()->with('status', 'connection-exists');
        }

        if ($existing) {
            $existing->forceFill([
                'requester_sbo_id' => $data['requester_sbo_id'],
                'recipient_sbo_id' => $data['recipient_sbo_id'],
                'status' => SboConnection::STATUS_PENDING,
                'responded_at' => null,
            ])->save();

            $existing->recipient?->creator?->notify(
                new SboConnectionRequestedNotification($existing->fresh(['requester', 'recipient'])),
            );

            return back()->with('status', 'connection-requested');
        }

        $connection = SboConnection::create([...$data, 'status' => SboConnection::STATUS_PENDING]);

        // The recipient's owner gets the bell entry and the mail. The Volt
        // network component already did this; the HTTP endpoint did not, so
        // requests raised from Vue arrived silently.
        $connection->recipient?->creator?->notify(
            new SboConnectionRequestedNotification($connection->fresh(['requester', 'recipient'])),
        );

        return back()->with('status', 'connection-requested');
    }

    /** Accept or decline an incoming request. */
    public function respond(Request $request, SboConnection $connection): RedirectResponse
    {
        abort_unless($this->mySboIds($request)->contains($connection->recipient_sbo_id), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'declined'])],
        ]);

        $connection->forceFill([
            'status' => $data['status'],
            'responded_at' => now(),
        ])->save();

        /*
         * Accepting opens the channel, LinkedIn-style: without a conversation
         * there is nothing for either side to message into, and the messages
         * tab would stay empty however many connections were accepted.
         * firstOrCreate so re-accepting cannot spawn duplicates.
         */
        if (SboConnection::STATUS_ACCEPTED === $data['status']) {
            $this->conversationFor($connection);

            $connection->requester?->creator?->notify(
                new SboConnectionAcceptedNotification($connection->fresh(['requester', 'recipient'])),
            );
        }

        return back()->with('status', 'connection-' . $data['status']);
    }

    /**
     * Disconnect from an accepted connection.
     *
     * The network card's (x) posted `respond(declined)`, which only the
     * *recipient* of the original request is allowed to do — so the owner who
     * sent the request in the first place got a bare 403 when trying to remove
     * the connection, and "decline a request you were never sent" was the wrong
     * verb regardless.
     *
     * Either side may disconnect, and the pair can connect again afterwards.
     * The conversation and its messages survive: history is not the connection.
     */
    public function disconnect(Request $request, SboConnection $connection): RedirectResponse
    {
        $mine = $this->mySboIds($request);

        abort_unless(
            $mine->contains($connection->requester_sbo_id)
                || $mine->contains($connection->recipient_sbo_id),
            403,
        );

        // A pending request is withdrawn or refused through `respond`; this is
        // for connections that were actually made.
        abort_unless($connection->isAccepted(), 422, 'This connection is not active.');

        $connection->forceFill([
            'status' => SboConnection::STATUS_REMOVED,
            'responded_at' => now(),
        ])->save();

        return back()->with('status', 'connection-removed');
    }

    /**
     * Open a conversation from the composer.
     *
     * The inbox's compose button had nowhere to post: a conversation only ever
     * came into being as a side effect of accepting a connection, so there was
     * no way to start one with a connection accepted before that behaviour
     * existed. Reuses the connection's conversation when there already is one,
     * so composing twice does not fork the thread.
     */
    public function storeConversation(Request $request): RedirectResponse
    {
        $mine = $this->mySboIds($request);

        $data = $request->validate([
            'sbo_id' => ['required', Rule::in($mine)],
            'recipient_sbo_id' => ['required', 'integer', 'exists:sbos,id', 'different:sbo_id'],
            'body' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['array', 'max:5'],
            'attachments.*' => self::attachmentRules(),
        ]);

        // You can only message an SBO you are actually connected to — the same
        // rule the design states ("accepted connections") and the only one that
        // makes the private channel safe.
        $connection = SboConnection::query()
            ->where('status', SboConnection::STATUS_ACCEPTED)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('requester_sbo_id', $data['sbo_id'])
                    ->where('recipient_sbo_id', $data['recipient_sbo_id']))
                ->orWhere(fn ($w) => $w->where('requester_sbo_id', $data['recipient_sbo_id'])
                    ->where('recipient_sbo_id', $data['sbo_id'])))
            ->first();

        abort_if(null === $connection, 422, 'You can only message an accepted connection.');

        $conversation = $this->conversationFor($connection);

        $files = $request->file('attachments', []);

        if (filled($data['body'] ?? null) || [] !== $files) {
            $this->post(
                $conversation,
                (int) $data['sbo_id'],
                $request->user()->id,
                (string) ($data['body'] ?? ''),
                $files,
            );
        }

        return redirect()
            ->route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id])
            ->with('status', 'conversation-started');
    }

    /**
     * Create a member-owned group chat.
     *
     * The creator names it and seeds it with businesses they are connected to.
     * Membership is theirs to manage from here (add and remove); everyone in
     * the group can post, the same as any other conversation they are part of.
     */
    public function storeGroup(Request $request): RedirectResponse
    {
        $mine = $this->mySboIds($request);
        $sboId = $mine->first();
        abort_if(null === $sboId, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'member_sbo_ids' => ['required', 'array', 'min:1', 'max:100'],
            'member_sbo_ids.*' => ['integer', 'distinct'],
        ]);

        // Only the creator's accepted connections may be seeded in — the same
        // rule that governs starting a direct conversation.
        $members = $this->connectionSubset((int) $sboId, $data['member_sbo_ids']);
        abort_if($members->isEmpty(), 422, 'Add at least one of your connections.');

        $conversation = SboConversation::create([
            'created_by_sbo_id' => $sboId,
            'type' => SboConversation::TYPE_GROUP,
            'name' => mb_trim($data['name']),
            'last_message_at' => now(),
        ]);

        $conversation->participants()->syncWithoutDetaching(
            $members->push((int) $sboId)->unique()->all()
        );

        return redirect()
            ->route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id])
            ->with('status', 'group-created');
    }

    /** Add more of the owner's connections to a group they created. */
    public function addGroupMembers(Request $request, SboConversation $conversation): RedirectResponse
    {
        $sboId = $this->ownedGroupSboId($request, $conversation);

        $data = $request->validate([
            'member_sbo_ids' => ['required', 'array', 'min:1', 'max:100'],
            'member_sbo_ids.*' => ['integer', 'distinct'],
        ]);

        $members = $this->connectionSubset($sboId, $data['member_sbo_ids']);
        abort_if($members->isEmpty(), 422, 'Choose connections to add.');

        $conversation->participants()->syncWithoutDetaching($members->all());

        return back()->with('status', 'group-members-added');
    }

    /** Remove a member from a group the requester owns. The owner stays. */
    public function removeGroupMember(Request $request, SboConversation $conversation): RedirectResponse
    {
        $sboId = $this->ownedGroupSboId($request, $conversation);

        $data = $request->validate(['member_sbo_id' => ['required', 'integer']]);
        $target = (int) $data['member_sbo_id'];

        abort_if($target === $sboId, 422, 'The owner cannot be removed.');

        $conversation->participants()->detach($target);

        return back()->with('status', 'group-member-removed');
    }

    /**
     * Leave a group. Anyone but the owner, who manages it — the owner leaving
     * would orphan the roster.
     */
    public function leaveGroup(Request $request, SboConversation $conversation): RedirectResponse
    {
        abort_unless(SboConversation::TYPE_GROUP === $conversation->type, 404);

        $mine = $this->mySboIds($request);
        $sboId = $conversation->participantSboId($mine);
        abort_if(null === $sboId, 403);

        abort_if(
            (int) $conversation->created_by_sbo_id === (int) $sboId,
            422,
            'The owner cannot leave their own group.',
        );

        $conversation->participants()->detach($sboId);

        return redirect()
            ->route('sbo.network.index', ['tab' => 'messages'])
            ->with('status', 'group-left');
    }

    /**
     * Take a group conversation aside into a private one.
     *
     * This is all three of "message the admin privately", "the admin messages
     * a member privately" and "members message each other privately" — they
     * are the same act, and separating them by role would mean three
     * endpoints enforcing one rule.
     *
     * The rule: two businesses who share a group conversation may open a
     * direct one, whether or not they hold an accepted connection. Being in a
     * room together IS the relationship — requiring a connection first would
     * mean you can speak to someone in front of thirty people but not to them
     * alone, which is backwards.
     *
     * It is find-or-create, so asking twice reopens the same thread rather
     * than splitting the history in two.
     */
    public function privateConversation(Request $request, SboConversation $conversation): RedirectResponse
    {
        $mine = $this->mySboIds($request);
        $sboId = $conversation->participantSboId($mine);

        // Not my conversation, so not a room I can take anyone aside from.
        abort_if(null === $sboId, 403);

        $data = $request->validate([
            /*
             * notIn rather than `different:`, which compares two REQUEST
             * fields — `different:7` looks for a field named "7", finds
             * nothing, and passes. It would have let a business open a private
             * conversation with itself.
             */
            'recipient_sbo_id' => ['required', 'integer', 'exists:sbos,id', Rule::notIn([$sboId])],
        ]);

        $recipientId = (int) $data['recipient_sbo_id'];

        // The recipient has to be in the same room. Otherwise this endpoint is
        // a way to message any business in the registry unsolicited.
        abort_unless(
            $conversation->participants()->whereKey($recipientId)->exists(),
            422,
            'That business is not in this conversation.',
        );

        $private = $this->directConversationBetween($sboId, $recipientId);

        return redirect()
            ->route('sbo.network.index', ['tab' => 'messages', 'conversation' => $private->id])
            ->with('status', 'conversation-started');
    }

    /**
     * Open the member's conversation with the registry.
     *
     * One thread per member, whichever end starts it and wherever it is
     * started from — a room's "Message admin", or an administrator reaching
     * out. Splitting it per room would scatter one relationship across as many
     * threads as the member has joined, and neither end would hold the record.
     *
     * The registry speaks as a listing of its own rather than as whichever
     * member of staff picked the thread up: the member is writing to the
     * registry, and staff change.
     */
    public function messageAdmin(Request $request): RedirectResponse
    {
        $mine = $this->mySboIds($request);
        $sboId = $mine->first();

        // Nothing to write from. A member with no listing has no side of a
        // conversation to be on.
        abort_if(null === $sboId, 403);

        $registry = RegistrySbo::ensure();

        $conversation = $this->directConversationBetween((int) $sboId, $registry->id);

        return redirect()
            ->route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversation->id])
            ->with('status', 'conversation-started');
    }

    public function message(Request $request, SboConversation $conversation): RedirectResponse
    {
        $mine = $this->mySboIds($request);

        /*
         * Was: "the conversation has a connection and one side is mine", which
         * both refused every bid/group conversation (no connection row) and
         * then attributed the message to `$mine->first()` — the wrong SBO for
         * an owner with more than one listing. participantSboId answers both
         * questions at once, and a null means this is not my conversation.
         */
        $sboId = $conversation->participantSboId($mine);

        abort_if(null === $sboId, 403);

        /*
         * The body is required only when nothing is attached. A photograph of
         * the goods with no caption is a message; an empty send is not.
         */
        $data = $request->validate([
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:5000'],
            'attachments' => ['array', 'max:5'],
            'attachments.*' => self::attachmentRules(),
            /*
             * The message being answered, which must be in THIS conversation.
             * Without that check a reply could quote a message from a thread
             * the sender cannot read, and the quote is rendered to everyone
             * here.
             */
            'reply_to_message_id' => [
                'nullable',
                Rule::exists('sbo_messages', 'id')->where('conversation_id', $conversation->id),
            ],
        ]);

        $this->post(
            $conversation,
            $sboId,
            $request->user()->id,
            (string) ($data['body'] ?? ''),
            $request->file('attachments', []),
            isset($data['reply_to_message_id']) ? (int) $data['reply_to_message_id'] : null,
        );

        return back()->with('status', 'message-sent');
    }

    /**
     * Star or unstar a conversation — the chat header's star, which was a
     * disabled button.
     *
     * Membership is checked the same way posting into the thread is, so a
     * conversation you cannot read is one you cannot bookmark either.
     */
    /**
     * Mute or unmute a conversation for the member who asked.
     *
     * A room can have hundreds of businesses in it and every message notifies
     * everyone; without this, one busy room is a reason to stop reading the
     * whole inbox. Per participant, so muting is one member's decision about
     * one room and not something anybody else can see or set.
     */
    public function mute(Request $request, SboConversation $conversation): RedirectResponse
    {
        $mine = $this->mySboIds($request);
        $sboId = $conversation->participantSboId($mine);

        abort_if(null === $sboId, 403);

        $muted = null !== $conversation->participants()
            ->whereKey($sboId)
            ->first()?->pivot?->muted_at;

        $conversation->participants()->updateExistingPivot($sboId, [
            'muted_at' => $muted ? null : now(),
        ]);

        return back()->with('status', $muted ? 'conversation-unmuted' : 'conversation-muted');
    }

    public function star(Request $request, SboConversation $conversation): RedirectResponse
    {
        abort_if(null === $conversation->participantSboId($this->mySboIds($request)), 403);

        $user = $request->user();

        $conversation->starredBy()->syncWithoutDetaching([$user->id]);

        return back()->with('status', 'conversation-starred');
    }

    public function unstar(Request $request, SboConversation $conversation): RedirectResponse
    {
        abort_if(null === $conversation->participantSboId($this->mySboIds($request)), 403);

        $conversation->starredBy()->detach($request->user()->id);

        return back()->with('status', 'conversation-unstarred');
    }

    /** Mark everything in a conversation as read for this user. */
    public function readMessages(Request $request, SboConversation $conversation): RedirectResponse
    {
        // Was unauthorised: any signed-in user could clear anyone's unread
        // count by posting a conversation id.
        abort_if(null === $conversation->participantSboId($this->mySboIds($request)), 403);

        SboMessage::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('status', 'messages-read');
    }

    public function supportEnquiry(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'sbo_number' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        SupportEnquiry::create([
            ...$data,
            // Support is reachable signed out, so the user link is optional.
            'user_id' => $request->user()?->id,
            'sbo_id' => $request->user()?->sbos()->value('id'),
            'status' => 'open',
        ]);

        return back()->with('status', 'enquiry-sent');
    }

    /** Application step 3 — compliance document upload. */
    public function storeDocument(Request $request): RedirectResponse
    {
        $mine = $this->mySboIds($request);

        $data = $request->validate([
            'sbo_id' => ['required', Rule::in($mine)],
            'compliance_document_type_id' => ['required', 'integer', 'exists:compliance_document_types,id'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        // Document uploads open once a payment has been MADE — settled, or
        // submitted and awaiting an admin's confirmation. The owner does not wait
        // for that confirmation to upload; the admin reviews once steps are done.
        abort_unless(
            Sbo::whereKey($data['sbo_id'])->first()?->feesPaid() ?? false,
            403,
            'Make your registration fee payment before uploading compliance documents.',
        );

        $doc = SboComplianceDocument::firstOrNew([
            'sbo_id' => $data['sbo_id'],
            'compliance_document_type_id' => $data['compliance_document_type_id'],
        ]);

        // Re-uploading after a rejection resets the review, rather than
        // leaving a stale reason attached to fresh evidence.
        $doc->forceFill([
            'status' => 'pending',
            'rejection_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();

        /*
         * `SboComplianceDocument::COLLECTION`, not the literal 'documents'.
         *
         * The collection is registered as 'document' (singular) and `file()`
         * reads `getFirstMedia('document')`, so uploads landing in 'documents'
         * were stored on disk and then invisible: the progress screen showed no
         * file name, no download link, and the owner had no way to tell whether
         * their certificate had arrived. The Livewire uploader always used the
         * constant; the HTTP endpoint did not.
         */
        $doc->addMediaFromRequest('document')->toMediaCollection(SboComplianceDocument::COLLECTION);

        return back()->with('status', 'document-uploaded');
    }

    /**
     * Manual payment — the owner pays in the Espees Max app and uploads proof.
     * The Espees checkout has had an endpoint all along
     * (sbo.payments.espees.start); this is the other half of that screen,
     * which had a designed upload panel and nowhere to send the file.
     */
    public function storePaymentProof(Request $request, Sbo $sbo): RedirectResponse
    {
        abort_unless($this->mySboIds($request)->contains($sbo->id), 403);

        $request->validate([
            /*
             * `mimetypes:` as well as `mimes:`, matching what the payment_proof
             * collection accepts exactly. The collection refuses a file whose
             * real type is not on that list by throwing, which reaches the
             * uploader as a 500 rather than as "that file is not a receipt".
             */
            'proof' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $proof = \App\Models\SboPaymentProof::firstOrNew(['sbo_id' => $sbo->id]);
        // Re-uploading after a rejection clears the old reason rather than
        // leaving it attached to new evidence.
        $proof->forceFill([
            'status' => 'pending',
            'rejection_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();

        /*
         * SboPaymentProof::COLLECTION, not the literal 'proofs'. Only the
         * registered collection is routed to the private `compliance` disk and
         * read back by SboPaymentProof::file(), which is what every reviewer
         * screen asks. Filed under 'proofs' the receipt went to the PUBLIC disk
         * and the admin's proof row read "Not uploaded" forever — so no manual
         * payment could be approved, and none reached the Payments queue
         * either (it gates on the same file()).
         */
        $proof->addMediaFromRequest('proof')->toMediaCollection(\App\Models\SboPaymentProof::COLLECTION);

        // Flags the fee as awaiting an admin decision, not yet paid. The owner
        // stays on the payment page seeing "awaiting approval" — documents open
        // only once an admin approves the payment.
        $sbo->forceFill(['payment_submitted_at' => now()])->save();

        return back()->with('status', 'proof-uploaded');
    }

    /**
     * What an attachment must be.
     *
     * `mimetypes:` reads the file's real type rather than trusting its name,
     * and `mimes:` gates the extension as well — both, because a .png that is
     * really a PHP script and a script named .png are different attacks.
     *
     * The accepted list is closed and excludes SVG: it is markup, browsers
     * execute the script inside it, and it would be served from our own origin.
     *
     * @return array<int, string>
     */
    private static function attachmentRules(): array
    {
        return [
            'file',
            'mimetypes:' . implode(',', array_keys(SboMessageAttachment::ACCEPTED)),
            'mimes:' . implode(',', SboMessageAttachment::EXTENSIONS),
            'max:' . SboMessageAttachment::MAX_KILOBYTES,
        ];
    }

    /** The requester's SBO that owns this group, or abort. */
    private function ownedGroupSboId(Request $request, SboConversation $conversation): int
    {
        abort_unless(SboConversation::TYPE_GROUP === $conversation->type, 404);

        $mine = $this->mySboIds($request);

        abort_unless(
            $mine->contains($conversation->created_by_sbo_id),
            403,
            'Only the group owner can manage members.',
        );

        return (int) $conversation->created_by_sbo_id;
    }

    /**
     * Of the given ids, those that are accepted connections of $sboId (never
     * $sboId itself).
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, int>
     */
    private function connectionSubset(int $sboId, array $ids): Collection
    {
        $connected = SboConnection::query()
            ->where('status', SboConnection::STATUS_ACCEPTED)
            ->where(fn ($q) => $q->where('requester_sbo_id', $sboId)->orWhere('recipient_sbo_id', $sboId))
            ->get(['requester_sbo_id', 'recipient_sbo_id'])
            ->map(fn (SboConnection $c): int => (int) $c->requester_sbo_id === $sboId
                ? (int) $c->recipient_sbo_id
                : (int) $c->requester_sbo_id)
            ->unique();

        return collect($ids)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => $id === $sboId)
            ->filter(fn (int $id): bool => $connected->contains($id))
            ->values();
    }

    /**
     * The direct conversation for a connection, created if it does not exist.
     *
     * Also fills the participants pivot. The pivot is how bid and group
     * conversations know their members, and the Volt network component reads
     * it; a conversation created without it looked empty over there and had no
     * recipients to notify on send.
     */
    private function conversationFor(SboConnection $connection): SboConversation
    {
        $conversation = SboConversation::firstOrCreate(
            ['connection_id' => $connection->id],
            [
                'created_by_sbo_id' => $connection->recipient_sbo_id,
                'type' => SboConversation::TYPE_DIRECT,
                'last_message_at' => now(),
            ],
        );

        $conversation->participants()->syncWithoutDetaching([
            $connection->requester_sbo_id,
            $connection->recipient_sbo_id,
        ]);

        return $conversation;
    }

    /**
     * Write the message, bump the conversation and push it to whoever has the
     * thread open. Shared by compose and by replying.
     */
    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function post(
        SboConversation $conversation,
        int $sboId,
        int|string $userId,
        string $body,
        array $files = [],
        ?int $replyToId = null,
    ): SboMessage {
        $message = SboMessage::create([
            'conversation_id' => $conversation->id,
            'reply_to_message_id' => $replyToId,
            'sbo_id' => $sboId,
            'user_id' => $userId,
            'body' => $body,
        ]);

        foreach ($files as $file) {
            SboMessageAttachment::storeFor($message, $file);
        }

        $conversation->forceFill(['last_message_at' => now()])->save();
        $conversation->participants()->updateExistingPivot($sboId, ['last_read_at' => now()]);

        $message->load(['sbo', 'attachments', 'replyTo.sbo']);

        // Live delivery to whoever has the thread open. Queued by the event
        // contract, so a Reverb outage cannot fail the send — the row is
        // already committed and the next page load shows it either way.
        SboMessageSent::dispatch($message);

        /*
         * And the bell, for everyone who does not. Recipients come from the
         * participants pivot, falling back to the other side of the connection
         * for conversations created before the pivot was being filled.
         */
        $recipients = $conversation->participants()
            ->where('sbos.id', '!=', $sboId)
            /*
             * Muted members still receive the message and it still counts as
             * unread — muting silences the bell, it does not leave the room.
             */
            ->wherePivotNull('muted_at')
            ->with('creator')
            ->get()
            ->pluck('creator');

        if ($recipients->isEmpty() && $conversation->sboConnection) {
            $other = $conversation->sboConnection->requester_sbo_id === $sboId
                ? $conversation->sboConnection->recipient
                : $conversation->sboConnection->requester;
            $recipients = collect([$other?->creator]);
        }

        $recipients = $recipients->filter()->unique('id')->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new SboMessageReceivedNotification($message));
        }

        return $message;
    }

    /**
     * The direct thread between two businesses, opened if it does not exist.
     *
     * Distinct from conversationFor(), which keys on a connection row. A pair
     * who met in a group may have no connection at all, so the pair itself is
     * the key here: every direct conversation where both are participants and
     * nobody else is.
     */
    private function directConversationBetween(int $sboId, int $recipientId): SboConversation
    {
        $existing = SboConversation::query()
            ->where('type', SboConversation::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->whereKey($sboId))
            ->whereHas('participants', fn ($q) => $q->whereKey($recipientId))
            /*
             * And nobody else. `has(..., '=', 2)` is a correlated count in the
             * WHERE clause; withCount + having is the obvious spelling and
             * SQLite rejects it outright as a HAVING on a non-aggregate query.
             */
            ->has('participants', '=', 2)
            ->first();

        if ($existing instanceof SboConversation) {
            return $existing;
        }

        $conversation = SboConversation::create([
            'created_by_sbo_id' => $sboId,
            'type' => SboConversation::TYPE_DIRECT,
            'last_message_at' => now(),
        ]);

        $conversation->participants()->syncWithoutDetaching([$sboId, $recipientId]);

        return $conversation;
    }

    private function mySboIds(Request $request): Collection
    {
        return $request->user()->sbos()->pluck('id');
    }
}
