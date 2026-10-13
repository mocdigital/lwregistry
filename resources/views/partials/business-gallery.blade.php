<div class="card gallery-card p-0 overflow-hidden">
    @php
        $hasGalleryImages = $business->hasMedia('gallery');
        $galleryImages = $hasGalleryImages
            ? $business->getMedia('gallery')->take(5)->map(fn($media) => $media->getUrl())->toArray()
            : [];
    @endphp

    @if($hasGalleryImages)
        <!-- Main profile hero image -->
        <img id="profile-heroImage" src="{{ $galleryImages[0] }}" alt="{{ $business->name }}"
             class="w-100" style="height:320px; object-fit:cover; display:block; cursor:pointer;"
             data-bs-toggle="modal" data-bs-target="#photoLightbox">

        <!-- Thumbnails row -->
        <div class="d-flex gap-2 p-3 align-items-center">
            <div class="d-flex flex-row gap-2 flex-wrap" style="flex:1;">
                @foreach($galleryImages as $index => $imageUrl)
                    <button class="thumb-btn p-0 border-0 bg-transparent" data-src="{{ $imageUrl }}" aria-label="Photo {{ $index + 1 }}">
                        <img class="thumb-small rounded" src="{{ $imageUrl }}" alt="thumb {{ $index + 1 }}"
                             loading="lazy" decoding="async"
                             style="width:84px; height:65px; object-fit:cover; cursor:pointer; transition: transform 0.15s ease, box-shadow 0.15s ease;">
                    </button>
                @endforeach
            </div>
        </div>
    @else
        <!-- No images placeholder -->
        <div class="gallery-placeholder-main" style="height:320px; background:#f8fafc; display:flex; align-items:center; justify-content:center;">
            <span class="text-muted">No image available</span>
        </div>
    @endif
</div>


