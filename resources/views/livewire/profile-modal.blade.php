<div>
    <!-- Profile Modal -->
    <div class="modal fade modal-compact" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="save">
                    <div class="modal-header">
                        <h5 class="modal-title" id="profileModalLabel">
                            <i class="bi bi-person me-2"></i>Manage profile
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="profilePhoto" class="form-label">Profile picture</label>
                            <div class="profile-photo-field">
                                <div class="profile-photo-preview">
                                    @if($profilePhoto)
                                        <img src="{{ $profilePhoto->temporaryUrl() }}" alt="Selected profile picture preview">
                                    @else
                                        <x-user-avatar :user="auth()->user()" class="profile-photo-current" />
                                    @endif
                                </div>

                                <div class="flex-grow-1">
                                    <input
                                        id="profilePhoto"
                                        wire:model="profilePhoto"
                                        class="form-control @error('profilePhoto') is-invalid @enderror"
                                        type="file"
                                        accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                    >
                                    @error('profilePhoto')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <div class="form-note">PNG or JPG only. Maximum file size: 1MB.</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="profileFirstName" class="form-label">First name</label>
                            <input
                                id="profileFirstName"
                                wire:model="firstname"
                                class="form-control @error('firstname') is-invalid @enderror"
                                type="text"
                                required
                                placeholder="e.g. Nicolas"
                            >
                            @error('firstname')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="profileLastName" class="form-label">Last name</label>
                            <input
                                id="profileLastName"
                                wire:model="lastname"
                                class="form-control @error('lastname') is-invalid @enderror"
                                type="text"
                                required
                                placeholder="e.g. Akpas"
                            >
                            @error('lastname')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="profilePhone" class="form-label">Phone</label>
                            <input
                                id="profilePhone"
                                wire:model="phone"
                                class="form-control @error('phone') is-invalid @enderror"
                                type="tel"
                                placeholder="+234 700 000 000"
                            >
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-note">This number will be shown on your public listings.</div>
                        </div>

                        <div class="mb-3">
                            <label for="profileKcHandle" class="form-label">KC Handle</label>
                            <input
                                id="profileKcHandle"
                                wire:model="kcHandle"
                                class="form-control @error('kcHandle') is-invalid @enderror"
                                type="text"
                                maxlength="255"
                                placeholder="@yourhandle"
                            >
                            @error('kcHandle')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-cta" wire:loading.attr="disabled">
                            <span wire:loading.remove>Save changes</span>
                            <span wire:loading>
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                Saving...
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        /* Modal compact styling */
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
        .profile-photo-field {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .profile-photo-preview {
            width: 64px;
            height: 64px;
            flex: 0 0 64px;
        }
        .profile-photo-preview img,
        .profile-photo-current {
            width: 64px;
            height: 64px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(20,42,59,0.08);
            color: var(--dark-color);
            font-weight: 800;
            object-fit: cover;
        }
    </style>
</div>

@script
<script>
    $wire.on('show-profile-modal', () => {
        const modalEl = document.getElementById('profileModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    });

    $wire.on('close-profile-modal', () => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) {
            modal.hide();
        }
    });

    $wire.on('profile-updated', () => {
        window.location.reload();
    });

    // Focus first input when modal opens
    document.getElementById('profileModal')?.addEventListener('shown.bs.modal', function() {
        document.getElementById('profileFirstName')?.focus();
    });
</script>
@endscript
