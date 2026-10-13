<link href="{{ asset('css/account-nav.css?v=1.5') }}" rel="stylesheet">

@php
    $accountUser = auth()->user();
    $sboWorkspaceLocked = auth()->check()
        && app(\App\Support\SboWorkspaceAccess::class)->requiresPayment($accountUser);
    $sboOwnerWorkspace = auth()->check()
        && app(\App\Support\SboWorkspaceAccess::class)->hasOwnerWorkspace($accountUser);
    $sboNetworkReady = auth()->check()
        && app(\App\Support\SboNetworkAccess::class)->userCanUseNetwork($accountUser);

    $applicationProgressUrl = route('sbo.applications.index');

    if ($sboOwnerWorkspace) {
        $progressTargetSbo = $accountUser->sbos()
            ->orderBy('name')
            ->first();

        $applicationProgressUrl = $progressTargetSbo
            ? route('sbo.applications.progress', $progressTargetSbo)
            : route('sbo.applications.index');
    }

    $sboRfqsUrl = $sboNetworkReady ? route('sbo.rfqs.index') : $applicationProgressUrl;
    $sboNetworkUrl = $sboNetworkReady ? route('sbo.network.index') : $applicationProgressUrl;
    $sboCategoriesUrl = route('sbo.categories.index');
@endphp

<nav class="navbar navbar-expand-lg fixed-top shadow-sm loveworld-navbar">
  <div class="container">
    <!-- Brand -->
    <a class="navbar-brand" href="{{ route('home') }}" wire:navigate>
      <img src="{{ asset('images/logo-light.png') }}" alt="Loveworld Registry" />
    </a>

    <div class="account-navbar-actions">
      @livewire('notification-bell', [], key('account-notification-bell'))

      <button
        class="account-trigger btn"
        id="accountToggle"
        data-bs-toggle="offcanvas"
        data-bs-target="#accountPanel"
        aria-controls="accountPanel"
        aria-expanded="false"
        aria-label="Open account panel"
        data-account-menu-toggle
      >
        <i class="bi bi-list nav-toggle-icon d-lg-none" data-menu-icon aria-hidden="true"></i>
        <span class="account-toggle-label d-lg-none" data-menu-label>Menu</span>
        <x-user-avatar :user="auth()->user()" class="avatar d-none d-lg-inline-flex" />
        <span class="d-none d-lg-block text-start">
          <div class="name">{{ auth()->user()->name }}</div>
          <div class="muted-sm">{{ auth()->user()->email }}</div>
        </span>
        <i class="bi bi-chevron-right ms-2 d-none d-lg-inline" style="font-size:1.05rem;color:var(--muted);"></i>
      </button>
    </div>

    <!-- Collapsible menu -->
    <div class="collapse navbar-collapse" id="navbarContent">
      <div class="navbar-search mx-lg-auto my-3 my-lg-0" id="navbarSearch">
        @livewire('business-search')
      </div>
    </div>
  </div>
</nav>

<!-- Offcanvas: right-hand account panel -->
<div class="offcanvas offcanvas-end custom-panel" tabindex="-1" id="accountPanel" aria-labelledby="accountPanelLabel" data-bs-scroll="false" data-bs-backdrop="true">
  <div class="panel-header d-flex gap-3 align-items-center">
    <div>
      <x-user-avatar :user="auth()->user()" class="panel-avatar" />
    </div>
    <div class="flex-grow-1">
      <h5 id="accountPanelLabel" style="margin-bottom:4px;">{{ auth()->user()->name }}</h5>
      <div class="muted-sm">{{ auth()->user()->email }}</div>
      <div class="mt-2">
        @if(auth()->user()->email_verified_at)
        <span class="badge rounded-pill" style="background:rgba(29,191,115,0.12); color:var(--light-color); padding:.35rem .6rem;">Verified</span>
        @endif
      </div>
    </div>

    <button type="button" class="panel-close" data-bs-dismiss="offcanvas" aria-label="Close account menu">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
      <span>Close menu</span>
    </button>
  </div>

  <div class="offcanvas-body p-3">
	    <!-- Menu -->
	    <div class="account-menu mb-3">
	      <x-workspace-switcher class="w-100 mb-3" />

	      <div class="list-group" role="menu" aria-label="Account menu">
        <a href="{{ route('account.profile') }}" class="list-group-item list-group-item-action d-flex align-items-center" role="menuitem" wire:navigate>
          <i class="bi bi-person"></i>
          <div>
            <div style="font-weight:700;">Profile</div>
            <div class="muted-sm">Manage account details</div>
          </div>
        </a>

        @if($sboOwnerWorkspace)
        <a href="{{ route('sbo.dashboard') }}" class="list-group-item list-group-item-action d-flex align-items-center" role="menuitem" wire:navigate>
          <i class="bi bi-speedometer2"></i>
          <div>
            <div style="font-weight:700;">SBO Dashboard</div>
            <div class="muted-sm">Your SBO overview</div>
          </div>
        </a>
        <a href="{{ $applicationProgressUrl }}" class="list-group-item list-group-item-action d-flex align-items-center" role="menuitem" wire:navigate>
          <i class="bi bi-list-check"></i>
          <div>
            <div style="font-weight:700;">Application Progress</div>
            <div class="muted-sm">Track registration steps</div>
          </div>
        </a>
        <a href="{{ $sboRfqsUrl }}" class="list-group-item list-group-item-action d-flex align-items-center {{ $sboNetworkReady ? '' : 'is-locked' }}" role="menuitem" wire:navigate>
          <i class="bi {{ $sboNetworkReady ? 'bi-clipboard-check' : 'bi-lock' }}"></i>
          <div>
            <div style="font-weight:700;">Bids & RFQs</div>
            <div class="muted-sm">{{ $sboNetworkReady ? 'Create requests and manage bids' : 'Unlock after full validation' }}</div>
          </div>
        </a>
        <a href="{{ $sboNetworkUrl }}" class="list-group-item list-group-item-action d-flex align-items-center {{ $sboNetworkReady ? '' : 'is-locked' }}" role="menuitem" wire:navigate>
          <i class="bi bi-diagram-3"></i>
          <div>
            <div style="font-weight:700;">SBO Network</div>
            <div class="muted-sm">{{ $sboNetworkReady ? 'Connect, message, and discover bids' : 'Unlock after full validation' }}</div>
          </div>
        </a>
        <a href="{{ $sboCategoriesUrl }}" class="list-group-item list-group-item-action d-flex align-items-center" role="menuitem" wire:navigate>
          <i class="bi bi-tags"></i>
          <div>
            <div style="font-weight:700;">SBO Categories</div>
            <div class="muted-sm">Search SBOs by category and status</div>
          </div>
        </a>
        @endif

        <a href="{{ route('dashboard') }}#favorites" class="list-group-item list-group-item-action d-flex align-items-center" role="menuitem" wire:navigate data-tab="favorites">
          <i class="bi bi-heart"></i>
          <div>
            <div style="font-weight:700;">Favorites</div>
            <div class="muted-sm">Saved SBOs</div>
          </div>
          <span class="ms-auto badge rounded-pill bg-light text-muted">{{ auth()->user()->favorites()->count() }}</span>
        </a>

        <a href="{{ route('support.index') }}" class="list-group-item list-group-item-action d-flex align-items-center" role="menuitem" wire:navigate>
          <i class="bi bi-life-preserver"></i>
          <div>
            <div style="font-weight:700;">Support</div>
            <div class="muted-sm">Get help from the registry team</div>
          </div>
        </a>

        <a href="{{ route('feedback') }}" class="list-group-item list-group-item-action d-flex align-items-center" role="menuitem" wire:navigate>
          <i class="bi bi-chat-heart"></i>
          <div>
            <div style="font-weight:700;">Give Feedback</div>
            <div class="muted-sm">Share ideas or report issues</div>
          </div>
        </a>

        <div class="mt-2 px-1">
          <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="list-group-item list-group-item-action d-flex align-items-center text-danger w-100" role="menuitem">
              <i class="bi bi-box-arrow-right"></i>
              <div style="font-weight:700;">Logout</div>
            </button>
          </form>
        </div>
      </div>
    </div>

    <hr>

    <!-- Extras: quick stats / shortcuts -->
    <div class="mb-3">
      <div class="d-flex gap-2 align-items-center mb-2">
        <i class="bi bi-bar-chart-line-fill" style="font-size:1.1rem;color:var(--light-color)"></i>
        <div>
          <div style="font-weight:700;">Quick stats</div>
          <div class="muted-sm">Listings: <strong>{{ auth()->user()->businesses()->count() }}</strong> • Views (30d): <strong>{{ auth()->user()->businesses()->sum('views_count') }}</strong></div>
        </div>
      </div>

      <div class="d-grid">
        <a href="{{ $sboWorkspaceLocked ? route('sbo.payment-required') : route('dashboard') }}" class="list-business-cta" wire:navigate>
          {{ $sboWorkspaceLocked ? 'Complete payment' : 'Manage listings' }}
        </a>
      </div>
    </div>

    <div class="small text-muted">
      Need help? <a href="{{ route('support.index') }}" wire:navigate>Contact support</a>
    </div>
  </div>
</div>

@push('scripts')
<script>
function initializeAccountNavigation() {
    const offcanvasEl = document.getElementById('accountPanel');

    if (!offcanvasEl) return;

    // Prevent duplicate event listener registration
    if (offcanvasEl.dataset.listenersAttached === 'true') {
        return;
    }
    offcanvasEl.dataset.listenersAttached = 'true';

    const accountToggles = document.querySelectorAll('[data-account-menu-toggle][data-bs-target="#accountPanel"]');

    // Flag to track when we're transitioning to a modal
    let openingModal = false;

    function setAccountMenuState(isOpen) {
        accountToggles.forEach((toggle) => {
            const icon = toggle.querySelector('[data-menu-icon]');

            toggle.classList.toggle('is-open', isOpen);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', isOpen ? 'Close account panel' : 'Open account panel');

            if (icon) {
                icon.classList.toggle('bi-list', !isOpen);
                icon.classList.toggle('bi-x-lg', isOpen);
            }

            const label = toggle.querySelector('[data-menu-label]');
            if (label) {
                label.textContent = isOpen ? 'Close' : 'Menu';
            }
        });
    }

    // Helper function to safely clean up orphaned backdrops
    // Only removes backdrops when no active modals/offcanvas exist
    function cleanupOrphanedBackdrops() {
        // Check if any modals or offcanvas are actually showing
        const activeModals = document.querySelectorAll('.modal.show, .offcanvas.show');

        if (activeModals.length === 0) {
            // Only clean up if nothing is active
            const backdrops = document.querySelectorAll('.offcanvas-backdrop, .modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());

            // Reset body state
            document.body.classList.remove('modal-open', 'offcanvas-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    }

    accountToggles.forEach((toggle) => {
        toggle.addEventListener('click', function(e) {
            if (offcanvasEl.classList.contains('show')) {
                e.preventDefault();
                e.stopImmediatePropagation();

                const offcanvasInstance = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
                offcanvasInstance.hide();
            }
        });
    });

    offcanvasEl.addEventListener('shown.bs.offcanvas', function() {
        setAccountMenuState(true);
    });

    offcanvasEl.addEventListener('hidden.bs.offcanvas', function() {
        setAccountMenuState(false);
    });

    // Handle wire:navigate links inside offcanvas
    offcanvasEl.addEventListener('click', function(e) {
        const link = e.target.closest('a[wire\\:navigate]');

        if (link) {
            e.preventDefault();
            e.stopPropagation();

            const href = link.getAttribute('href');
            const offcanvasInstance = bootstrap.Offcanvas.getInstance(offcanvasEl);

            if (offcanvasInstance) {
                // Close offcanvas first
                offcanvasInstance.hide();

                // Navigate after offcanvas is fully hidden
                offcanvasEl.addEventListener('hidden.bs.offcanvas', function navigateHandler() {
                    // Remove this specific listener
                    offcanvasEl.removeEventListener('hidden.bs.offcanvas', navigateHandler);

                    // Use Livewire navigation
                    if (typeof Livewire !== 'undefined' && Livewire.navigate) {
                        Livewire.navigate(href);
                    } else {
                        window.location.href = href;
                    }
                }, { once: true });
            } else {
                // If no instance, just navigate normally
                if (typeof Livewire !== 'undefined' && Livewire.navigate) {
                    Livewire.navigate(href);
                } else {
                    window.location.href = href;
                }
            }
        }
    });

    // Handle modal trigger (List a Business button)
    const modalTrigger = offcanvasEl.querySelector('[data-bs-target="#createBusinessModal"]');
    if (modalTrigger) {
        modalTrigger.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const offcanvasInstance = bootstrap.Offcanvas.getInstance(offcanvasEl);

            if (offcanvasInstance) {
                // Set flag to indicate we're opening a modal
                openingModal = true;

                // Close offcanvas first
                offcanvasInstance.hide();

                // Open modal after offcanvas is fully hidden
                offcanvasEl.addEventListener('hidden.bs.offcanvas', function modalHandler() {
                    offcanvasEl.removeEventListener('hidden.bs.offcanvas', modalHandler);

                    // Clean up only the offcanvas backdrop before opening modal
                    const offcanvasBackdrops = document.querySelector('.offcanvas-backdrop');
                    if (offcanvasBackdrops) {
                        offcanvasBackdrops.remove();
                    }

                    const modalEl = document.getElementById('createBusinessModal');
                    if (modalEl) {
                        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                        modal.show();

                        // Reset flag after modal is fully shown
                        modalEl.addEventListener('shown.bs.modal', function resetFlag() {
                            modalEl.removeEventListener('shown.bs.modal', resetFlag);
                            openingModal = false;
                        }, { once: true });
                    } else {
                        openingModal = false;
                    }
                }, { once: true });
            } else {
                // If no instance, just open modal
                const modalEl = document.getElementById('createBusinessModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                }
            }
        });
    }

    // Safety check: Clean up orphaned backdrops after offcanvas closes
    // Only runs if no active modals/offcanvas exist (Bootstrap should handle this, but this is a safety net)
    offcanvasEl.addEventListener('hidden.bs.offcanvas', function() {
        // Only clean up if we're not transitioning to a modal
        if (!openingModal) {
            // Use a small delay to let Bootstrap finish its cleanup
            setTimeout(() => {
                cleanupOrphanedBackdrops();
            }, 100);
        }
    });

    // Listen for favorite events to update count badge in real-time
    document.addEventListener('livewire:init', () => {
        Livewire.on('favorite-added', (event) => {
            // Update favorites count badge
            const favoritesBadge = document.querySelector('[data-tab="favorites"] .badge');
            if (favoritesBadge) {
                const currentCount = parseInt(favoritesBadge.textContent) || 0;
                favoritesBadge.textContent = currentCount + 1;
            }
        });

        Livewire.on('favorite-removed', (event) => {
            // Update favorites count badge
            const favoritesBadge = document.querySelector('[data-tab="favorites"] .badge');
            if (favoritesBadge) {
                const currentCount = parseInt(favoritesBadge.textContent) || 0;
                favoritesBadge.textContent = Math.max(0, currentCount - 1);
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initializeAccountNavigation);
document.addEventListener('livewire:navigated', initializeAccountNavigation);
</script>
@endpush
