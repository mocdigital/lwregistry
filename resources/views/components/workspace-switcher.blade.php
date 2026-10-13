@php
    $workspaceUser = auth()->user();
    $workspaceAccess = $workspaceUser ? app(\App\Support\SboWorkspaceAccess::class) : null;
    $showWorkspaceSwitcher = $workspaceUser?->isAdmin() && $workspaceAccess?->hasOwnerWorkspace($workspaceUser);
    $adminWorkspaceRoute = $workspaceUser
        ? \App\Support\AdminPages::firstAccessibleRouteName($workspaceUser)
        : null;
@endphp

@if($showWorkspaceSwitcher && $adminWorkspaceRoute)
    {{-- Full page loads on purpose: the two workspaces run on different layouts
         (the admin component layout vs. layouts.app), and wire:navigate keeps
         the current layout's assets and runtime alive while only swapping the
         body — so crossing between them leaves the destination half-styled.
         A real navigation boots the target layout cleanly. --}}
    <div {{ $attributes->class('btn-group btn-group-sm workspace-switcher') }} role="group" aria-label="Workspace switcher">
        <a
            href="{{ route($adminWorkspaceRoute) }}"
            @class([
                'btn',
                'btn-primary' => request()->routeIs('admin.*'),
                'btn-outline-primary' => ! request()->routeIs('admin.*'),
            ])
        >
            Admin Workspace
        </a>
        <a
            href="{{ route('sbo.dashboard') }}"
            @class([
                'btn',
                'btn-primary' => ! request()->routeIs('admin.*'),
                'btn-outline-primary' => request()->routeIs('admin.*'),
            ])
        >
            SBO Owner Workspace
        </a>
    </div>
@endif
