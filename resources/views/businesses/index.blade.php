@extends('layouts.app')

@section('title', ($selectedCategory ? $selectedCategory->name . ' Businesses' : 'Browse SBOs') . ' - Loveworld Business Registry')

@push('styles')
<link href="{{ asset('css/lists.css?v=10') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="directory-remake">
@if($paymentAccessRequired ?? false)
<div class="py-4">
  <div class="container">
    <section class="directory-payment-gate">
      <div class="directory-payment-gate__icon" aria-hidden="true">
        <i class="bi bi-shield-lock"></i>
      </div>
      <div>
        <span class="directory-payment-gate__eyebrow">Access restricted</span>
        <h1>Pay to get access</h1>
        <p>
          Your SBO payment must be approved before you can browse the SBO directory and access related business opportunities.
        </p>
        <a href="{{ $paymentProgressUrl }}" class="btn btn-primary rounded-pill" wire:navigate>
          <i class="bi bi-credit-card me-1"></i> Pay to get access
        </a>
      </div>
    </section>
  </div>
</div>

<div class="modal fade" id="directoryPaymentGateModal" tabindex="-1" aria-labelledby="directoryPaymentGateModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0">
        <h2 class="modal-title h5" id="directoryPaymentGateModalLabel">
          <i class="bi bi-exclamation-triangle text-warning me-2"></i> Payment approval required
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-0">
        <p class="mb-0">
          Pay to get access. Once your SBO registration payment is approved, this page will unlock automatically.
        </p>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Close</button>
        <a href="{{ $paymentProgressUrl }}" class="btn btn-primary rounded-pill" wire:navigate>Pay to get access</a>
      </div>
    </div>
  </div>
</div>
@else
<div class="py-4">
    <div class="container">
      <div class="row gy-4">
        <!-- Listings -->
        <div class="col-lg-8">
          <x-ui.page-header
            title="Browse SBOs"
            eyebrow="Public directory"
            :description="$selectedCategory ? 'Explore businesses in ' . $selectedCategory->name . '.' : 'Find trusted businesses and services across the Loveworld economy.'"
          >
            <x-slot:meta>
              <span><strong>{{ $businesses->total() }}</strong> results</span>
              @if(request('location'))
                <span>in {{ request('location') }}</span>
              @endif
            </x-slot:meta>
            <x-slot:actions>
              <div class="dropdown">
                <button class="btn btn-outline-secondary rounded-pill dropdown-toggle" id="sortDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                  Relevance
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="sortDropdown">
                  <li><a class="dropdown-item" href="#">Relevance</a></li>
                  <li><a class="dropdown-item" href="#">Rating</a></li>
                  <li><a class="dropdown-item" href="#">Newest</a></li>
                </ul>
              </div>
            </x-slot:actions>
          </x-ui.page-header>

          <!-- Filters Row -->
          <div class="filter-row mb-4">
            <div class="row g-2 align-items-center">
              <div class="col-lg-4 col-md-6">
                <label class="form-label small text-muted mb-1">Categories</label>
                <select id="filterCategory" class="form-select" onchange="window.location.href = this.value">
                  <option value="{{ route('businesses.index') }}" {{ !$selectedCategory ? 'selected' : '' }}>All categories</option>
                  @foreach($categories as $cat)
                    <option value="{{ route('businesses.category', $cat) }}" {{ $selectedCategory && $selectedCategory->id === $cat->id ? 'selected' : '' }}>
                      {{ $cat->name }}
                    </option>
                  @endforeach
                </select>
              </div>

              <div class="col-lg-3 col-md-6">
                <label class="form-label small text-muted mb-1">Location</label>
                @php
                    $locationBaseRoute = $selectedCategory
                        ? route('businesses.category', $selectedCategory)
                        : route('businesses.index');
                    $locationPreservedParams = array_filter(request()->only(['status', 'open24', 'search']));
                @endphp
                <select class="form-select" onchange="window.location.href = this.value">
                  <option value="{{ $locationBaseRoute }}?{{ http_build_query($locationPreservedParams) }}" {{ !request('location') ? 'selected' : '' }}>All locations</option>
                  @foreach($cities as $city)
                    <option value="{{ $locationBaseRoute }}?{{ http_build_query(array_merge($locationPreservedParams, ['location' => $city])) }}" {{ request('location') === $city ? 'selected' : '' }}>
                      {{ $city }}
                    </option>
                  @endforeach
                </select>
              </div>

              <div class="col-lg-3 col-md-6">
                <label class="form-label small text-muted mb-1 d-block">Quick filters</label>
                @php
                    $baseRoute = $selectedCategory
                        ? route('businesses.category', $selectedCategory)
                        : route('businesses.index');
                    $currentStatus = request('status', 'all');
                    $currentOpen24 = request()->boolean('open24');
                    $preservedParams = array_filter(request()->only(['location', 'search']));
                @endphp
                <div class="d-flex gap-2">
                  <!-- 24/7 toggle -->
                  <a href="{{ $baseRoute }}?{{ http_build_query(array_filter(array_merge($preservedParams, ['status' => $currentStatus !== 'all' ? $currentStatus : null, 'open24' => !$currentOpen24 ? '1' : null]))) }}"
                     wire:navigate class="btn btn-tab {{ $currentOpen24 ? 'active' : '' }}">24/7</a>
                  <!-- Status group: verified/unverified -->
                  <div class="btn-group" role="group">
                    <a href="{{ $baseRoute }}?{{ http_build_query(array_filter(array_merge($preservedParams, ['open24' => $currentOpen24 ? '1' : null]))) }}"
                       wire:navigate class="btn btn-tab {{ $currentStatus === 'all' ? 'active' : '' }}">All</a>
                    <a href="{{ $baseRoute }}?{{ http_build_query(array_filter(array_merge($preservedParams, ['status' => 'verified', 'open24' => $currentOpen24 ? '1' : null]))) }}"
                       wire:navigate class="btn btn-tab {{ $currentStatus === 'verified' ? 'active' : '' }}">Verified</a>
                    <a href="{{ $baseRoute }}?{{ http_build_query(array_filter(array_merge($preservedParams, ['status' => 'unverified', 'open24' => $currentOpen24 ? '1' : null]))) }}"
                       wire:navigate class="btn btn-tab {{ $currentStatus === 'unverified' ? 'active' : '' }}">Unverified</a>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div id="listings" class="d-flex flex-column gap-3">
            @forelse($businesses as $business)
            <!-- Listing Card -->
            <article class="listing-card" data-verified="{{ $business->is_verified ? 'yes' : 'no' }}" data-open24="{{ $business->is_24_7 ? 'yes' : 'no' }}">
              @if($business->hasMedia('logo'))
                <img class="thumb" src="{{ $business->getFirstMediaUrl('logo', 'thumb') }}" alt="{{ $business->name }}">
              @else
                <img class="thumb" src="{{ asset('images/placeholder-logo.svg') }}" alt="{{ $business->name }}">
              @endif

              <div>
                <div class="d-flex align-items-center gap-2">
                  <a href="{{ route('businesses.show', $business->slug) }}" class="listing-title" wire:navigate>{{ $business->name }}</a>

                  @if($business->is_verified)
                    <span class="verified-badge" title="Verified SBO">
                      <i class="bi bi-patch-check-fill"></i>
                    </span>
                  @else
                    <span class="unverified-badge" title="SBO not verified">
                      <i class="bi bi-info-circle"></i>
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
                  @auth
                    @if($business->phone)
                      <a class="btn btn-sm btn-outline-primary" href="tel:{{ $business->phone }}"><i class="bi bi-telephone"></i> Contact</a>
                    @endif
                    @if($business->website)
                      <a class="btn btn-sm btn-outline-primary hidden-mobile" href="{{ $business->website }}" target="_blank"><i class="bi bi-globe"></i> Website</a>
                    @endif
                    <a class="btn btn-sm btn-outline-primary hidden-mobile" href="https://www.google.com/maps/dir/?api=1&destination={{ urlencode($business->address . ', ' . $business->city) }}" target="_blank"><i class="bi bi-geo-alt"></i> Direction</a>
                    @if($business->email)
                      <a class="btn btn-sm btn-outline-primary" href="mailto:{{ $business->email }}"><i class="bi bi-chat-dots"></i> Message</a>
                    @endif
                  @else
                    <a class="btn btn-sm btn-outline-primary openLogin" href="#"><i class="bi bi-telephone"></i> Contact</a>
                    <a class="btn btn-sm btn-outline-primary hidden-mobile openLogin" href="#"><i class="bi bi-globe"></i> Website</a>
                    <a class="btn btn-sm btn-outline-primary hidden-mobile openLogin" href="#"><i class="bi bi-geo-alt"></i> Direction</a>
                    <a class="btn btn-sm btn-outline-primary openLogin" href="#"><i class="bi bi-chat-dots"></i> Message</a>
                  @endauth
                </div>
              </div>

              <div class="text-end rating hidden-mobile">
                <div class="mb-2">
                  <span class="text-warning">
                    @for($i = 1; $i <= 5; $i++)
                      {{ $i <= round($business->avg_rating) ? '★' : '☆' }}
                    @endfor
                  </span>
                  <small class="text-muted d-block">{{ number_format($business->avg_rating, 1) }} ({{ $business->total_reviews }} {{ Str::plural('review', $business->total_reviews) }})</small>
                </div>
                @if($business->is_24_7)
                  <div class="open-badge">Open 24/7</div>
                @else
                  <div class="open-badge">Open now</div>
                @endif
              </div>
            </article>
            @empty
            <div class="text-center py-5">
              <i class="bi bi-search display-1 text-muted"></i>
              <p class="mt-3 text-muted">No SBOs found matching your criteria.</p>
              <a href="{{ route('businesses.index') }}" class="btn btn-primary">View All SBOs</a>
            </div>
            @endforelse
          </div>

          @if($businesses->total() > 0)
            @php
              $currentPage = $businesses->currentPage();
              $lastPage = $businesses->lastPage();
              $paginationPages = collect([
                  1,
                  $currentPage - 1,
                  $currentPage,
                  $currentPage + 1,
                  $lastPage,
              ])->filter(fn ($page) => $page >= 1 && $page <= $lastPage)->unique()->sort()->values();
            @endphp

            <nav class="directory-pagination" aria-label="Directory pagination">
              <p class="directory-pagination__summary">
                Showing <strong>{{ $businesses->firstItem() }}</strong>–<strong>{{ $businesses->lastItem() }}</strong>
                of <strong>{{ $businesses->total() }}</strong> businesses
              </p>

              @if($businesses->hasPages())
                <div class="directory-pagination__controls">
                  @if($businesses->onFirstPage())
                    <span class="directory-pagination__link directory-pagination__link--direction is-disabled" aria-disabled="true">
                      <i class="bi bi-arrow-left" aria-hidden="true"></i>
                      <span class="directory-pagination__link-label">Previous</span>
                    </span>
                  @else
                    <a href="{{ $businesses->previousPageUrl() }}" rel="prev" wire:navigate class="directory-pagination__link directory-pagination__link--direction" aria-label="Go to previous page">
                      <i class="bi bi-arrow-left" aria-hidden="true"></i>
                      <span class="directory-pagination__link-label">Previous</span>
                    </a>
                  @endif

                  <div class="directory-pagination__pages" aria-label="Page list">
                    @php $previousPage = null; @endphp
                    @foreach($paginationPages as $page)
                      @if($previousPage !== null && $page > $previousPage + 1)
                        <span class="directory-pagination__ellipsis" aria-hidden="true">…</span>
                      @endif

                      @if($page === $currentPage)
                        <span class="directory-pagination__link is-current" aria-current="page">{{ $page }}</span>
                      @else
                        <a href="{{ $businesses->url($page) }}" wire:navigate class="directory-pagination__link" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                      @endif

                      @php $previousPage = $page; @endphp
                    @endforeach
                  </div>

                  @if($businesses->hasMorePages())
                    <a href="{{ $businesses->nextPageUrl() }}" rel="next" wire:navigate class="directory-pagination__link directory-pagination__link--direction" aria-label="Go to next page">
                      <span class="directory-pagination__link-label">Next</span>
                      <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                  @else
                    <span class="directory-pagination__link directory-pagination__link--direction is-disabled" aria-disabled="true">
                      <span class="directory-pagination__link-label">Next</span>
                      <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </span>
                  @endif
                </div>
              @endif
            </nav>
          @endif
        </div>

        <!-- Sponsored Ads Sidebar -->
        <div id="sponsored-ads" class="col-md-4">
          <aside class="sponsored-wrap sticky-top">
            <div class="sponsored-header d-flex align-items-center justify-content-between">
              <h3 class="mb-0">Sponsored</h3>
              <span class="sponsored-pill">Ads</span>
            </div>

            <div class="sponsored-list mt-3">

              <x-ad-slot name="directory_sidebar" />

              <!-- Footer -->
              <div class="sponsored-footer small text-muted mt-2">
                <p class="mb-0">Advertising helps grow the Registry. <a href="{{ route('advertise') }}">Advertise with us</a></p>
              </div>
            </div>
          </aside>
        </div>
      </div>
    </div>
  </div>
@endif
</div>
@endsection

@push('styles')
<style>
  .directory-payment-gate {
    min-height: calc(100vh - 210px);
    display: grid;
    grid-template-columns: auto minmax(0, 620px);
    align-items: center;
    justify-content: center;
    gap: 2rem;
    padding: 3rem 1.5rem;
    text-align: left;
  }

  .directory-payment-gate__icon {
    width: 104px;
    height: 104px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    color: #fff;
    background: var(--light-color);
    box-shadow: 0 0 0 0 rgba(29, 191, 115, 0.35);
    animation: directoryGatePulse 1.8s ease-in-out infinite;
  }

  .directory-payment-gate__icon i {
    font-size: 2.8rem;
  }

  .directory-payment-gate__eyebrow {
    display: inline-block;
    color: var(--text-blue);
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0;
    margin-bottom: 0.5rem;
  }

  .directory-payment-gate h1 {
    color: var(--dark-color);
    font-size: clamp(2rem, 4vw, 3.25rem);
    line-height: 1.05;
    margin-bottom: 1rem;
  }

  .directory-payment-gate p {
    color: #52616b;
    font-size: 1.05rem;
    margin-bottom: 1.35rem;
  }

  @keyframes directoryGatePulse {
    0% { box-shadow: 0 0 0 0 rgba(29, 191, 115, 0.35); transform: scale(1); }
    70% { box-shadow: 0 0 0 18px rgba(29, 191, 115, 0); transform: scale(1.03); }
    100% { box-shadow: 0 0 0 0 rgba(29, 191, 115, 0); transform: scale(1); }
  }

  @media (max-width: 767.98px) {
    .directory-payment-gate {
      grid-template-columns: 1fr;
      justify-items: start;
      gap: 1.25rem;
      padding-inline: 0;
    }

    .directory-payment-gate__icon {
      width: 84px;
      height: 84px;
    }
  }
</style>
@endpush

@push('scripts')
<script>
  @if($paymentAccessRequired ?? false)
  function showDirectoryPaymentGateModal() {
    const modalEl = document.getElementById('directoryPaymentGateModal');
    if (modalEl && window.bootstrap) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
  }

  document.addEventListener('DOMContentLoaded', showDirectoryPaymentGateModal);
  document.addEventListener('livewire:navigated', showDirectoryPaymentGateModal);
  @endif

  // Ensure navbar is scrolled state on listings page
  window.addEventListener('DOMContentLoaded', function() {
    const navbarSearch = document.getElementById('navbarSearch');
    if (navbarSearch) {
      navbarSearch.classList.add('show');
    }

    const navbar = document.querySelector(".navbar");
    if (navbar) {
      navbar.classList.add("scrolled");
    }
  });
</script>
@endpush
