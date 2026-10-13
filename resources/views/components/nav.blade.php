<nav class="navbar navbar-expand-lg fixed-top shadow-sm loveworld-navbar">
  <div class="container">
    <!-- Brand -->
    <a class="navbar-brand" href="{{ route('home') }}" wire:navigate>
      <img src="{{ asset('images/logo-light.png') }}" alt="Loveworld Registry" />
    </a>

    <!-- Toggle button -->
    <button
      class="navbar-toggler mobile-menu-toggle border-0 text-white"
      type="button"
      data-bs-toggle="collapse"
      data-bs-target="#navbarContent"
      aria-controls="navbarContent"
      aria-expanded="false"
      aria-label="Open navigation menu"
      data-mobile-menu-toggle
    >
      <i class="bi bi-list nav-toggle-icon" data-menu-icon aria-hidden="true"></i>
    </button>

    <!-- Collapsible menu -->
    <div class="collapse navbar-collapse" id="navbarContent">
      <div class="mobile-menu-actions d-lg-none">
        <button
          type="button"
          class="mobile-menu-close"
          aria-label="Close navigation menu"
          data-mobile-menu-close
        >
          <i class="bi bi-x-lg" aria-hidden="true"></i>
          <span>Close menu</span>
        </button>
      </div>

      <div class="navbar-search mx-lg-auto my-3 my-lg-0" id="navbarSearch">
        @livewire('business-search')
      </div>

      <ul class="navbar-nav ms-auto align-items-lg-center text-center">
        <li class="nav-item">
          <a href="{{ route('advertise') }}" class="nav-link" wire:navigate><span>Advertise</span></a>
        </li>

        <li class="nav-item">
          <a href="{{ route('feedback') }}" class="nav-link" wire:navigate><span>Give Feedback</span></a>
        </li>

        @guest
          <li class="nav-item"><a href="{{ route('join') }}" class="nav-link" wire:navigate><span>Join</span></a></li>
          <li class="nav-item"><a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#loginModal"><span>Log in</span></a></li>
        @endguest

        @auth
          <x-account-nav />
        @endauth
      </ul>
    </div>
  </div>
</nav>

@push('scripts')
<script>
function initializePublicNavigation() {
    const navbar = document.querySelector('.loveworld-navbar');
    const navbarSearch = document.getElementById('navbarSearch');
    const heroSearchWrapper = document.getElementById('heroSearchWrapper');
    const hideOnScroll = document.querySelectorAll('.hide-on-scroll');
    const navbarContent = document.getElementById('navbarContent');
    const menuToggle = document.querySelector('[data-mobile-menu-toggle]');
    const menuIcon = menuToggle ? menuToggle.querySelector('[data-menu-icon]') : null;
    const menuClose = document.querySelector('[data-mobile-menu-close]');

    // Function to handle scroll
    function handleScroll() {
        if (!navbar) return;

        if (window.scrollY > 100) {
            navbar.classList.add('scrolled');
            hideOnScroll.forEach(el => el.style.display = 'none');
        } else {
            navbar.classList.remove('scrolled');
            hideOnScroll.forEach(el => el.style.display = '');
        }
    }

    // Handle navbar search visibility based on hero SEARCH BAR (not entire hero)
    if (heroSearchWrapper && navbarSearch && navbarSearch.dataset.heroObserverReady !== 'true') {
        navbarSearch.dataset.heroObserverReady = 'true';

        // Use IntersectionObserver to detect when hero search bar scrolls behind navbar
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    // Hero search bar is visible - hide navbar search
                    navbarSearch.classList.remove('show');
                } else {
                    // Hero search bar is NOT visible (scrolled behind navbar) - show navbar search
                    navbarSearch.classList.add('show');
                }
            });
        }, {
            threshold: 0, // Trigger as soon as ANY part is not visible
            rootMargin: '-80px 0px 0px 0px' // Account for navbar height (~80px)
        });

        observer.observe(heroSearchWrapper);
    } else if (navbarSearch && !heroSearchWrapper) {
        // No hero search on this page - always show navbar search
        navbarSearch.classList.add('show');
    }

    // Attach scroll listener
    if (navbar && navbar.dataset.scrollListenerReady !== 'true') {
        navbar.dataset.scrollListenerReady = 'true';
        window.addEventListener('scroll', handleScroll);
    }

    // Run once on load
    handleScroll();

    if (!navbarContent || navbarContent.dataset.mobileMenuReady === 'true') {
        return;
    }

    navbarContent.dataset.mobileMenuReady = 'true';

    function setMenuState(isOpen) {
        if (!menuToggle) return;

        menuToggle.classList.toggle('is-open', isOpen);
        menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        menuToggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');

        if (menuIcon) {
            menuIcon.classList.toggle('bi-list', !isOpen);
            menuIcon.classList.toggle('bi-x-lg', isOpen);
        }
    }

    function hideMobileMenu() {
        if (window.innerWidth >= 992 || typeof bootstrap === 'undefined') return;

        const collapse = bootstrap.Collapse.getOrCreateInstance(navbarContent, { toggle: false });
        collapse.hide();
    }

    navbarContent.addEventListener('shown.bs.collapse', () => setMenuState(true));
    navbarContent.addEventListener('hidden.bs.collapse', () => setMenuState(false));

    if (menuClose) {
        menuClose.addEventListener('click', hideMobileMenu);
    }

    navbarContent.addEventListener('click', function(event) {
        const clickedLink = event.target.closest('a.nav-link, a[wire\\:navigate], [data-bs-toggle="modal"]');

        if (clickedLink) {
            hideMobileMenu();
        }
    });
}

document.addEventListener('DOMContentLoaded', initializePublicNavigation);
document.addEventListener('livewire:navigated', initializePublicNavigation);
</script>
@endpush
