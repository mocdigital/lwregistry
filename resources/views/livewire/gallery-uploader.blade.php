<div
    class="gallery-uploader"
    x-data="{
        mainImage: '{{ $business->getMedia('gallery')->first()?->getUrl() ?? '' }}',
        setMainImage(src) {
            this.mainImage = src;
        }
    }"
>
    <div class="card gallery-card p-0 overflow-hidden">
        @php
            $galleryMedia = $business->getMedia('gallery');
            $galleryCount = $galleryMedia->count();
            $mainImage = $galleryCount > 0 ? $galleryMedia->first()->getUrl() : null;
        @endphp

        <!-- Main profile hero image -->
        <template x-if="mainImage">
            <img
                id="gallery-heroImage"
                :src="mainImage"
                alt="{{ $business->name }}"
                class="w-100"
                style="height:320px; object-fit:cover; display:block; cursor:pointer;"
                data-bs-toggle="modal"
                data-bs-target="#photoLightbox"
                @click="document.getElementById('lightboxImage').src = mainImage"
            >
        </template>
        <template x-if="!mainImage">
            <div class="gallery-placeholder-main" style="height:320px; background:#f8fafc; display:flex; align-items:center; justify-content:center;">
                <span class="text-muted">Upload gallery images below</span>
            </div>
        </template>

        <!-- Thumbnails row -->
        <div class="d-flex gap-2 p-3 align-items-center">
            <div class="d-flex flex-row gap-2 flex-wrap" style="flex:1;">
                @for($i = 0; $i < 5; $i++)
                    @if($i < $galleryCount)
                        @php $media = $galleryMedia->get($i); @endphp
                        <!-- Uploaded image slot -->
                        <div
                            class="gallery-thumb-slot"
                            x-data="{ inputId: 'gallery-replace-{{ $business->id }}-{{ $i }}', imgSrc: '{{ $media->getUrl() }}' }"
                            @click="setMainImage(imgSrc)"
                            @dblclick.stop="$wire.setReplaceIndex({{ $i }}); $nextTick(() => document.getElementById(inputId).click())"
                            title="Click to view, double-click to replace"
                        >
                            <img
                                class="thumb-small rounded gallery-thumb-img"
                                :src="imgSrc"
                                alt="Gallery image {{ $i + 1 }}"
                            >
                            <div class="gallery-thumb-overlay" wire:loading.remove wire:target="image">
                                <i class="bi bi-arrow-repeat"></i>
                            </div>
                            <div class="gallery-thumb-overlay gallery-loading" wire:loading wire:target="image">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            </div>
                            <input
                                type="file"
                                id="gallery-replace-{{ $business->id }}-{{ $i }}"
                                wire:model="image"
                                accept="image/jpeg,image/jpg,image/png,image/webp"
                                class="gallery-input-hidden"
                            >
                        </div>
                    @else
                        <!-- Empty upload slot -->
                        <label
                            for="gallery-upload-{{ $business->id }}-{{ $i }}"
                            class="gallery-upload-slot"
                            @if($i > $galleryCount) style="opacity: 0.5; pointer-events: none;" @endif
                        >
                            <div class="gallery-plus-icon" wire:loading.remove wire:target="image">
                                <i class="bi bi-plus-lg"></i>
                            </div>
                            <div class="gallery-plus-icon gallery-loading" wire:loading wire:target="image">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            </div>
                            <input
                                type="file"
                                id="gallery-upload-{{ $business->id }}-{{ $i }}"
                                wire:model="image"
                                accept="image/jpeg,image/jpg,image/png,image/webp"
                                class="gallery-input-hidden"
                                @if($i > $galleryCount) disabled @endif
                            >
                        </label>
                    @endif
                @endfor
            </div>
        </div>
    </div>

    {{-- Error message --}}
    @error('image')
        <div class="gallery-error mt-2">{{ $message }}</div>
    @enderror

    <style>
    .gallery-uploader {
        width: 100%;
    }

    .gallery-thumb-slot {
        position: relative;
        width: 84px;
        height: 65px;
        cursor: pointer;
        overflow: hidden;
        border-radius: 4px;
    }

    .gallery-thumb-img {
        width: 84px;
        height: 65px;
        object-fit: cover;
        display: block;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .gallery-thumb-slot:hover .gallery-thumb-img {
        transform: scale(1.08);
        box-shadow: 0 8px 20px rgba(20,42,59,0.15);
    }

    .gallery-thumb-overlay {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(20,42,59,0.55);
        opacity: 0;
        transition: opacity 0.2s ease;
        border-radius: 4px;
    }

    .gallery-thumb-slot:hover .gallery-thumb-overlay {
        opacity: 1;
    }

    .gallery-thumb-overlay i {
        font-size: 1.2rem;
        color: #fff;
    }

    .gallery-thumb-overlay .spinner-border {
        width: 1rem;
        height: 1rem;
        color: #fff;
    }

    .gallery-loading {
        opacity: 1 !important;
        background: rgba(20,42,59,0.7);
    }

    .gallery-upload-slot {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 84px;
        height: 65px;
        cursor: pointer;
        overflow: hidden;
        border: 2px dashed rgba(20,42,59,0.2);
        border-radius: 4px;
        background: #f8fafc;
        transition: border-color 0.2s ease, background-color 0.2s ease;
    }

    .gallery-upload-slot:hover {
        border-color: var(--light-color, #1DBF73);
        background: rgba(27,169,222,0.05);
    }

    .gallery-plus-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: rgba(20,42,59,0.08);
        transition: background-color 0.2s ease;
    }

    .gallery-upload-slot:hover .gallery-plus-icon {
        background: var(--light-color, #1DBF73);
    }

    .gallery-plus-icon i {
        font-size: 1rem;
        color: rgba(20,42,59,0.5);
        transition: color 0.2s ease;
    }

    .gallery-upload-slot:hover .gallery-plus-icon i {
        color: #fff;
    }

    .gallery-plus-icon .spinner-border {
        width: 1rem;
        height: 1rem;
        color: rgba(20,42,59,0.5);
    }

    .gallery-input-hidden {
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

    .gallery-error {
        font-size: 0.85rem;
        color: #dc3545;
    }

    @media (max-width: 991.98px) {
        .gallery-thumb-slot,
        .gallery-thumb-img,
        .gallery-upload-slot {
            width: 60px;
            height: 46px;
        }

        .gallery-plus-icon {
            width: 24px;
            height: 24px;
        }

        .gallery-plus-icon i {
            font-size: 0.8rem;
        }
    }
    </style>

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('gallery-updated', () => {
                // Refresh the page to show updated gallery
                // This ensures the Alpine state is reset with new images
                window.location.reload();
            });
        });
    </script>
</div>

