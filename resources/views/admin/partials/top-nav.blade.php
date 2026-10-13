@php
    $topNavUser = auth()->user();
    $topNavHasWorkspaceSwitch = $topNavUser?->isAdmin()
        && app(\App\Support\SboWorkspaceAccess::class)->hasOwnerWorkspace($topNavUser);
@endphp

<header class="top-nav">
    <div class="d-flex align-items-center gap-2">
        <button
            class="btn d-lg-none btn-sm btn-outline-secondary me-2 admin-sidebar-toggle"
            id="sidebarToggle"
            type="button"
            aria-controls="adminSidebar"
            aria-expanded="false"
            aria-label="Open sidebar menu"
            data-admin-sidebar-toggle
        >
            <i class="bi bi-list admin-sidebar-toggle-icon" aria-hidden="true"></i>
            <span class="admin-sidebar-toggle-label" data-admin-sidebar-toggle-label>Menu</span>
        </button>
        <h1 class="h5 mb-0 text-brand">{{ $pageTitle ?? 'Admin Dashboard' }}</h1>
    </div>

    <div class="d-flex align-items-center gap-3">
        <!--
        <div class="position-relative d-none d-md-block">
            <i class="bi bi-search search-icon"></i>
            <input
                type="search"
                class="form-control form-control-sm search-input"
                placeholder="Search users, businesses..."
            />
        </div>
        -->

        @if($topNavHasWorkspaceSwitch)
            <x-workspace-switcher class="d-none d-md-inline-flex" />
        @endif

        @livewire('notification-bell')

        <div class="dropdown">
            <button
                class="btn btn-sm btn-outline-secondary rounded-pill d-flex align-items-center px-2"
                data-bs-toggle="dropdown"
            >
                <x-user-avatar :user="auth()->user()" class="table-avatar me-1" style="width:28px;height:28px;" />
                <span class="d-none d-sm-inline small">{{ auth()->user()->firstname }}</span>
                <i class="bi bi-chevron-down ms-1 small"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header">Signed in as</h6></li>
                <li><span class="dropdown-item-text small">{{ auth()->user()->email }}</span></li>
                @if($topNavHasWorkspaceSwitch)
                    <li><hr class="dropdown-divider" /></li>
                    <li class="px-3 py-2 d-md-none">
                        <x-workspace-switcher class="w-100" />
                    </li>
                @endif
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger">Sign out</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
