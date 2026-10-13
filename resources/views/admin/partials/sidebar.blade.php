@php
    $adminUser = auth()->user();
    $canPage = fn (string $page): bool => $adminUser?->hasAdminPage($page) ?? false;
    $canAnyPage = fn (array $pages): bool => $adminUser?->hasAnyAdminPage($pages) ?? false;
@endphp

<nav class="sidebar d-flex flex-column" id="adminSidebar" aria-label="Admin sidebar menu">
    <div class="sidebar-brand">
        <div class="logo-circle">
            <img src="{{ asset('images/logo-icon-light.png') }}" alt="Logo" />
        </div>
        <div class="sidebar-brand-copy">
            <div class="fw-semibold">Loveworld Registry</div>

            <small class="text-muted-soft d-block">
                {{ auth()->user()->isSuperAdmin() ? 'Super Admin' : auth()->user()->role->label() }}
            </small>
        </div>

        <button
            type="button"
            class="sidebar-close d-lg-none"
            aria-controls="adminSidebar"
            aria-label="Close sidebar menu"
            data-admin-sidebar-close
        >
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>


    <div class="mt-3 flex-grow-1 d-flex flex-column">

        @if($canPage(\App\Support\AdminPages::DASHBOARD))
            <div class="submenu-title">Overview</div>
            <a href="{{ route('admin.dashboard') }}"
            class="nav-link"
            data-page="dashboard">
                <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
            </a>
        @endif

        {{-- ================= DIRECTORY ================= --}}
        @if($canAnyPage([\App\Support\AdminPages::USERS, \App\Support\AdminPages::BUSINESSES, \App\Support\AdminPages::COB_BUSINESSES]))
            <div class="submenu-title">Directory</div>

            @if($canPage(\App\Support\AdminPages::USERS))
                <a href="{{ route('admin.users.index') }}"
                   class="nav-link"
                   data-page="users">
                    <i class="bi bi-people"></i> <span>Users</span>
                </a>
            @endif

            @if($canPage(\App\Support\AdminPages::BUSINESSES))
                <a href="{{ route('admin.businesses.index') }}"
                   class="nav-link"
                   data-page="businesses">
                    <i class="bi bi-building"></i> <span>SBOs</span>
                </a>
            @endif

            @if($canPage(\App\Support\AdminPages::COB_BUSINESSES))
                <a href="{{ route('admin.cob.index') }}"
                   class="nav-link"
                   data-page="cob-businesses">
                    <i class="bi bi-shop"></i> <span>COBs</span>
                </a>
            @endif
        @endif

        {{-- ================= APPROVALS ================= --}}
        @if($canAnyPage([
            \App\Support\AdminPages::ORGANIZATIONS,
            \App\Support\AdminPages::APPROVAL_WORKFLOW,
            \App\Support\AdminPages::APPROVAL_INFO_REQUESTS,
            \App\Support\AdminPages::APPROVAL_REJECTED_HISTORY,
        ]))
            <div class="submenu-title">Approvals</div>
        @endif

        @if($canPage(\App\Support\AdminPages::ORGANIZATIONS))
            <a href="{{ route('admin.sbos.index') }}"
               class="nav-link"
               data-page="sbos">
                <i class="bi bi-people"></i> <span>Application approvals</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::APPROVAL_WORKFLOW))
            <a href="{{ route('admin.approvals.index') }}"
               class="nav-link"
               data-page="approvals-workflow">
                <i class="bi bi-diagram-3"></i> <span>Validation approvals</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::APPROVAL_INFO_REQUESTS))
            <a href="{{ route('admin.approvals.info-requests') }}"
               class="nav-link"
               data-page="approvals-info-requests">
                <i class="bi bi-chat-left-text"></i> <span>Info requests</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::APPROVAL_REJECTED_HISTORY))
            <a href="{{ route('admin.approvals.rejected-history') }}"
               class="nav-link"
               data-page="approvals-rejected-history">
                <i class="bi bi-clock-history"></i> <span>Rejected history</span>
            </a>
        @endif

        @if($canAnyPage([\App\Support\AdminPages::COMPLIANCE_DOCUMENTS, \App\Support\AdminPages::FEES, \App\Support\AdminPages::REPORTS]))
            {{-- ================= SBO WORKFLOW ================= --}}
            <div class="submenu-title">SBO Workflow</div>
        @endif

        @if($canPage(\App\Support\AdminPages::COMPLIANCE_DOCUMENTS))
            <a href="{{ route('admin.compliance.documents.index') }}"
               class="nav-link"
               data-page="compliance-documents">
                <i class="bi bi-file-earmark-check"></i> <span>Required Documents</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::FEES))
            <a href="{{ route('admin.fees.index') }}"
               class="nav-link"
               data-page="fees">
                <i class="bi bi-cash-coin"></i> <span>Registration Fees</span>
            </a>

            <a href="{{ route('admin.fees.index') }}#espees-checkout"
               class="nav-link"
               data-page="espees-configuration">
                <i class="bi bi-credit-card"></i> <span>Espees Configuration</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::REPORTS))
            <a href="{{ route('admin.reports.index') }}"
               class="nav-link"
               data-page="reports">
                <i class="bi bi-chat-square-text"></i> <span>Reports</span>
            </a>
        @endif

        {{-- ================= GROWTH ================= --}}
        @if($canAnyPage([\App\Support\AdminPages::ZONE_REFERRALS, \App\Support\AdminPages::ZONE_MOMENTUM, \App\Support\AdminPages::GROWTH_PAYMENTS]))
            <div class="submenu-title">Growth</div>
        @endif

        @if($canPage(\App\Support\AdminPages::ZONE_REFERRALS))
            <a href="{{ route('admin.zones.referrals.index') }}"
               class="nav-link"
               data-page="zone-referrals">
                <i class="bi bi-link-45deg"></i> <span>Zone Referrals</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::ZONE_MOMENTUM))
            <a href="{{ route('admin.zones.momentum.index') }}"
               class="nav-link"
               data-page="zone-momentum">
                <i class="bi bi-graph-up-arrow"></i> <span>Zone Momentum</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::GROWTH_PAYMENTS))
            <a href="{{ route('admin.growth.payments') }}"
               class="nav-link"
               data-page="growth-payments">
                <i class="bi bi-wallet2"></i> <span>Payments</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::PURCHASE_ORDERS))
            <div class="submenu-title">Procurement</div>
            <a href="{{ route('admin.purchase-orders.index') }}"
               class="nav-link"
               data-page="purchase-orders">
                <i class="bi bi-building"></i> <span>Procurement Requests</span>
            </a>
        @endif

        {{-- ================= STORE / INVENTORY ================= --}}
        @if($canAnyPage([\App\Support\AdminPages::INVENTORY, \App\Support\AdminPages::INVENTORY_ORDERS, \App\Support\AdminPages::INVENTORY_ORDER_DETAILS]))
            <div class="submenu-title">Store</div>

            @if($canPage(\App\Support\AdminPages::INVENTORY))
                <a href="{{ route('admin.inventory.index') }}"
                   class="nav-link"
                   data-page="inventory">
                    <i class="bi bi-box-seam"></i> <span>Inventory</span>
                </a>
            @endif

            @if($canPage(\App\Support\AdminPages::INVENTORY_ORDERS))
                <a href="{{ route('admin.inventory.orders.index') }}"
                   class="nav-link"
                   data-page="inventory-orders">
                    <i class="bi bi-receipt"></i> <span>Orders &amp; Checkout</span>
                </a>
            @endif

            @if($canPage(\App\Support\AdminPages::INVENTORY_ORDER_DETAILS))
                <a href="{{ route('admin.inventory.order-details.index') }}"
                   class="nav-link"
                   data-page="inventory-order-details">
                    <i class="bi bi-clipboard-data"></i> <span>Order Details</span>
                </a>
            @endif
        @endif

        {{-- ================= CONFIGURATION ================= --}}
        @if($canAnyPage([\App\Support\AdminPages::CATEGORIES_LOCATIONS, \App\Support\AdminPages::MINISTRIES, \App\Support\AdminPages::GBPC_REGISTRATIONS, \App\Support\AdminPages::ADS, \App\Support\AdminPages::AD_INQUIRIES, \App\Support\AdminPages::SUBSCRIBERS, \App\Support\AdminPages::MAIL_TEST, \App\Support\AdminPages::ADMINS]))
            <div class="submenu-title">Configuration</div>

            @if($canPage(\App\Support\AdminPages::CATEGORIES_LOCATIONS))
                <a href="{{ route('admin.categories-locations.index') }}"
                   class="nav-link"
                   data-page="categories-locations">
                    <i class="bi bi-tags"></i> <span>Categories &amp; Locations</span>
                </a>
            @endif

            @if($canPage(\App\Support\AdminPages::MINISTRIES))
                <a href="{{ route('admin.ministries.index') }}"
                   class="nav-link"
                   data-page="ministries">
                    <i class="bi bi-people"></i> <span>Ministries</span>
                </a>
            @endif

            @if($canPage(\App\Support\AdminPages::GBPC_REGISTRATIONS))
                <a href="{{ route('admin.gbpc-registrations.index') }}"
                   class="nav-link"
                   data-page="gbpc-registrations">
                    <i class="bi bi-clipboard-check"></i> <span>GBPC Registrations</span>
                </a>
            @endif

            @if($canPage(\App\Support\AdminPages::ADS))
                <a href="{{ route('admin.ads.index') }}"
                   class="nav-link"
                   data-page="ads">
                    <i class="bi bi-badge-ad"></i> <span>Ad Placements</span>
                </a>
            @endif

            {{-- The public Advertise form has written to this table all along;
                 until now nothing read it, so an inquiry only ever reached
                 anybody as an email. --}}
            @if($canPage(\App\Support\AdminPages::AD_INQUIRIES))
                <a href="{{ route('admin.ads.inquiries') }}"
                   class="nav-link"
                   data-page="ads-inquiries">
                    <i class="bi bi-envelope-paper"></i> <span>Advertising Inquiries</span>
                </a>
            @endif

            @if($canPage(\App\Support\AdminPages::SUBSCRIBERS))
                <a href="{{ route('admin.subscribers.index') }}"
                   class="nav-link"
                   data-page="subscribers">
                    <i class="bi bi-bell"></i> <span>Subscribers</span>
                </a>
            @endif

            @if($canPage(\App\Support\AdminPages::MAIL_TEST))
                <a href="{{ route('admin.mail-test') }}"
                   class="nav-link"
                   data-page="mail-test">
                    <i class="bi bi-envelope-check"></i> <span>Mail Test</span>
                </a>
            @endif

            @if($canPage(\App\Support\AdminPages::ADMINS))
                <a href="{{ route('admin.admins.index') }}"
                   class="nav-link"
                   data-page="admins">
                    <i class="bi bi-shield-lock"></i> <span>Administrators</span>
                </a>
            @endif
        @endif

        {{-- ================= OVERSIGHT ================= --}}
        @if($canPage(\App\Support\AdminPages::ACTIVITY))
            <div class="submenu-title">Oversight</div>
            <a href="{{ route('admin.activity.index') }}"
               class="nav-link"
               data-page="activity">
                <i class="bi bi-clock-history"></i> <span>Activity Log</span>
            </a>
        @endif

        @if($canAnyPage([\App\Support\AdminPages::SUPPORT, \App\Support\AdminPages::SUPPORT_FEEDBACK, \App\Support\AdminPages::NETWORK_REPORTS, \App\Support\AdminPages::NETWORK_GROUPS]))
            <div class="submenu-title">Support</div>
        @endif

        @if($canPage(\App\Support\AdminPages::ASSISTANT))
            <a href="{{ route('admin.assistant.index') }}"
               class="nav-link"
               data-page="assistant">
                <i class="bi bi-robot"></i> <span>Sales Assistant</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::ASSISTANT_LIVE))
            <a href="{{ route('admin.assistant.live') }}"
               class="nav-link"
               data-page="assistant-live">
                <i class="bi bi-headset"></i> <span>Live Chat</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::ASSISTANT_BLUEPRINTS))
            <a href="{{ route('admin.chatbot.blueprints') }}"
               class="nav-link"
               data-page="chatbot-blueprints">
                <i class="bi bi-diagram-3"></i> <span>Assistant Blueprints</span>
            </a>
        @endif

        {{-- The other end of a member's "Message admin". --}}
        @if($canPage(\App\Support\AdminPages::MESSAGES))
            <a href="{{ route('admin.messages.index') }}"
               class="nav-link"
               data-page="messages">
                <i class="bi bi-chat-dots"></i> <span>Member Messages</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::SUPPORT))
            <a href="{{ route('admin.support.index') }}"
               class="nav-link"
               data-page="support">
                <i class="bi bi-life-preserver"></i> <span>Support Enquiries</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::SUPPORT_FEEDBACK))
            <a href="{{ route('admin.support.feedback') }}"
               class="nav-link"
               data-page="support-feedback">
                <i class="bi bi-chat-heart"></i> <span>Feedback</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::NETWORK_REPORTS))
            <a href="{{ route('admin.network.reports') }}"
               class="nav-link"
               data-page="network-reports">
                <i class="bi bi-shield-exclamation"></i> <span>Network Reports</span>
            </a>
        @endif

        @if($canPage(\App\Support\AdminPages::NETWORK_GROUPS))
            <a href="{{ route('admin.network.groups') }}"
               class="nav-link"
               data-page="network-groups">
                <i class="bi bi-people-fill"></i> <span>Industry Groups</span>
            </a>
        @endif

        {{-- ================= VERIFICATION QUEUE ================= --}}
        @if($canAnyPage([
            \App\Support\AdminPages::ORGANIZATIONS,
            \App\Support\AdminPages::APPROVAL_WORKFLOW,
        ]))
            <div class="mt-auto mb-4 px-3">
                @livewire('admin.verification-queue-widget')
            </div>
        @endif

    </div>
</nav>

<div class="admin-sidebar-backdrop d-lg-none" data-admin-sidebar-backdrop hidden></div>

{{-- Active link highlighting --}}
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var currentPage = document.body.dataset.page;
        var links = document.querySelectorAll('.sidebar .nav-link');

        links.forEach(function (link) {
            link.classList.remove('active');
            if (link.dataset.page === currentPage) {
                link.classList.add('active');
            }
        });
    });
</script>
