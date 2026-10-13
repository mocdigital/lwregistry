<div class="logo-uploader">
    <label
        for="logo-input-{{ $business->id }}"
        class="logo-upload-container"
    >
        {{-- Current or placeholder image --}}
        @if($business->hasMedia('logo'))
            <img
                src="{{ $business->getFirstMediaUrl('logo') }}"
                alt="{{ $business->name }}"
                class="logo-preview"
            >
        @else
            <img
                src="{{ asset('images/placeholder-logo.svg') }}"
                alt="{{ $business->name }}"
                class="logo-preview"
            >
        @endif

        {{-- Hover overlay with plus icon --}}
        <div class="logo-overlay" wire:loading.remove wire:target="logo">
            <i class="bi bi-plus-lg"></i>
        </div>

        {{-- Loading overlay --}}
        <div class="logo-overlay logo-loading" wire:loading wire:target="logo">
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
        </div>

        {{-- Hidden file input --}}
        <input
            type="file"
            id="logo-input-{{ $business->id }}"
            wire:model="logo"
            accept="image/jpeg,image/jpg,image/png,image/webp"
            class="logo-input-hidden"
        >
    </label>

    {{-- Error message --}}
    @error('logo')
        <div class="logo-error">{{ $message }}</div>
    @enderror

    <style>
    .logo-uploader {
        display: inline-block;
    }

    .logo-upload-container {
        position: relative;
        display: block;
        width: 120px;
        height: 120px;
        cursor: pointer;
        overflow: hidden;
        border: 1px solid rgba(20,42,59,0.06);
        background: #fff;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .logo-upload-container:hover {
        border-color: var(--light-color, #1DBF73);
        box-shadow: 0 8px 24px rgba(27,169,222,0.12);
    }

    .logo-upload-container:hover .logo-overlay {
        opacity: 1;
    }

    .logo-loading {
        opacity: 1;
        background: rgba(20,42,59,0.7);
    }

    .logo-preview {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .logo-overlay {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(20,42,59,0.55);
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    .logo-overlay i {
        font-size: 2rem;
        color: #fff;
    }

    .logo-overlay .spinner-wrapper {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .logo-overlay .spinner-border {
        width: 1.5rem;
        height: 1.5rem;
        color: #fff;
    }

    .logo-input-hidden {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    .logo-error {
        margin-top: 8px;
        font-size: 0.85rem;
        color: #dc3545;
        max-width: 120px;
    }

    @media (max-width: 991.98px) {
        .logo-upload-container {
            width: 80px;
            height: 80px;
        }

        .logo-overlay i {
            font-size: 1.5rem;
        }

        .logo-error {
            max-width: 80px;
        }
    }
    </style>
</div>

