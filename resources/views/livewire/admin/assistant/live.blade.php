<?php

declare(strict_types=1);

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * The live console for the sales assistant.
 *
 * Watching every conversation and stepping into any of them. The page itself is
 * a shell: the list, the transcript and the actions are JSON the browser polls,
 * because taking over has to feel immediate and this deployment has no
 * websocket server to push with. While it is open the administrator is counted
 * as online, which is what a visitor's "Talk to a person" checks before
 * promising someone will join.
 */
new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Live Chat – Admin Dashboard',
        'dataPage' => 'assistant-live',
        'pageTitle' => 'Live Chat',
    ]
)] class extends Component {
    //
};
?>

{{--
    Brand colours over Bootstrap's defaults: the console's primary actions and
    active states are the registry's teal, not framework blue. The status
    badges stay semantic — amber waiting, green live, red missed — because those
    carry meaning a brand colour would erase.
--}}
<style>
    .assistant-live { --lw-teal: #0d9488; --lw-teal-dark: #0f766e; --lw-navy: #001529; }
    .assistant-live .btn-primary {
        background-color: var(--lw-teal);
        border-color: var(--lw-teal);
    }
    .assistant-live .btn-primary:hover,
    .assistant-live .btn-primary:focus,
    .assistant-live .btn-primary:active {
        background-color: var(--lw-teal-dark);
        border-color: var(--lw-teal-dark);
        box-shadow: none;
    }
    .assistant-live .nav-pills .nav-link.active {
        background-color: var(--lw-teal);
    }
    .assistant-live .nav-pills .nav-link {
        color: var(--lw-navy);
    }
    .assistant-live .form-check-input:checked {
        background-color: var(--lw-teal);
        border-color: var(--lw-teal);
    }
    .assistant-live .list-group-item.active {
        background-color: var(--lw-teal);
        border-color: var(--lw-teal);
    }
    .assistant-live .badge.bg-primary { background-color: var(--lw-teal) !important; }
    .assistant-live #live-thread .bg-primary { background-color: var(--lw-teal) !important; }
</style>

<div
    class="assistant-live"
    data-ping="{{ route('admin.assistant.live.ping') }}"
    data-sessions="{{ route('admin.assistant.live.sessions') }}"
    data-thread-base="{{ url('/admin/assistant/live/thread') }}"
    data-takeover-base="{{ url('/admin/assistant/live/takeover') }}"
    data-send-base="{{ url('/admin/assistant/live/send') }}"
    data-end-base="{{ url('/admin/assistant/live/end') }}"
>
    <div class="card">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <p class="text-muted mb-0" style="font-size: .85rem;">
                    You are online while this page is open, so visitors can reach a person.
                    Waiting conversations ring here.
                </p>
                <label class="form-check form-switch mb-0" style="font-size: .85rem;">
                    <input class="form-check-input" type="checkbox" id="live-sound" checked>
                    <span class="form-check-label">Ring on new request</span>
                </label>
            </div>

            <div class="row g-3">
                {{-- The list of conversations. --}}
                <div class="col-md-4">
                    <div id="live-waiting" class="mb-3"></div>
                    <ul class="nav nav-pills nav-fill mb-2" style="font-size: .8rem;">
                        <li class="nav-item"><button class="nav-link active" data-filter="all" type="button">All</button></li>
                        <li class="nav-item"><button class="nav-link" data-filter="live" type="button">Live</button></li>
                        <li class="nav-item"><button class="nav-link" data-filter="missed" type="button">Missed</button></li>
                    </ul>
                    <div id="live-list" class="list-group" style="max-height: 60vh; overflow-y: auto;"></div>
                </div>

                {{-- The open conversation. --}}
                <div class="col-md-8">
                    <div id="live-thread-wrap" class="border rounded d-flex flex-column" style="height: 66vh;">
                        <div id="live-thread-empty" class="m-auto text-center text-muted p-4">
                            Choose a conversation on the left to read it, or take it over.
                        </div>

                        <div id="live-thread-head" class="border-bottom p-3 d-none align-items-center justify-content-between">
                            <div>
                                <strong id="live-thread-name"></strong>
                                <span id="live-thread-email" class="text-muted ms-2" style="font-size: .8rem;"></span>
                            </div>
                            <div class="d-flex gap-2">
                                <button id="live-takeover" class="btn btn-sm btn-primary" type="button">Take over</button>
                                <button id="live-end" class="btn btn-sm btn-outline-secondary d-none" type="button">End chat</button>
                            </div>
                        </div>

                        <div id="live-thread" class="flex-fill p-3 d-none" style="overflow-y: auto;"></div>

                        <form id="live-composer" class="border-top p-2 d-none">
                            <div class="input-group">
                                <input id="live-input" class="form-control" placeholder="Type a reply…" maxlength="2000" autocomplete="off">
                                <button class="btn btn-primary" type="submit">Send</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @vite('resources/js/admin/assistant-live.js')
</div>
