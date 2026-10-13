<div class="py-4">
    <div class="container">
        <div class="row gy-4">
            <!-- Listings -->
            <div class="col-lg-8">
                <!-- Header -->
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <h1 class="page-title mb-0">SBOs <small class="text-muted">({{ $businesses->total() }} results)</small></h1>
                    </div>

                    <div class="hidden-mobile">
                        <select wire:model.live="sortBy" class="form-select form-select-sm">
                            <option value="relevance">Relevance</option>
                            <option value="rating">Rating</option>
                            <option value="newest">Newest</option>
                        </select>
                    </div>
                </div>

                <!-- Filters Row -->
                <div class="filter-row mb-4">
                    <div class="row g-2 align-items-center">
                        <div class="col-lg-4 col-md-6">
                            <label class="form-label small text-muted mb-1">Categories</label>
                            <select wire:model.live="category" class="form-select">
                                <option value="all">All categories</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->slug }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-lg-3 col-md-6">
                            <label class="form-label small text-muted mb-1">Location</label>
                            <select wire:model.live="location" class="form-select">
                                <option value="all">All locations</option>
                                @foreach($cities as $city)
                                    <option value="{{ $city }}">{{ $city }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-lg-3 col-md-6">
                            <label class="form-label small text-muted mb-1 d-block">Quick filters</label>
                            <div class="d-flex gap-2">
                                <button type="button" wire:click="$toggle('open24')" class="btn btn-tab {{ $open24 ? 'active' : '' }}">24/7</button>
                                <div class="btn-group" role="group">
                                    <button type="button" wire:click="$set('status', 'all')" class="btn btn-tab {{ $status === 'all' ? 'active' : '' }}">All</button>
                                    <button type="button" wire:click="$set('status', 'verified')" class="btn btn-tab {{ $status === 'verified' ? 'active' : '' }}">Verified</button>
                                    <button type="button" wire:click="$set('status', 'unverified')" class="btn btn-tab {{ $status === 'unverified' ? 'active' : '' }}">Unverified</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loading indicator -->
                <div wire:loading class="text-center py-3">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <!-- Listings -->
                <div id="listings" class="d-flex flex-column gap-3" wire:loading.remove>
                    @forelse($businesses as $business)
                        <article class="listing-card">
                            <img class="thumb" src="{{ $business->getFirstMediaUrl('logo') ?: asset('images/placeholder-logo.svg') }}" alt="{{ $business->name }}">

                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <a href="{{ route('businesses.show', $business->slug) }}" class="listing-title">{{ $business->name }}</a>

                                    @if($business->is_verified)
                                        <span class="verified-badge" title="Verified SBO">
                                            <i class="bi bi-patch-check-fill"></i>
                                        </span>
                                    @endif
                                </div>

                                <div class="listing-meta">
                                    @php
                                        $addressWordCount = str_word_count(strip_tags($business->address ?? ''));
                                        $descriptionWordCount = str_word_count(strip_tags($business->description ?? ''));
                                    @endphp
                                    {{ $addressWordCount > 40 ? Str::words($business->address, 40, '...') : $business->address }}@if($business->city), {{ $business->city }}@endif<br>
                                    {{ $descriptionWordCount > 40 ? Str::words($business->description, 40, '...') : $business->description }}
                                </div>

                                <div class="listing-actions d-flex gap-2">
                                    <a class="btn btn-sm btn-outline-primary" href="tel:{{ $business->phone }}"><i class="bi bi-telephone"></i> Contact</a>
                                    @if($business->website)
                                        <a class="btn btn-sm btn-outline-primary hidden-mobile" href="{{ $business->website }}" target="_blank"><i class="bi bi-globe"></i> Website</a>
                                    @endif
                                    <a class="btn btn-sm btn-outline-primary hidden-mobile" href="https://www.google.com/maps/dir/?api=1&destination={{ urlencode($business->address . ', ' . $business->city) }}" target="_blank"><i class="bi bi-geo-alt"></i> Direction</a>
                                    <a class="btn btn-sm btn-outline-primary" href="mailto:{{ $business->email }}"><i class="bi bi-chat-dots"></i> Message</a>
                                </div>
                            </div>

                            <div class="text-end rating hidden-mobile">
                                <div class="mb-2">
                                    @if($business->avg_rating)
                                        <span class="text-warning">
                                            @for($i = 1; $i <= 5; $i++)
                                                @if($i <= $business->avg_rating)
                                                    ★
                                                @else
                                                    ☆
                                                @endif
                                            @endfor
                                        </span>
                                    @else
                                        <span class="text-muted">No ratings yet</span>
                                    @endif
                                </div>
                                <div class="open-badge">{{ $business->is_24_7 ? 'Open 24/7' : 'Open now' }}</div>
                            </div>
                        </article>
                    @empty
                        <div class="text-center py-5">
                            <i class="bi bi-search display-1 text-muted"></i>
                            <p class="mt-3">No SBOs found matching your criteria.</p>
                            <button type="button" wire:click="$set('category', 'all'); $set('location', 'all'); $set('status', 'all'); $set('open24', false); $set('search', '')" class="btn btn-primary">Clear Filters</button>
                        </div>
                    @endforelse

                    <div class="mt-4">
                        {{ $businesses->links() }}
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <aside class="col-lg-4">
                <div class="sidebar-widget p-4 mb-4 shadow-sm">
                    <h5 class="fw-bold mb-3">Sponsored Ads</h5>
                    <div class="d-flex flex-column gap-3">
                        <!-- Static Ad 1 -->
                        <div class="ad-card">
                            <img src="{{ asset('images/ad-placeholder-1.jpg') }}" alt="Sponsored Ad" class="img-fluid mb-2">
                            <h6>Grow Your SBO with Us</h6>
                            <p class="small text-muted">Reach thousands of potential customers in the Loveworld community.</p>
                            <a href="#" class="btn btn-sm btn-primary">Learn More</a>
                        </div>
                        <!-- Static Ad 2 -->
                        <div class="ad-card">
                            <img src="{{ asset('images/ad-placeholder-2.jpg') }}" alt="Sponsored Ad" class="img-fluid mb-2">
                            <h6>Promote Your Events</h6>
                            <p class="small text-muted">Showcase your events to a targeted audience.</p>
                            <a href="#" class="btn btn-sm btn-primary">Get Started</a>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</div>
