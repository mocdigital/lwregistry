<div
    x-data="{
        open: false,
        browserPermission: typeof Notification === 'undefined' ? 'unsupported' : Notification.permission,
        initEcho() {
            // Real-time push via Reverb/Echo. When a notification broadcasts on
            // the user's private channel, refresh the bell immediately.
            if (window.Echo && @js(auth()->id())) {
                const bell = this;
                window.Echo.private('App.Models.User.' + @js(auth()->id()))
                    .notification((notification) => {
                        $wire.refresh();
                        bell.playChime();
                        bell.notifyBrowser(notification);
                    });
            }
        },
        /*
         * A short two-tone chime for a new notification. Synthesised with the
         * Web Audio API so there is no asset to ship and nothing for the CSP to
         * block. The context is created on first use and resumed if the browser
         * suspended it; by the time a notification arrives the admin has almost
         * always interacted with the page, so autoplay policy lets it through.
         */
        playChime() {
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (! Ctx) return;
                this._audio = this._audio || new Ctx();
                if (this._audio.state === 'suspended') this._audio.resume();

                const now = this._audio.currentTime;
                [[880, 0], [1174.7, 0.12]].forEach(([freq, offset]) => {
                    const osc = this._audio.createOscillator();
                    const gain = this._audio.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = freq;
                    gain.gain.setValueAtTime(0.0001, now + offset);
                    gain.gain.exponentialRampToValueAtTime(0.18, now + offset + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.18);
                    osc.connect(gain).connect(this._audio.destination);
                    osc.start(now + offset);
                    osc.stop(now + offset + 0.2);
                });
            } catch (error) {
                // Sound is a nicety; a browser that refuses it changes nothing else.
            }
        },
        toggle() {
            this.open = ! this.open;

            if (this.open) {
                $wire.markAllRead();
            }
        },
        requestBrowserPermission() {
            if (typeof Notification === 'undefined' || ! Notification.requestPermission) {
                this.browserPermission = 'unsupported';
                return;
            }

            Notification.requestPermission().then((permission) => {
                this.browserPermission = permission;
            });
        },
        notifyBrowser(notification) {
            if (typeof Notification === 'undefined' || Notification.permission !== 'granted') {
                this.browserPermission = typeof Notification === 'undefined' ? 'unsupported' : Notification.permission;
                return;
            }

            const title = notification?.title || 'Loveworld Registry';
            const body = notification?.message || 'You have a new notification.';

            try {
                new Notification(title, {
                    body,
                    tag: notification?.id || notification?.type || 'loveworld-registry-notification',
                    icon: @js(asset('images/logo-light.png')),
                });
            } catch (error) {
                // Browser notifications are a progressive enhancement; the bell still updates.
            }
        },
    }"
    x-init="initEcho()"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="lw-notification-bell dropdown"
    {{-- Reverb/Echo pushes updates in real time (see initEcho). wire:poll is a
         30s fallback in case the websocket connection is unavailable. The
         dropdown's open state is Alpine-managed, so it survives the poll
         re-render and stays open while the user is reading. --}}
    wire:poll.30s="refresh"
>
    <button
        class="btn lw-notification-trigger position-relative text-reset text-decoration-none"
        type="button"
        title="Notifications"
        aria-label="Notifications"
        :aria-expanded="open ? 'true' : 'false'"
        @click="toggle()"
    >
        <i class="bi bi-bell"></i>
        @if($this->unreadCount > 0)
            <span
                class="lw-notification-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                wire:key="notif-badge"
            >
                {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
            </span>
        @endif
    </button>

    <div
        class="dropdown-menu dropdown-menu-end shadow-sm p-0 lw-notification-menu"
        :class="{ 'show': open }"
        x-cloak
    >
        <div class="lw-notification-header">
            <div>
                <span class="fw-semibold small d-block">Notifications</span>
                <span class="text-muted" style="font-size:.72rem;">Live bell, email, and browser alerts</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                @if($this->unreadCount > 0)
                    <span class="badge rounded-pill bg-danger-subtle text-danger">{{ $this->unreadCount }} new</span>
                @endif
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary lw-notification-browser-btn"
                    x-show="browserPermission === 'default'"
                    x-cloak
                    @click.stop="requestBrowserPermission()"
                >
                    Browser alerts
                </button>
                <span class="badge rounded-pill bg-success-subtle text-success" x-show="browserPermission === 'granted'" x-cloak>Browser on</span>
                <span class="badge rounded-pill bg-light text-muted" x-show="browserPermission === 'denied'" x-cloak>Browser blocked</span>
            </div>
        </div>

        <div class="lw-notification-list">
            @forelse($this->recent as $notification)
                @php
                    $data = $notification->data;
                    $hasUrl = is_string($data['url'] ?? null) && '' !== $data['url'];
                @endphp
                <button
                    type="button"
                    class="dropdown-item lw-notification-item {{ $notification->read_at ? '' : 'is-unread' }}"
                    wire:key="notif-{{ $notification->id }}"
                    wire:click="open('{{ $notification->id }}')"
                >
                    <div class="d-flex w-100 align-items-start gap-2">
                        @unless($notification->read_at)
                            <span class="lw-notification-unread-dot mt-1"></span>
                        @endunless
                        <div class="flex-grow-1">
                            <div class="fw-semibold small">{{ $data['title'] ?? 'Notification' }}</div>
                            <div class="text-muted small">{{ $data['message'] ?? '' }}</div>
                            <div class="text-muted" style="font-size:.7rem;">{{ $notification->created_at->diffForHumans() }}</div>
                        </div>
                        @if($hasUrl)
                            <i class="bi bi-chevron-right text-muted align-self-center"></i>
                        @endif
                    </div>
                </button>
            @empty
                <div class="text-center text-muted small py-4">
                    <i class="bi bi-bell-slash d-block mb-1 fs-5"></i>
                    You have no notifications yet.
                </div>
            @endforelse
        </div>

        <a href="{{ route('notifications.index') }}"
           class="d-block text-center small py-2 border-top text-decoration-none lw-notification-footer"
           wire:navigate>
            View all notifications
        </a>
    </div>
</div>
