<div>
    <!-- Security Modal (Change Password) -->
    <div class="modal fade modal-compact" id="securityModal" tabindex="-1" aria-labelledby="securityModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="save">
                    <div class="modal-header">
                        <h5 class="modal-title" id="securityModalLabel">
                            <i class="bi bi-key me-2"></i>Change password
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="currentPassword" class="form-label">Current password</label>
                            <input
                                id="currentPassword"
                                wire:model="current_password"
                                class="form-control @error('current_password') is-invalid @enderror"
                                type="password"
                                required
                                placeholder="••••••••"
                                autocomplete="current-password"
                            >
                            @error('current_password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="newPassword" class="form-label">New password</label>
                            <input
                                id="newPassword"
                                wire:model="password"
                                class="form-control @error('password') is-invalid @enderror"
                                type="password"
                                required
                                minlength="8"
                                placeholder="At least 8 characters"
                                autocomplete="new-password"
                            >
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="confirmPassword" class="form-label">Confirm new password</label>
                            <input
                                id="confirmPassword"
                                wire:model="password_confirmation"
                                class="form-control @error('password_confirmation') is-invalid @enderror"
                                type="password"
                                required
                                minlength="8"
                                placeholder="Repeat new password"
                                autocomplete="new-password"
                            >
                            @error('password_confirmation')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-note">Choose a strong password; avoid reusing passwords used elsewhere.</div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-cta" wire:loading.attr="disabled">
                            <span wire:loading.remove>Change password</span>
                            <span wire:loading>
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                Changing...
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        /* Modal compact styling (shared with ProfileModal) */
        .modal-compact .modal-content {
            border-radius: 0px;
            overflow: hidden;
            box-shadow: 0 26px 60px rgba(20,42,59,0.10);
            border: none;
        }
        .modal-compact .modal-header {
            background: linear-gradient(90deg, rgba(27,169,222,0.06), rgba(20,42,59,0.01));
            border-bottom: none;
            padding: 14px 18px;
        }
        .modal-compact .modal-title {
            font-weight: 800;
            color: var(--dark-color);
            font-size: 1.05rem;
        }
        .modal-compact .modal-body {
            padding: 16px 18px;
            background: var(--bg-light-gray);
        }
        .modal-compact .form-label {
            font-weight: 700;
            color: var(--dark-color);
            font-size: .95rem;
        }
        .modal-compact .form-control {
            border-radius: 0px;
            border: 1px solid rgba(20,42,59,0.08);
            padding: .5rem .75rem;
        }
        .modal-compact .form-control:focus {
            border-color: var(--light-color);
            box-shadow: 0 0 0 3px rgba(27,169,222,0.1);
        }
        .modal-compact .modal-footer {
            gap: .5rem;
            padding: 12px 16px;
            border-top: none;
            background: var(--bg-light-gray);
        }
        .form-note {
            color: var(--muted);
            font-size: .92rem;
            margin-top: 6px;
        }
    </style>
</div>

@script
<script>
    $wire.on('show-security-modal', () => {
        const modalEl = document.getElementById('securityModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    });

    $wire.on('close-security-modal', () => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('securityModal'));
        if (modal) {
            modal.hide();
        }
    });

    $wire.on('password-updated', () => {
        window.location.reload();
    });

    // Focus first input when modal opens
    document.getElementById('securityModal')?.addEventListener('shown.bs.modal', function() {
        document.getElementById('currentPassword')?.focus();
    });
</script>
@endscript
