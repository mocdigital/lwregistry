{{--
    One message with the full admin action menu, shared by the category groups
    screen and the private inbox. Expects: $message, $reactionSet,
    $reportReasons, $translations, $first. The component using it must provide
    the wire actions (react, pinMessage, translateMessage, reportMessage,
    deleteMessage from ModeratesSboMessages; startReplyTo / startPrivateReply
    from the screen).
--}}
<div wire:key="msg-{{ $message->id }}"
     class="d-flex justify-content-between align-items-start gap-3 py-3 {{ ! $first ? 'border-top' : '' }}">
    <div class="min-w-0 flex-grow-1">
        <div class="small fw-semibold">
            {{ $message->sbo?->name ?? 'Unknown business' }}
            <span class="text-muted-soft fw-normal">{{ $message->user?->email }}</span>
            @if($message->pinned_at)
                <span class="badge-secondary-soft rounded-pill ms-1"><i class="bi bi-pin-angle-fill"></i> Pinned</span>
            @endif
        </div>
        <div class="small text-muted-soft mb-1">{{ $message->created_at?->format('M d, Y, H:i') }}</div>

        @if($message->replyTo)
            <div class="small text-muted-soft border-start ps-2 mb-1">
                <i class="bi bi-reply"></i>
                {{ $message->replyTo->sbo?->name ?? 'business' }}:
                {{ Str::limit($message->replyTo->body, 80) }}
            </div>
        @endif

        <div class="small text-break">{{ $message->body !== '' ? $message->body : '(message deleted)' }}</div>

        @if(isset($translations[$message->id]))
            <div class="small text-break mt-1 p-2 rounded bg-light">
                <span class="text-muted-soft d-block mb-1"><i class="bi bi-translate"></i> Translation</span>
                {{ $translations[$message->id] }}
            </div>
        @endif

        @php($grouped = $message->reactions->groupBy('emoji'))
        @if($grouped->isNotEmpty())
            <div class="d-flex flex-wrap gap-1 mt-2">
                @foreach($grouped as $emoji => $rs)
                    <button type="button" wire:click="react({{ $message->id }}, '{{ $emoji }}')"
                            class="btn btn-sm btn-light border rounded-pill py-0 px-2">
                        {{ $emoji }} <span class="small">{{ $rs->count() }}</span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    <div class="dropdown flex-shrink-0">
        <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-three-dots"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><button class="dropdown-item" type="button" wire:click="startReplyTo({{ $message->id }})"><i class="bi bi-reply me-2"></i>Reply</button></li>
            <li><button class="dropdown-item" type="button" wire:click="startPrivateReply({{ $message->id }})"><i class="bi bi-chat-left-text me-2"></i>Reply privately</button></li>
            <li>
                <button class="dropdown-item" type="button"
                        onclick="navigator.clipboard.writeText(@js($message->body)); this.innerHTML='<i class=&quot;bi bi-check2 me-2&quot;></i>Copied';">
                    <i class="bi bi-clipboard me-2"></i>Copy
                </button>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li class="dropdown-header py-1">React</li>
            <li>
                <div class="d-flex flex-wrap gap-1 px-3 pb-2">
                    @foreach($reactionSet as $emoji)
                        <button type="button" class="btn btn-sm btn-light border rounded-circle" wire:click="react({{ $message->id }}, '{{ $emoji }}')">{{ $emoji }}</button>
                    @endforeach
                </div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><button class="dropdown-item" type="button" wire:click="pinMessage({{ $message->id }})"><i class="bi bi-pin-angle me-2"></i>{{ $message->pinned_at ? 'Unpin' : 'Pin' }}</button></li>
            <li><button class="dropdown-item" type="button" wire:click="translateMessage({{ $message->id }})"><i class="bi bi-translate me-2"></i>{{ isset($translations[$message->id]) ? 'Hide translation' : 'Translate' }}</button></li>
            <li><hr class="dropdown-divider"></li>
            <li class="dropdown-header py-1">Report</li>
            @foreach($reportReasons as $key => $label)
                <li><button class="dropdown-item small" type="button" wire:click="reportMessage({{ $message->id }}, '{{ $key }}')">{{ $label }}</button></li>
            @endforeach
            <li><hr class="dropdown-divider"></li>
            <li>
                <button class="dropdown-item text-danger" type="button"
                        wire:click="deleteMessage({{ $message->id }})"
                        wire:confirm="Delete this message? This cannot be undone.">
                    <i class="bi bi-trash me-2"></i>Delete
                </button>
            </li>
        </ul>
    </div>
</div>
