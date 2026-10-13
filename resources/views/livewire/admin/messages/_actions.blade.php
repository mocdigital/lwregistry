{{--
    The per-message action menu for the private inbox, mirroring the category
    groups menu but sized for a chat bubble. Expects: $message, $reactionSet,
    $reportReasons, $translations. Actions come from ModeratesSboMessages plus
    the screen's startReplyTo / startPrivateReply.
--}}
<div class="dropdown flex-shrink-0">
    {{-- auto-close="outside" keeps the menu open when the React item is clicked
         to reveal the emoji picker in place, matching the member side; it still
         closes on an outside click. --}}
    <button class="btn btn-sm btn-link text-muted-soft p-1" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Message actions">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu">
        <li><button class="dropdown-item" type="button" wire:click="startReplyTo({{ $message->id }})"><i class="bi bi-reply me-2"></i>Reply</button></li>
        @unless ((bool) $message->sbo?->is_registry)
            <li><button class="dropdown-item" type="button" wire:click="startPrivateReply({{ $message->id }})"><i class="bi bi-chat-left-text me-2"></i>Reply privately</button></li>
        @endunless
        <li>
            <button class="dropdown-item" type="button"
                    onclick="navigator.clipboard.writeText(@js($message->body)); this.innerHTML='<i class=&quot;bi bi-check2 me-2&quot;></i>Copied';">
                <i class="bi bi-clipboard me-2"></i>Copy
            </button>
        </li>
        <li><hr class="dropdown-divider"></li>
        {{-- React reveals the emoji set only once clicked, so the menu stays
             compact. @click.stop keeps Bootstrap from closing the dropdown. --}}
        <li x-data="{ showReact: false }">
            <button class="dropdown-item d-flex align-items-center" type="button" @click.stop="showReact = !showReact">
                <i class="bi bi-emoji-smile me-2"></i>React
                <i class="bi ms-auto" :class="showReact ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
            </button>
            <div class="d-flex flex-wrap gap-1 px-3 pb-2" x-show="showReact" x-cloak>
                @foreach ($reactionSet as $emoji)
                    <button type="button" class="btn btn-sm btn-light border rounded-circle" wire:click="react({{ $message->id }}, '{{ $emoji }}')">{{ $emoji }}</button>
                @endforeach
            </div>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" type="button" wire:click="pinMessage({{ $message->id }})"><i class="bi bi-pin-angle me-2"></i>{{ $message->pinned_at ? 'Unpin' : 'Pin' }}</button></li>
        <li><button class="dropdown-item" type="button" wire:click="translateMessage({{ $message->id }})"><i class="bi bi-translate me-2"></i>{{ isset($translations[$message->id]) ? 'Hide translation' : 'Translate' }}</button></li>
        <li><button class="dropdown-item" type="button" wire:click="openReport({{ $message->id }})"><i class="bi bi-flag me-2"></i>Report</button></li>
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
