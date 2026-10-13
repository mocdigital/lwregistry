<div>
    <!-- Login Modal -->
    <div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content auth-modal auth-modal--premium shadow-sm">
                <div class="modal-header">
                    <h5 class="modal-title text-center" id="loginModalLabel">Account Login</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="color:#fff">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="row g-0">
                        <div class="col-12 col-md-12 auth-right">
                            <form wire:submit.prevent="login" class="mb-3 premium-auth-form">
                                <div class="mb-3">
                                    <input type="email" wire:model="loginEmail" class="form-control" placeholder="Email" data-form-help="Enter the email address attached to your registry account." required>
                                    @error('loginEmail') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                                <div class="mb-2">
                                    <div class="position-relative">
                                        <input id="loginPasswordInput" type="password" wire:model="loginPassword" class="form-control password-toggle-input" placeholder="Password" data-form-help="Enter your account password. Use the visibility icon only when you are in a private setting." required autocomplete="current-password">
                                        <button type="button" class="btn btn-sm position-absolute end-0 top-50 translate-middle-y password-toggle-btn" data-password-toggle="loginPasswordInput" aria-label="Show password" aria-pressed="false">
                                            <svg data-password-icon="show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                                <circle cx="12" cy="12" r="3"/>
                                            </svg>
                                            <svg data-password-icon="hide" class="d-none" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                                                <line x1="1" y1="1" x2="23" y2="23"/>
                                            </svg>
                                        </button>
                                    </div>
                                    @error('loginPassword') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" wire:model="remember" id="rememberMe">
                                        <label class="form-check-label" for="rememberMe">Remember me</label>
                                    </div>
                                    <div><a href="{{ route('password.request') }}">Forgot your password?</a></div>
                                </div>

                                <button type="submit" class="btn login-btn w-100 rounded-pill">Login</button>
                            </form>

                            <div class="text-center mt-2">
                                <small>Don't have an account yet? <a href="{{ route('join') }}">Click here to sign up!</a></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <style>
        /* Focus styles for password visibility toggle buttons - change icon color */
        .password-toggle-btn:focus svg,
        .password-toggle-btn:focus-visible svg {
            stroke: rgba(27, 169, 222, 1);
        }

        .password-toggle-input {
            padding-right: 3rem;
        }

        .password-toggle-btn {
            align-items: center;
            background: transparent;
            border: 0;
            color: #6c757d;
            display: inline-flex;
            height: 44px;
            justify-content: center;
            padding: 0;
            touch-action: manipulation;
            width: 44px;
            z-index: 3;
        }

        .password-toggle-btn:hover,
        .password-toggle-btn:active {
            color: rgba(27, 169, 222, 1);
        }

        /* Registration type tabs */
        .register-type-tabs .nav-link {
            border: 1px solid rgba(27, 169, 222, 0.4);
            color: rgba(27, 169, 222, 1);
            background: transparent;
            border-radius: 0.5rem;
            font-weight: 600;
        }
        .register-type-tabs .nav-item:first-child .nav-link { margin-right: .25rem; }
        .register-type-tabs .nav-link.active {
            background: rgba(27, 169, 222, 1);
            color: #fff;
            border-color: rgba(27, 169, 222, 1);
        }

        .auth-modal--premium .modal-body {
            background:
                linear-gradient(180deg, #ffffff, #fbfdfc),
                linear-gradient(135deg, rgba(244, 185, 66, 0.07), rgba(29, 191, 115, 0.05));
        }

        .premium-auth-form .form-control,
        .premium-auth-form .form-select {
            border-color: rgba(11, 37, 69, 0.14);
            border-radius: 8px;
            min-height: 46px;
        }

        .premium-auth-form .form-control:focus,
        .premium-auth-form .form-select:focus {
            border-color: rgba(29, 191, 115, 0.68);
            box-shadow: 0 0 0 0.18rem rgba(29, 191, 115, 0.14);
        }

        .premium-auth-step-panel {
            border: 1px solid rgba(11, 37, 69, 0.08);
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 10px 28px rgba(11, 37, 69, 0.05);
            padding: clamp(14px, 3vw, 18px);
        }

        /* SBO multi-step indicator */
        .sbo-steps { gap: .25rem; }
        .sbo-step-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            font-weight: 700;
        }
        .sbo-step-badge.active {
            background: rgba(27, 169, 222, 1);
            color: #fff;
        }
        .sbo-step-line {
            flex: 1 1 auto;
            height: 2px;
            background: #e9ecef;
            margin: 0 .25rem;
            align-self: flex-start;
            margin-top: 16px;
        }
        .sbo-step-line.active { background: rgba(27, 169, 222, 1); }

        .premium-auth-stepper {
            border: 1px solid rgba(11, 37, 69, 0.08);
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 10px 28px rgba(11, 37, 69, 0.05);
            padding: 12px;
        }

        /* Code of Conduct scrollable box */
        .code-of-conduct-box {
            max-height: 220px;
            overflow-y: auto;
            border: 1px solid rgba(20, 42, 59, 0.15);
            border-radius: 0;
            background: #fff;
            padding: 14px 16px;
            font-size: .88rem;
            line-height: 1.5;
            color: #333;
        }
        .code-of-conduct-box:focus { outline: 2px solid rgba(27, 169, 222, .4); outline-offset: 1px; }
        .code-of-conduct-box ol { margin-bottom: .5rem; }

        /* Provisional registration callout */
        .provisional-callout {
            display: flex;
            gap: .6rem;
            align-items: flex-start;
            padding: .75rem .9rem;
            background: rgba(244, 185, 66, 0.12);
            border: 1px solid rgba(244, 185, 66, 0.45);
            border-radius: 0;
            color: #7a5b0b;
            font-size: .85rem;
            line-height: 1.45;
        }
        .provisional-callout svg { color: #c8901a; }

        .premium-auth-callout {
            border-radius: 12px;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(255, 250, 236, 0.9)),
                linear-gradient(135deg, rgba(244, 185, 66, 0.18), rgba(29, 191, 115, 0.08));
        }
    </style>
</div>

@script
<script>
    // Wrap everything in IIFE to ensure proper scoping
    (function() {
        // Helper function to properly clean up all modals and backdrops
        function cleanupModals() {
            // Remove all backdrops
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
                backdrop.remove();
            });

            // Reset body state
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }

        function togglePasswordVisibility(button) {
            const inputId = button.getAttribute('data-password-toggle');
            const input = inputId ? document.getElementById(inputId) : null;

            if (!input) {
                return;
            }

            const shouldShow = input.type === 'password';
            input.type = shouldShow ? 'text' : 'password';
            button.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
            button.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');

            button.querySelector('[data-password-icon="show"]')?.classList.toggle('d-none', shouldShow);
            button.querySelector('[data-password-icon="hide"]')?.classList.toggle('d-none', !shouldShow);
        }

        document.addEventListener('pointerdown', function(e) {
            const button = e.target.closest('[data-password-toggle]');

            if (!button) {
                return;
            }

            e.preventDefault();
            togglePasswordVisibility(button);
        });

        document.addEventListener('click', function(e) {
            const button = e.target.closest('[data-password-toggle]');

            if (!button) {
                return;
            }

            e.preventDefault();
        });

        document.addEventListener('keydown', function(e) {
            const button = e.target.closest('[data-password-toggle]');

            if (!button || !['Enter', ' '].includes(e.key)) {
                return;
            }

            e.preventDefault();
            togglePasswordVisibility(button);
        });

        // Handle successful login - close modal and redirect
        window.addEventListener('close-auth-modal', () => {
            const loginModal = document.getElementById('loginModal');

            const loginInstance = loginModal
                ? bootstrap.Modal.getInstance(loginModal)
                : null;

            if (loginInstance) loginInstance.hide();

            cleanupModals();
        });


        // Listen for open-login-modal event from FavoriteButton (for guests)
        // Use Livewire.on() for cross-component events, not $wire.on()
        Livewire.on('open-login-modal', () => {
            const loginModal = document.getElementById('loginModal');
            if (loginModal) {
                const modal = new bootstrap.Modal(loginModal);
                modal.show();
            }
        });

        document.addEventListener('click', function(e) {
            const redirectTrigger = e.target.closest('[data-auth-redirect]');

            if (!redirectTrigger) {
                return;
            }

            const targetUrl = redirectTrigger.getAttribute('data-auth-redirect');

            if (targetUrl) {
                @this.call('rememberLoginRedirect', targetUrl);
            }
        });

        // Listen for modal hidden events to clean up properly
        const loginModal = document.getElementById('loginModal');

        if (loginModal) {
            loginModal.addEventListener('hidden.bs.modal', function () {
                // Skip cleanup if we're switching to another modal
                if (loginModal.dataset.switching === 'true') {
                    return;
                }
                /*
                if (@this.redirecting) {
                    cleanupModals();
                    return;
                }
                */
                // Ensure cleanup after Bootstrap finishes hiding
                cleanupModals();
                // Reset form fields when modal fully closes
                /*
                if (typeof $wire !== 'undefined') {
                    $wire.call('resetFormFields');
                }
                */
            });
        }

        // Handle modal switching with proper cleanup
        document.addEventListener('click', function(e) {
            const switchLink = e.target.closest('.modal-switch-link');
            if (switchLink) {
                e.preventDefault();
                e.stopPropagation();

                const currentModalId = switchLink.dataset.current;
                const targetModalId = switchLink.dataset.target;

                const currentModal = document.getElementById(currentModalId);
                const targetModal = document.getElementById(targetModalId);

                if (currentModal && targetModal) {
                    // Set a flag to prevent the default hidden.bs.modal handlers from interfering
                    currentModal.dataset.switching = 'true';
                    targetModal.dataset.switching = 'true';

                    // Get or create current modal instance
                    let currentInstance = bootstrap.Modal.getInstance(currentModal);
                    if (!currentInstance) {
                        currentInstance = new bootstrap.Modal(currentModal);
                    }

                    // Hide current modal
                    currentInstance.hide();

                    // Wait for hide animation to complete before showing next modal
                    const showNextModal = function() {
                        // Only proceed if we're actually switching (not just closing)
                        if (currentModal.dataset.switching === 'true') {
                            // Clean up any lingering backdrops
                            cleanupModals();

                            // Small delay to ensure cleanup is complete
                            setTimeout(() => {
                                // Reset form fields when switching modals
                                if (typeof $wire !== 'undefined' && $wire.call) {
                                    $wire.call('resetFormFields');
                                }

                                // Show target modal using getOrCreateInstance
                                const targetInstance = bootstrap.Modal.getOrCreateInstance(targetModal);
                                targetInstance.show();

                                // Clear the switching flags
                                delete currentModal.dataset.switching;
                                delete targetModal.dataset.switching;
                            }, 100);
                        }
                    };

                    currentModal.addEventListener('hidden.bs.modal', showNextModal, { once: true });
                }
            }
        });
    })();
</script>
@endscript
