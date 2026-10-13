<div class="notifications-page-remake sbo-remake">
    <x-ui.page-header
        title="Notifications"
        description="Stay close to application reviews, payment confirmations, and network activity."
        :breadcrumbs="[
            ['label' => 'Account', 'href' => route('dashboard'), 'wireNavigate' => true],
            ['label' => 'Notifications', 'current' => true],
        ]"
    >
        <x-slot:meta>
                @if($unreadCount > 0)
                    You have <strong>{{ $unreadCount }}</strong> unread notification{{ $unreadCount === 1 ? '' : 's' }}.
                @else
                    You're all caught up.
                @endif
        </x-slot:meta>
        <x-slot:actions>
        @if($unreadCount > 0)
            <button class="btn btn-outline-secondary rounded-pill" wire:click="markAllRead">
                <i class="bi bi-check2-all me-1"></i> Mark all as read
            </button>
        @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Filter tabs --}}
    <div class="notifications-page-remake__filters mb-3" role="tablist" aria-label="Notification filter">
        <div>
            <button type="button" class="btn btn-sm {{ $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill" wire:click="setFilter('all')" role="tab" aria-selected="{{ $filter === 'all' ? 'true' : 'false' }}">All</button>
        </div>
        <div>
            <button type="button" class="btn btn-sm {{ $filter === 'unread' ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill" wire:click="setFilter('unread')" role="tab" aria-selected="{{ $filter === 'unread' ? 'true' : 'false' }}">
                Unread @if($unreadCount > 0)<span class="badge rounded-pill bg-danger ms-1">{{ $unreadCount }}</span>@endif
            </button>
        </div>
    </div>

    <div class="card">
        <div class="list-group list-group-flush">
            @forelse($notifications as $notification)
                @php $data = $notification->data; @endphp
                <article
                    class="list-group-item d-flex align-items-start gap-3 {{ $notification->read_at ? '' : 'bg-light' }}"
                    wire:key="notif-{{ $notification->id }}"
                >
                    <div class="mt-1">
                        @if($notification->read_at)
                            <i class="bi bi-bell text-muted fs-5"></i>
                        @else
                            <i class="bi bi-bell-fill text-danger fs-5"></i>
                        @endif
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ $data['title'] ?? 'Notification' }}</div>
                        <div class="text-muted">{{ $data['message'] ?? '' }}</div>
                        <div class="text-muted small mt-1">{{ $notification->created_at->diffForHumans() }}</div>
                        @if(filled($data['url'] ?? null) || filled($data['sbo_id'] ?? null))
                            <button type="button" class="btn btn-sm btn-link px-0 mt-1" wire:click="open('{{ $notification->id }}')">
                                Open update <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                            </button>
                        @endif
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-link text-muted" data-bs-toggle="dropdown" aria-label="Actions">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @if($notification->read_at)
                                <li><button class="dropdown-item" wire:click="markUnread('{{ $notification->id }}')">Mark as unread</button></li>
                            @else
                                <li><button class="dropdown-item" wire:click="markRead('{{ $notification->id }}')">Mark as read</button></li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button class="dropdown-item text-danger"
                                    wire:click="delete('{{ $notification->id }}')"
                                    wire:confirm="Delete this notification?">
                                    Delete
                                </button>
                            </li>
                        </ul>
                    </div>
                </article>
            @empty
                <div class="list-group-item text-center text-muted py-5">
                    <i class="bi bi-bell-slash d-block mb-2 fs-3"></i>
                    @if($filter === 'unread')
                        No unread notifications.
                    @else
                        You have no notifications yet.
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    @if($notifications->hasPages())
        <div class="mt-3">
            {{ $notifications->links() }}
        </div>
    @endif
</div>
