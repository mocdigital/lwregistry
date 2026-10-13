<?php

use App\Concerns\ModeratesSboMessages;
use App\Events\SboMessageSent;
use App\Http\Controllers\SboReportController;
use App\Models\Sbo;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\SboMessageAttachment;
use App\Models\SboMessageReaction;
use App\Notifications\SboMessageReceivedNotification;
use App\Support\RegistrySbo;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/*
 * Private messages between a member and the registry.
 *
 * The other half of the "Message admin" button in the member's network
 * workspace. One thread per member, whichever end starts it: an administrator
 * opening a conversation here lands in the same thread the member would have
 * reached from a room, so neither side ends up holding half the record.
 *
 * Every administrator writes as the registry's own listing rather than as
 * themselves — the member is talking to the registry, and staff change. Who
 * actually typed it is kept on the message's user_id for the audit trail.
 */
new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Member Messages – Admin Dashboard',
        'dataPage' => 'messages',
        'pageTitle' => 'Member Messages',
    ]
)] class extends Component {
    use ModeratesSboMessages;
    use WithFileUploads;
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public ?int $selectedId = null;

    public string $body = '';

    /** Files being attached to the outgoing message. */
    public array $files = [];

    /** A message in this thread being quoted by the composer. */
    public ?int $replyingToId = null;

    /** Searching by business name, for finding a member to write to first. */
    public string $search = '';

    public function mount(): void
    {
        // A notification can deep-link to a thread (?conversation=ID); open it
        // when the registry is actually in it, otherwise the most recent one.
        $requested = (int) request()->integer('conversation');
        $registry = RegistrySbo::current();

        $isParticipant = $requested > 0 && $registry instanceof Sbo
            && SboConversation::whereKey($requested)
                ->whereHas('participants', fn ($q) => $q->whereKey($registry->id))
                ->exists();

        $this->selectedId = $isParticipant ? $requested : $this->threads()->first()?->id;
    }

    /**
     * Live delivery for the open thread.
     *
     * The registry sits in every thread shown here, so a message the other side
     * sends broadcasts on this conversation's channel and the inbox appends it
     * without a reload. The listener follows the selected thread; selecting
     * another re-subscribes on the next render. New threads that arrive while
     * none is open are caught by the poll on the list instead.
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

    /**
     * A message arrived on the open thread's channel. The payload is ignored on
     * purpose: with() re-reads the thread from the database, which also settles
     * the reactions, pins and replies a broadcast does not carry.
     */
    public function onLiveMessage(): void
    {
        // The round trip itself re-renders; nothing else to do.
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->body = '';
        $this->files = [];
        $this->replyingToId = null;
        $this->translations = [];
    }

    /* --------------------------------------------- reply / reply privately --- */

    /** Quote a message from this thread in the composer. */
    public function startReplyTo(int $messageId): void
    {
        if (null === $this->messageInScope($messageId)) {
            return;
        }

        $this->replyingToId = $messageId;
    }

    /**
     * Open (or start) the private 1:1 with the message's author and switch to
     * it — "privately" on this screen means moving the conversation out of the
     * group and into a direct thread, not composing a hidden group message.
     */
    public function startPrivateReply(int $messageId): void
    {
        $message = $this->messageInScope($messageId);

        if (null === $message) {
            return;
        }

        $registry = RegistrySbo::ensure();

        // A message the registry itself sent has no other party to reach.
        if ((int) $message->sbo_id === $registry->id) {
            return;
        }

        $direct = $this->registryDirectWith((int) $message->sbo_id, $registry);

        $this->select($direct->id);
    }

    public function cancelReply(): void
    {
        $this->replyingToId = null;
    }

    /** Drop one queued attachment before sending, keeping the list contiguous. */
    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    /**
     * Start (or reopen) the thread with a member.
     *
     * Find-or-create, so reaching out to somebody already written to continues
     * the conversation rather than opening a second one beside it.
     */
    public function startWith(int $sboId): void
    {
        $registry = RegistrySbo::ensure();

        abort_if($sboId === $registry->id, 422);

        $existing = SboConversation::query()
            ->where('type', SboConversation::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->whereKey($registry->id))
            ->whereHas('participants', fn ($q) => $q->whereKey($sboId))
            ->has('participants', '=', 2)
            ->first();

        if (! $existing instanceof SboConversation) {
            $existing = SboConversation::create([
                'created_by_sbo_id' => $registry->id,
                'type' => SboConversation::TYPE_DIRECT,
                'last_message_at' => now(),
            ]);

            $existing->participants()->syncWithoutDetaching([$registry->id, $sboId]);
        }

        $this->search = '';
        $this->select($existing->id);
    }

    public function send(): void
    {
        $conversation = $this->selectedThread();
        abort_unless($conversation instanceof SboConversation, 404);

        // A message may be text, one or more files, or both — but not empty.
        $data = $this->validate([
            'body' => ['required_without:files', 'nullable', 'string', 'max:5000'],
            'files' => ['array', 'max:5'],
            'files.*' => [
                'file',
                'mimetypes:' . implode(',', array_keys(SboMessageAttachment::ACCEPTED)),
                'mimes:' . implode(',', SboMessageAttachment::EXTENSIONS),
                'max:' . SboMessageAttachment::MAX_KILOBYTES,
            ],
        ]);

        $registry = RegistrySbo::ensure();

        $message = SboMessage::create([
            'conversation_id' => $conversation->id,
            // As the registry, not as the person typing. The user_id below is
            // what records who that was.
            'sbo_id' => $registry->id,
            'user_id' => auth()->id(),
            'body' => (string) ($data['body'] ?? ''),
            'reply_to_message_id' => $this->replyingToId,
        ]);

        foreach ($this->files as $file) {
            SboMessageAttachment::storeFor($message, $file);
        }

        $conversation->forceFill(['last_message_at' => now()])->save();
        $conversation->participants()->updateExistingPivot($registry->id, ['last_read_at' => now()]);

        $message->load(['sbo', 'attachments']);

        // The same delivery the member's own screen uses, so a reply appears
        // in an open thread rather than on the next page load.
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

        $this->body = '';
        $this->files = [];
        $this->replyingToId = null;
    }

    /** Every thread the registry is a party to, most recently active first. */
    public function threads()
    {
        $registry = RegistrySbo::current();

        if (! $registry instanceof Sbo) {
            return collect();
        }

        /*
         * Direct threads AND the group rooms, because the registry owns every
         * room and sits in it. An admin who can be written to privately but
         * cannot see the group they are the admin of is only half present.
         *
         * The search narrows this list: a group by its category name, or a
         * direct thread by the other business's name — so a room can be found
         * among the forty of them, not just a member written to.
         */
        $term = mb_trim($this->search);

        return SboConversation::query()
            ->whereHas('participants', fn ($q) => $q->whereKey($registry->id))
            ->when($term !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where(fn ($group) => $group
                    ->where('type', SboConversation::TYPE_INDUSTRY)
                    ->where('industry', 'like', '%' . $term . '%'))
                ->orWhereHas('participants', fn ($p) => $p
                    ->where('sbos.id', '!=', $registry->id)
                    ->where('sbos.name', 'like', '%' . $term . '%'))))
            ->with(['participants', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->orderByDesc('last_message_at')
            ->take(100)
            ->get();
    }

    public function selectedThread(): ?SboConversation
    {
        return null === $this->selectedId
            ? null
            : SboConversation::query()->with('participants')->find($this->selectedId);
    }

    public function with(): array
    {
        $registry = RegistrySbo::current();
        $thread = $this->selectedThread();

        return [
            'registry' => $registry,
            'threads' => $this->threads(),
            'thread' => $thread,
            'messages' => $thread
                ? SboMessage::where('conversation_id', $thread->id)
                    ->with(['sbo', 'user', 'attachments', 'reactions', 'replyTo.sbo'])
                    ->oldest()
                    ->take(200)
                    ->get()
                : collect(),
            'reactionSet' => SboMessageReaction::ALLOWED,
            'reportReasons' => SboReportController::reasons(),
            /*
             * Members to start a thread with. Only searched, never listed in
             * full: the registry has hundreds of listings and a dropdown of
             * all of them is not a way to find one.
             */
            'candidates' => mb_strlen(mb_trim($this->search)) < 2
                ? collect()
                : Sbo::query()
                    ->where('is_registry', false)
                    ->where('name', 'like', '%' . mb_trim($this->search) . '%')
                    ->orderBy('name')
                    ->take(10)
                    ->get(['id', 'name']),
        ];
    }
}; ?>

{{-- wire:poll keeps the open thread and the list fresh without a reload — new
     messages and brand-new conversations both surface on this cadence. When
     Reverb is enabled the open thread also updates instantly over Echo
     (getListeners); until then this poll is the live mechanism. --}}
<div class="row g-3" wire:poll.6s>
    {{-- The emoji picker inside each message's action menu is Alpine-hidden
         until React is clicked; cloak it so it does not flash before Alpine
         loads. --}}
    <style>[x-cloak]{display:none!important}</style>
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <label for="member-search" class="form-label small mb-1">Write to a member</label>
                <input
                    id="member-search"
                    type="search"
                    class="form-control form-control-sm"
                    placeholder="Search business name…"
                    wire:model.live.debounce.300ms="search"
                >

                @if ($candidates->isNotEmpty())
                    <ul class="list-group list-group-flush mt-2">
                        @foreach ($candidates as $candidate)
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span class="small text-truncate">{{ $candidate->name }}</span>
                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="startWith({{ $candidate->id }})">
                                    Message
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="list-group list-group-flush overflow-auto" style="max-height: 60vh;">
                @forelse ($threads as $row)
                    @php
                        $isRoom = $row->type === \App\Models\SboConversation::TYPE_INDUSTRY;
                        // A room has its own name; on a direct thread the other
                        // participant is the member being written to.
                        $label = $isRoom
                            ? \App\Support\IndustryGroupChat::name((string) $row->industry)
                            : ($row->participants->firstWhere('is_registry', false)?->name ?? 'Unknown business');
                    @endphp
                    <button
                        type="button"
                        class="list-group-item list-group-item-action {{ $row->id === $selectedId ? 'active' : '' }}"
                        wire:click="select({{ $row->id }})"
                    >
                        <div class="fw-semibold small text-truncate">
                            {{ $label }}
                            @if ($isRoom)
                                <span class="badge bg-secondary ms-1">Group</span>
                            @endif
                        </div>
                        <div class="small text-truncate {{ $row->id === $selectedId ? '' : 'text-muted' }}">
                            {{ Str::limit($row->messages->first()?->body ?? 'No messages yet', 48) }}
                        </div>
                    </button>
                @empty
                    <div class="p-3 small text-muted">
                        No member has written yet, and nobody has been written to. Search above to start.
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card h-100">
            @if ($thread)
                @php
                    $isRoom = $thread->type === \App\Models\SboConversation::TYPE_INDUSTRY;
                    $heading = $isRoom
                        ? \App\Support\IndustryGroupChat::name((string) $thread->industry)
                        : ($thread->participants->firstWhere('is_registry', false)?->name ?? 'Unknown business');
                @endphp

                <div class="card-header">
                    <div class="fw-semibold">
                        {{ $heading }}
                        @if ($isRoom)
                            <span class="badge bg-secondary ms-1">{{ $thread->participants->count() }} members</span>
                        @endif
                    </div>
                    <div class="small text-muted">
                        @if ($isRoom)
                            Posting here is visible to everyone in the room.
                        @endif
                        Sent as {{ $registry?->name ?? config('registry.support_name') }}.
                    </div>
                </div>

                <div class="card-body overflow-auto" style="max-height: 50vh;">
                    @forelse ($messages as $message)
                        @php
                            $fromRegistry = (bool) $message->sbo?->is_registry;
                            $grouped = $message->reactions->groupBy('emoji');
                        @endphp
                        <div wire:key="msg-{{ $message->id }}"
                             class="mb-3 d-flex align-items-start gap-1 {{ $fromRegistry ? 'justify-content-end' : '' }}">

                            {{-- Action menu sits on the outer edge of each bubble. --}}
                            @if ($fromRegistry)
                                @include('livewire.admin.messages._actions', ['message' => $message, 'reactionSet' => $reactionSet, 'reportReasons' => $reportReasons, 'translations' => $translations])
                            @endif

                            <div class="p-2 px-3 rounded-3 {{ $fromRegistry ? 'bg-primary text-white' : 'bg-light' }}" style="max-width: 32rem;">
                                {{-- Who posted: essential in a group room where any
                                     of the members may be speaking. --}}
                                <div class="small fw-semibold mb-1 {{ $fromRegistry ? 'text-white' : '' }}">
                                    {{ $message->sbo?->name ?? 'Unknown business' }}
                                    @if ($message->user?->email)
                                        <span class="fw-normal {{ $fromRegistry ? 'text-white-50' : 'text-muted-soft' }}">{{ $message->user->email }}</span>
                                    @endif
                                </div>

                                @if ($message->pinned_at)
                                    <div class="small {{ $fromRegistry ? 'text-white-50' : 'text-muted-soft' }} mb-1">
                                        <i class="bi bi-pin-angle-fill"></i> Pinned
                                    </div>
                                @endif

                                @if ($message->replyTo)
                                    <div class="small border-start ps-2 mb-1 {{ $fromRegistry ? 'border-light text-white-50' : 'text-muted-soft' }}">
                                        <i class="bi bi-reply"></i>
                                        {{ $message->replyTo->sbo?->name ?? 'business' }}:
                                        {{ Str::limit($message->replyTo->body, 80) }}
                                    </div>
                                @endif

                                <div class="small text-break">{{ $message->body !== '' ? $message->body : '(message deleted)' }}</div>

                                @if (isset($translations[$message->id]))
                                    <div class="small text-break mt-1 p-2 rounded {{ $fromRegistry ? 'bg-primary-subtle text-body' : 'bg-white' }}">
                                        <span class="text-muted-soft d-block mb-1"><i class="bi bi-translate"></i> Translation</span>
                                        {{ $translations[$message->id] }}
                                    </div>
                                @endif

                                {{-- Attachments, streamed through the gated route
                                     that checks access — images and video shown
                                     as media, everything else as a download. --}}
                                @foreach ($message->attachments as $attachment)
                                    @php $src = route('sbo.message-attachments.show', $attachment); @endphp
                                    @if ($attachment->kind === \App\Models\SboMessageAttachment::KIND_IMAGE)
                                        <a href="{{ $src }}" target="_blank" rel="noopener" class="d-block mt-2">
                                            <img src="{{ $src }}" alt="{{ $attachment->original_name }}"
                                                 class="rounded" style="max-width: 100%; max-height: 240px; object-fit: cover;">
                                        </a>
                                    @elseif ($attachment->kind === \App\Models\SboMessageAttachment::KIND_VIDEO)
                                        <video controls preload="metadata" class="rounded mt-2" style="max-width: 100%; max-height: 260px;">
                                            <source src="{{ $src }}" type="{{ $attachment->mime_type }}">
                                        </video>
                                    @else
                                        <a class="d-block small mt-1 {{ $fromRegistry ? 'text-white' : '' }}"
                                           href="{{ $src }}" target="_blank" rel="noopener">
                                            📎 {{ $attachment->original_name }}
                                        </a>
                                    @endif
                                @endforeach

                                @if ($grouped->isNotEmpty())
                                    <div class="d-flex flex-wrap gap-1 mt-2">
                                        @foreach ($grouped as $emoji => $rs)
                                            <button type="button" wire:click="react({{ $message->id }}, '{{ $emoji }}')"
                                                    class="btn btn-sm btn-light border rounded-pill py-0 px-2">
                                                {{ $emoji }} <span class="small">{{ $rs->count() }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="small opacity-75 mt-1">{{ $message->created_at?->diffForHumans() }}</div>
                            </div>

                            @unless ($fromRegistry)
                                @include('livewire.admin.messages._actions', ['message' => $message, 'reactionSet' => $reactionSet, 'reportReasons' => $reportReasons, 'translations' => $translations])
                            @endunless
                        </div>
                    @empty
                        <p class="small text-muted">Nothing said yet.</p>
                    @endforelse
                </div>

                <div class="card-footer">
                    @if ($replyingToId)
                        <div class="small text-muted-soft mb-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-reply"></i> Replying to a message in this thread</span>
                            <button type="button" class="btn btn-sm btn-link p-0" wire:click="cancelReply">Cancel</button>
                        </div>
                    @endif
                    <form wire:submit="send">
                        <div class="input-group">
                            <textarea class="form-control" rows="2" wire:model="body" placeholder="Write a reply…"></textarea>
                            <label class="btn btn-outline-secondary d-flex align-items-center mb-0" title="Attach files">
                                <i class="bi bi-paperclip"></i>
                                <input type="file" class="d-none" wire:model="files" multiple>
                            </label>
                            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="send">Send</button>
                        </div>

                        <div wire:loading wire:target="files" class="small text-muted-soft mt-1">Uploading…</div>

                        @if (count($files))
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                @foreach ($files as $index => $file)
                                    @php $isImage = str_starts_with((string) $file->getMimeType(), 'image/'); @endphp
                                    <span class="badge-secondary-soft rounded-pill d-inline-flex align-items-center gap-1">
                                        @if ($isImage)
                                            <img src="{{ $file->temporaryUrl() }}" alt="" class="rounded" style="width:1.4rem;height:1.4rem;object-fit:cover;">
                                        @else
                                            <i class="bi bi-paperclip"></i>
                                        @endif
                                        {{ Str::limit($file->getClientOriginalName(), 24) }}
                                        <button type="button" class="btn-close btn-close-sm ms-1" style="font-size:.6rem"
                                                wire:click="removeFile({{ $index }})" aria-label="Remove"></button>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @error('body')
                            <div class="small text-danger mt-1">{{ $message }}</div>
                        @enderror
                        @error('files.*')
                            <div class="small text-danger mt-1">{{ $message }}</div>
                        @enderror
                    </form>
                </div>
            @else
                <div class="card-body d-flex align-items-center justify-content-center text-center">
                    <div>
                        <p class="fw-semibold mb-1">No conversation selected</p>
                        <p class="small text-muted mb-0">Pick a thread on the left, or search for a member to write to.</p>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Report a message. A modal with the same reason list a member uses to
         report a listing, so the admin queue groups both together. --}}
    @if ($reportingId)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center p-3"
             style="background: rgba(15,23,42,.5); z-index: 1080;"
             wire:click.self="cancelReport" role="dialog" aria-modal="true" aria-labelledby="report-message-title">
            <div class="card shadow-lg" style="max-width: 28rem; width: 100%;" wire:key="report-modal-{{ $reportingId }}">
                <div class="card-body">
                    <h2 id="report-message-title" class="h6 mb-1">Report this message</h2>
                    <p class="small text-muted-soft mb-3">The message, and the business that sent it.</p>

                    <form wire:submit="submitReport">
                        <div class="mb-3">
                            <label for="report-reason" class="form-label small fw-semibold">Reason</label>
                            <select id="report-reason" class="form-select" wire:model="reportReason">
                                <option value="" disabled>Select a reason</option>
                                @foreach ($reportReasons as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('reportReason')<div class="small text-danger mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="report-details" class="form-label small fw-semibold">
                                Anything else <span class="fw-normal text-muted-soft">(optional)</span>
                            </label>
                            <textarea id="report-details" rows="3" class="form-control" wire:model="reportDetails"></textarea>
                            @error('reportDetails')<div class="small text-danger mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-outline-secondary" wire:click="cancelReport">Cancel</button>
                            <button type="submit" class="btn btn-danger" wire:loading.attr="disabled" wire:target="submitReport">Report</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
