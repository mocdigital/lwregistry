<?php

use App\Concerns\ModeratesSboMessages;
use App\Events\SboMessageSent;
use App\Http\Controllers\SboReportController;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\SboMessageReaction;
use App\Notifications\SboMessageReceivedNotification;
use App\Support\RegistrySbo;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Category group chats — the moderation and participation surface.
 *
 * Every verified business is put into the room for its category automatically,
 * with no join step and no invitation. The registry (the admin's listing) sits
 * in every room, so from here an administrator can do everything a member can
 * do to a message — reply, reply privately, react, pin, translate, report and
 * delete — acting as the registry throughout. The per-message actions come from
 * ModeratesSboMessages, shared with the private inbox.
 *
 * There is deliberately no "remove member" action: membership is derived from
 * verification by App\Support\IndustryGroupChat::sync() on every SBO save, so a
 * detach would be silently undone the next time anything touched that business.
 */
new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Category Groups - Admin Dashboard',
        'dataPage' => 'network-groups',
        'pageTitle' => 'Category Groups',
    ]
)] class extends Component {
    use ModeratesSboMessages;

    public ?int $selectedId = null;

    /** The composer, and what it is aimed at. */
    public string $reply = '';

    /** A group message being quoted in the reply. */
    public ?int $replyingToId = null;

    /** When set, the composer sends a private 1:1 to this business instead. */
    public ?int $privateToSboId = null;

    public ?string $privateToName = null;

    /** Recent messages shown per room. */
    private const MESSAGE_LIMIT = 40;

    public function mount(): void
    {
        $this->selectedId = SboConversation::query()
            ->where('type', SboConversation::TYPE_INDUSTRY)
            ->orderByDesc('last_message_at')
            ->value('id');
    }

    /**
     * Live delivery for the open room. A member posting broadcasts on the room's
     * conversation channel, and the screen appends it without a reload; the
     * listener follows the selected room and re-subscribes when it changes.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        if (null === $this->selectedId) {
            return [];
        }

        return [
            "echo-private:sbo.conversation.{$this->selectedId},.message.sent" => 'onLiveMessage',
        ];
    }

    /** A message arrived on the open room; the round trip re-renders with(). */
    public function onLiveMessage(): void
    {
        // Nothing to do: with() re-reads the room's messages on re-render.
    }

    public function select(int $id): void
    {
        // Scoped to group rooms: this screen must not become a way to read a
        // direct conversation between two businesses.
        $this->selectedId = SboConversation::query()
            ->where('type', SboConversation::TYPE_INDUSTRY)
            ->whereKey($id)
            ->value('id');

        $this->resetComposer();
        $this->translations = [];
    }

    /* --------------------------------------------- reply / reply privately --- */

    public function startReplyTo(int $messageId): void
    {
        if (null === $this->messageInScope($messageId)) {
            return;
        }

        $this->privateToSboId = null;
        $this->privateToName = null;
        $this->replyingToId = $messageId;
    }

    public function startPrivateReply(int $messageId): void
    {
        $message = $this->messageInScope($messageId);

        if (null === $message) {
            return;
        }

        $this->replyingToId = null;
        $this->privateToSboId = (int) $message->sbo_id;
        $this->privateToName = $message->sbo?->name;
    }

    public function cancelReply(): void
    {
        $this->resetComposer();
    }

    public function sendReply(): void
    {
        $data = $this->validate(['reply' => ['required', 'string', 'max:5000']]);

        $registry = RegistrySbo::ensure();
        $wasPrivate = null !== $this->privateToSboId;

        if ($wasPrivate) {
            $conversation = $this->registryDirectWith((int) $this->privateToSboId, $registry);
            $replyTo = null;
        } else {
            $conversation = SboConversation::query()
                ->where('type', SboConversation::TYPE_INDUSTRY)
                ->find($this->selectedId);

            if (! $conversation) {
                return;
            }

            $replyTo = $this->replyingToId;
        }

        $message = SboMessage::create([
            'conversation_id' => $conversation->id,
            // As the registry, not as the person typing; user_id records who.
            'sbo_id' => $registry->id,
            'user_id' => auth()->id(),
            'body' => $data['reply'],
            'reply_to_message_id' => $replyTo,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();
        $conversation->participants()->updateExistingPivot($registry->id, ['last_read_at' => now()]);

        $message->load(['sbo', 'attachments']);
        SboMessageSent::dispatch($message);

        $recipients = $conversation->participants()
            ->where('sbos.id', '!=', $registry->id)
            ->with('creator')
            ->get()
            ->pluck('creator')
            ->filter()
            ->unique('id')
            ->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new SboMessageReceivedNotification($message));
        }

        $this->resetComposer();
        session()->flash('success', $wasPrivate ? 'Private reply sent.' : 'Reply posted to the group.');
    }

    /* ------------------------------------------------------------- helpers --- */

    private function resetComposer(): void
    {
        $this->reply = '';
        $this->replyingToId = null;
        $this->privateToSboId = null;
        $this->privateToName = null;
    }

    /** Tighter than the trait default: only a message in a group room. */
    protected function messageInScope(int $id): ?SboMessage
    {
        return SboMessage::query()
            ->whereKey($id)
            ->whereHas('conversation', fn ($q) => $q->where('type', SboConversation::TYPE_INDUSTRY))
            ->with(['sbo', 'conversation'])
            ->first();
    }

    public function with(): array
    {
        $rooms = SboConversation::query()
            ->where('type', SboConversation::TYPE_INDUSTRY)
            ->withCount(['participants as member_count', 'messages as message_count'])
            ->orderBy('industry')
            ->get();

        $selected = $this->selectedId
            ? SboConversation::query()
                ->where('type', SboConversation::TYPE_INDUSTRY)
                ->with(['participants.creator'])
                ->withCount(['participants as member_count', 'messages as message_count'])
                ->find($this->selectedId)
            : null;

        return [
            'rooms' => $rooms,
            'selected' => $selected,
            'messages' => $selected
                ? SboMessage::query()
                    ->where('conversation_id', $selected->id)
                    ->with(['sbo', 'user', 'reactions', 'replyTo.sbo'])
                    ->latest()
                    ->limit(self::MESSAGE_LIMIT)
                    ->get()
                : collect(),
            'reactionSet' => SboMessageReaction::ALLOWED,
            'reportReasons' => SboReportController::reasons(),
            'totalMembers' => $rooms->sum('member_count'),
            'totalMessages' => $rooms->sum('message_count'),
        ];
    }
}; ?>

{{-- Poll keeps the open room's messages and the room list fresh without a
     reload; when Reverb is enabled the open room also updates instantly over
     Echo (getListeners), with this as the fallback. --}}
<div wire:poll.6s>
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <div>
            <h1 class="h5 mb-1">Category Groups</h1>
            <p class="text-muted mb-0">
                Every verified business is placed in the group chat for its category automatically.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <span class="badge-primary-soft rounded-pill">{{ $rooms->count() }} rooms</span>
            <span class="badge-secondary-soft rounded-pill">{{ $totalMembers }} memberships</span>
            <span class="badge-secondary-soft rounded-pill">{{ $totalMessages }} messages</span>
        </div>
    </div>

    <div class="row g-4">
        {{-- Rooms --}}
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">Rooms</div>
                <div class="card-body p-0">
                    @forelse($rooms as $room)
                        <button
                            type="button"
                            wire:click="select({{ $room->id }})"
                            class="btn btn-link w-100 text-start text-decoration-none d-block px-3 py-3 border-bottom rounded-0
                                   {{ $selected?->id === $room->id ? 'bg-light' : '' }}"
                        >
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <span class="fw-semibold text-body">{{ $room->industry }}</span>
                                <span class="badge-secondary-soft rounded-pill">{{ $room->member_count }}</span>
                            </div>
                            <div class="small text-muted-soft">
                                {{ $room->message_count }} {{ Str::plural('message', $room->message_count) }}
                                @if($room->last_message_at)
                                    <span class="mx-1">&middot;</span>{{ $room->last_message_at->diffForHumans() }}
                                @endif
                            </div>
                        </button>
                    @empty
                        <div class="admin-empty">
                            <i class="bi bi-people" aria-hidden="true"></i>
                            <h3>No category rooms yet</h3>
                            <p>A room is created the first time a business is verified in that category.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Selected room --}}
        <div class="col-lg-8">
            @if($selected)
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <span>{{ $selected->industry }} Group</span>
                        <span class="small text-muted-soft">
                            {{ $selected->member_count }} {{ Str::plural('member', $selected->member_count) }}
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="small text-muted-soft mb-2">Members</div>
                        <div class="table-responsive" style="max-height: 220px; overflow-y: auto;">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Business</th>
                                        <th>Owner</th>
                                        <th>Joined</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($selected->participants as $member)
                                        <tr>
                                            <td>
                                                <a href="{{ route('admin.sbos.show', $member) }}">{{ $member->name }}</a>
                                            </td>
                                            <td class="text-muted-soft">{{ $member->creator?->email ?? '—' }}</td>
                                            <td class="text-muted-soft">
                                                {{ $member->pivot?->created_at?->format('M d, Y') ?? '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Messages</div>
                    <div class="card-body">
                        @forelse($messages as $message)
                            @include('livewire.admin.network._message-row', ['message' => $message, 'reactionSet' => $reactionSet, 'reportReasons' => $reportReasons, 'translations' => $translations, 'first' => $loop->first])
                        @empty
                            <div class="admin-empty">
                                <i class="bi bi-chat-dots" aria-hidden="true"></i>
                                <h3>Nothing posted yet</h3>
                                <p>Messages members send to this room will appear here.</p>
                            </div>
                        @endforelse
                    </div>

                    {{-- Composer: reply to the group, or privately to a member. --}}
                    <div class="card-footer">
                        @if($replyingToId || $privateToSboId)
                            <div class="small text-muted-soft mb-2 d-flex justify-content-between align-items-center">
                                <span>
                                    @if($privateToSboId)
                                        <i class="bi bi-chat-left-text"></i> Replying privately to <strong>{{ $privateToName }}</strong>
                                    @else
                                        <i class="bi bi-reply"></i> Replying to a message in this group
                                    @endif
                                </span>
                                <button type="button" class="btn btn-sm btn-link p-0" wire:click="cancelReply">Cancel</button>
                            </div>
                        @endif
                        <form wire:submit="sendReply" class="d-flex gap-2">
                            <input type="text" class="form-control" wire:model="reply"
                                   placeholder="{{ $privateToSboId ? 'Write a private reply…' : 'Message the group as the registry…' }}">
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Send</button>
                        </form>
                        @error('reply')<div class="small text-danger mt-1">{{ $message }}</div>@enderror
                    </div>
                </div>
            @else
                <div class="card">
                    <div class="card-body">
                        <div class="admin-empty">
                            <i class="bi bi-chat-square-text" aria-hidden="true"></i>
                            <h3>Select a room</h3>
                            <p>Choose a category on the left to see its members and recent messages.</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
