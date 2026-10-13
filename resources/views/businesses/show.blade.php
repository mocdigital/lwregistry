@extends('layouts.app')

@section('title', $business->name . ' - Loveworld Business Registry')

@push('styles')
<link href="{{ asset('css/profile.css?v=5.7') }}" rel="stylesheet" />
@endpush

@section('content')
@php
  $categoryModel = is_string($business->category)
    ? \App\Models\Category::where('slug', \Illuminate\Support\Str::slug($business->category))
        ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($business->category)])
        ->first()
    : $business->category;
  $categoryLabel = $categoryModel?->name ?? (is_string($business->category) ? $business->category : null);
  $isLegacyBusiness = $business instanceof \App\Models\Business;
  $viewerOwnsBusiness = auth()->check() && auth()->id() === $business->user_id;
@endphp
<div class="business-profile-remake">
<div class="py-4">
    <div class="container">
      <!-- Profile Hero -->
      <div class="profile-hero mb-4 align-items-stretch">
        @auth
          @if($isLegacyBusiness && $viewerOwnsBusiness)
            @livewire('logo-uploader', ['business' => $business])
          @else
            @if($business->hasMedia('logo'))
              <img src="{{ $business->getFirstMediaUrl('logo') }}" alt="{{ $business->name }}" class="logo">
            @else
              <img src="{{ asset('images/placeholder-logo.svg') }}" alt="{{ $business->name }}" class="logo">
            @endif
          @endif
        @else
          @if($business->hasMedia('logo'))
            <img src="{{ $business->getFirstMediaUrl('logo') }}" alt="{{ $business->name }}" class="logo">
          @else
            <img src="{{ asset('images/placeholder-logo.svg') }}" alt="{{ $business->name }}" class="logo">
          @endif
        @endauth

        <div class="flex-grow-1">
          <div class="d-flex align-items-start gap-3">
            <div class="me-auto">
              <h2>
                {{ $business->name }}
                @if($categoryLabel)
                  @if($categoryModel)
                    <a href="{{ route('businesses.category', $categoryModel) }}" class="badge bg-light text-muted ms-2 hidden-mobile" style="text-decoration:none">
                      {{ $categoryLabel }}
                    </a>
                  @else
                    <span class="badge bg-light text-muted ms-2 hidden-mobile">{{ $categoryLabel }}</span>
                  @endif
                @endif
                @if($business->is_verified)
                <span class="verified-badge" title="Verified SBO">
                  <i class="bi bi-patch-check-fill"></i>
                </span>
                @endif
              </h2>
              <div class="meta-line">
                {{ $business->address }}, {{ $business->city }} -
                <span class="open-badge">{{ $business->is_24_7 ? 'Open 24/7' : 'View Hours' }}</span>
              </div>
            </div>

            <div class="text-end">
              <div class="d-flex gap-2">
                @if($isLegacyBusiness)
                  @livewire('favorite-button', ['businessId' => $business->id])
                @endif
                <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#shareModal"><i class="bi bi-share-fill"></i></button>
                @auth
                  @if($isLegacyBusiness && ! $viewerOwnsBusiness)
                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#reviewModal"><i class="bi bi-star-fill"></i></button>
                  @endif
                  <span class="hidden-desktop">
                    <a href="tel:{{ $business->phone }}" class="btn btn-outline-secondary"><i class="bi bi-phone"></i>  </a>
                    <a href="{{ $business->website }}" class="btn btn-outline-secondary"><i class="bi bi-globe"></i></a>
                    <!--
                    <a href="#" class="btn btn-outline-secondary"><i class="bi bi-geo-alt"></i></a>
                    <a href="#" class="btn btn-outline-secondary"><i class="bi bi-chat-dots"></i></a>
                    -->
                  </span>
                @elseif($isLegacyBusiness)
                  <button class="btn btn-outline-secondary openLogin"><i class="bi bi-star-fill"></i></button>
                   <span class="hidden-desktop">
                    <a href="#" class="btn btn-outline-secondary openLogin"><i class="bi bi-phone"></i>  </a>
                    <a href="#" class="btn btn-outline-secondary openLogin"><i class="bi bi-globe"></i></a>
                    <!--
                    <a href="#" class="btn btn-outline-secondary openLogin"><i class="bi bi-geo-alt"></i></a>
                    <a href="#" class="btn btn-outline-secondary openLogin"><i class="bi bi-chat-dots"></i></a>
                    -->
                  </span>
                @endauth
              </div>
            </div>
          </div>

          <!-- CTA group -->
          <div class="mt-3 d-flex gap-2 flex-wrap cta-group hidden-mobile">
            @auth
              @if($business->phone)
                <a href="tel:{{ $business->phone }}" class="btn btn-outline-primary"><i class="bi bi-telephone"></i> Contact</a>
              @endif
              @if($business->website)
                <a href="{{ $business->website }}" target="_blank" class="btn btn-outline-primary"><i class="bi bi-globe"></i> Website</a>
              @endif
              <!--
              <a href="#" class="btn btn-outline-primary"><i class="bi bi-geo-alt"></i> Directions</a>
              <a href="#" class="btn btn-outline-primary"><i class="bi bi-chat-dots"></i> Message</a>
              -->
             @else
              <a class="btn btn-sm btn-outline-primary openLogin" href="#"><i class="bi bi-telephone"></i> Contact</a>
              <a class="btn btn-sm btn-outline-primary hidden-mobile openLogin" href="#"><i class="bi bi-globe"></i> Website</a>
              <!--
              <a class="btn btn-sm btn-outline-primary hidden-mobile openLogin" href="#"><i class="bi bi-geo-alt"></i> Direction</a>
              <a class="btn btn-sm btn-outline-primary openLogin" href="#"><i class="bi bi-chat-dots"></i> Message</a>
              -->
            @endauth             
          </div>
        </div>
      </div>

      <div class="row gy-4">
        <!-- MAIN CONTENT -->
        <main class="col-lg-8">
          <!-- Gallery + Map Area -->
          <div class="row g-3 align-items-start mb-4">
            <!-- Gallery column -->
            <div class="col-lg-7">
              @auth
                @if($isLegacyBusiness && $viewerOwnsBusiness)
                  @livewire('gallery-uploader', ['business' => $business])
                @else
                  @include('partials.business-gallery', ['business' => $business])
                @endif
              @else
                @include('partials.business-gallery', ['business' => $business])
              @endauth
            </div>

            <!-- Info + Map column -->
            <div class="col-lg-5">
              <div class="card h-100 p-3" style="border-radius:0px;">
                <h5 class="mb-2" style="font-weight:700; color:var(--dark-color)">Location & Map</h5>
                <dl class="row mb-3">
                  <dt class="col-4 text-muted">Location</dt>
                  <dd class="col-8 mb-2">{{ $business->city }}, {{ $business->country }}</dd>
                </dl>

                @php
                  $fullAddress = trim($business->address . ', ' . $business->city);
                @endphp

                <div id="mapWrapper" style="border-radius:0px; overflow:hidden; box-shadow: 0 6px 18px rgba(20,42,59,0.06);">

                  <iframe
                    id="mapIframe"
                    src="https://www.google.com/maps?q={{ urlencode($fullAddress) }}&output=embed"
                    style="border:0; width:100%; height:240px;"
                    allowfullscreen
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade">
                  </iframe>

                  <!-- Fallback UI -->
                  <div id="mapFallback" class="p-4 d-none" style="background:#0f1720; color:#fff;">
                    <h6 style="font-weight:700;">Map unavailable</h6>
                    <p class="small mb-3" style="opacity:.9;">
                      Google Maps couldn't load here. You can still view the location on Google Maps.
                    </p>
                    <div class="d-flex gap-2">
                      <a
                        id="viewMapLink"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-outline-light btn-sm"
                        href="https://www.google.com/maps/search/?api=1&query={{ urlencode($fullAddress) }}">
                        <i class="bi bi-geo-alt"></i> View larger map
                      </a>
                      <a id="reportIssue" href="#" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-exclamation-circle"></i> Report issue
                      </a>
                    </div>
                  </div>

                </div>

                @php
                  $fullAddress = trim($business->address . ', ' . $business->city);
                  $mapLink = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($fullAddress);
                @endphp

                <div class="mt-3 d-flex gap-2">

                  <!-- View larger map -->
                  <a
                    href="{{ $mapLink }}"
                    target="_blank"
                    rel="noopener"
                    class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-geo-alt"></i> View larger map
                  </a>

                  <!-- Share -->
                  <button
                    type="button"
                    class="btn btn-outline-primary btn-sm"
                    onclick="shareLocation('{{ $mapLink }}', '{{ addslashes($fullAddress) }}')">
                    <i class="bi bi-share"></i> Share
                  </button>

                </div>


              </div>
            </div>
          </div>

          <!-- Tabs: Profile / Products / Reviews -->
          <div class="card mb-4" style="border-radius:0px; box-shadow: var(--card-shadow);">
            <div class="card-body">
              <div class="profile-tabs-wrapper">
                <ul class="nav nav-tabs mb-3" id="profileTabs" role="tablist">
                  <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab">Profile</button>
                  </li>
                  <li class="nav-item" role="presentation">
                    <button class="nav-link" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab">Products & Services</button>
                  </li>
                  <li class="nav-item" role="presentation">
                    <button class="nav-link" id="reviews-tab" data-bs-toggle="tab" data-bs-target="#reviews" type="button" role="tab">Reviews</button>
                  </li>
                </ul>
              </div>

              <div class="tab-content">
                <!-- Profile Tab -->
                <section class="tab-pane fade show active" id="profile" role="tabpanel">
                  <h5 class="section-title">Details & Description</h5>
                  <p class="text-muted">{{ $business->description }}</p>

                  <x-ad-slot name="company_below_about" />

                  <div class="row mt-3">
                    <div class="col-md-6">
                      <h6 class="fw-bold">Languages Spoken</h6>
                      <p class="text-muted mb-0">{{ $business->languages ?? 'English' }}</p>
                    </div>
                    <div class="col-md-6">
                      <h6 class="fw-bold">SBO Hours</h6>
                      @if($business->hours->count() > 0)
                        <p class="text-muted mb-0">
                          @php
                            $groupedHours = $business->hours->sortBy('day_of_week')->groupBy(function($hour) {
                              return $hour->is_closed ? 'closed' : $hour->open_time . '-' . $hour->close_time;
                            });
                          @endphp
                          @foreach($groupedHours as $timeSlot => $hours)
                            @if($timeSlot === 'closed')
                              {{ $hours->map(fn($h) => substr($h->getDayName(), 0, 3))->implode(', ') }}: Closed<br>
                            @else
                              {{ $hours->map(fn($h) => substr($h->getDayName(), 0, 3))->implode(', ') }}: {{ $hours->first()->open_time }} - {{ $hours->first()->close_time }}<br>
                            @endif
                          @endforeach
                        </p>
                      @else
                        <p class="text-muted mb-0">{{ $business->is_24_7 ? 'Open 24/7' : 'Contact for hours' }}</p>
                      @endif
                    </div>
                  </div>
                </section>

                <!-- Products Tab -->
                <section class="tab-pane fade" id="products" role="tabpanel">
                  <h5 class="section-title">Products & Services</h5>
                  @if($business->products->count() > 0)
                    <ul>
                      @foreach($business->products as $product)
                        <li>{{ $product->name }}{{ $product->description ? ' - ' . $product->description : '' }}</li>
                      @endforeach
                    </ul>
                  @else
                    <p class="text-muted">No products or services listed yet.</p>
                  @endif
                </section>

                <!-- Reviews Tab -->
                <section class="tab-pane fade" id="reviews" role="tabpanel">
                  <h5 class="section-title d-flex align-items-center justify-content-between">
                    Reviews
                    @auth
                      @if($isLegacyBusiness && ! $viewerOwnsBusiness)
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#reviewModal"><i class="bi bi-pencil-square"></i> Write a review</button>
                      @endif
                    @elseif($isLegacyBusiness)
                      <button class="btn btn-sm btn-primary openLogin">Login to Review an SBO</button>
                    @endauth
                  </h5>

                  <div class="mt-3">
                    @forelse($business->reviews as $review)
                      <!-- Review card -->
                      <div class="mb-3 p-3" style="background:#fafcff; border-radius:0px;">
                        <div class="d-flex align-items-start gap-3">
                          <div style="width:44px;height:44px;border-radius:0px;background:#e9f5ff;display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--light-color)">
                            {{ strtoupper(substr($review->user->name, 0, 1)) }}{{ strtoupper(substr(strstr($review->user->name, ' '), 1, 1) ?: '') }}
                          </div>
                          <div>
                            <div class="d-flex align-items-center gap-2">
                              <strong>{{ Str::limit($review->user->name, 15) }}</strong>
                              <small class="text-muted">• {{ $review->created_at->diffForHumans() }}</small>
                            </div>
                            <div class="text-warning">
                              @for($i = 1; $i <= 5; $i++)
                                {{ $i <= $review->rating ? '★' : '☆' }}
                              @endfor
                            </div>
                            @if($review->comment)
                              <p class="mb-0 text-muted">{{ $review->comment }}</p>
                            @endif
                          </div>
                        </div>
                      </div>
                    @empty
                      <!-- Placeholder if no reviews -->
                      <div class="text-muted">No reviews yet. Be the first to leave a review!</div>
                    @endforelse
                  </div>
                </section>
              </div>
            </div>
          </div>
        </main>

        <!-- Sidebar -->
        <aside class="col-lg-4">
          <x-ad-slot name="company_sidebar" />

          <section class="card" style="border-radius:0px; box-shadow: var(--card-shadow);">
            <div class="card-body">
              <h5 class="mb-3" style="font-weight:700; color:var(--dark-color);">SBO Highlights</h5>

              <dl class="row mb-2" style="font-size:.95rem;">
                @if($business->is_verified)
                  <dt class="col-6 text-muted">Status</dt>
                  <dd class="col-6 mb-2 text-end">
                    <span class="badge bg-success">✓ Verified</span>
                  </dd>
                @endif

                @if($business->staff_size)
                  <dt class="col-6 text-muted">Staff size</dt>
                  <dd class="col-6 mb-2 text-end" style="font-weight:700;">{{ $business->staff_size }}</dd>
                @endif

                <dt class="col-6 text-muted">Avg rating</dt>
                <dd class="col-6 mb-2 text-end">
                  <span aria-hidden="true">
                    @for($i = 1; $i <= 5; $i++)
                      {{ $i <= floor($business->avg_rating) ? '★' : '☆' }}
                    @endfor
                  </span>
                  <small class="text-muted d-block">{{ number_format($business->avg_rating, 1) }} ({{ $business->total_reviews }} {{ Str::plural('review', $business->total_reviews) }})</small>
                </dd>

                @if($business->years_active)
                  <dt class="col-6 text-muted">Years active</dt>
                  <dd class="col-6 mb-2 text-end" style="font-weight:700;">{{ $business->years_active }}+</dd>
                @endif

                @if($business->awards->count() > 0)
                  <dt class="col-6 text-muted">Awards</dt>
                  <dd class="col-6 mb-2 text-end" style="font-weight:700;">{{ $business->awards->count() }}</dd>
                @endif
              </dl>

              <!-- Awards list -->
              @if($business->awards->count() > 0)
              <div class="mb-3">
                <small class="text-muted">Recent awards</small>
                <ul class="list-unstyled mb-0 mt-1" style="line-height:2">
                  @foreach($business->awards->take(3) as $award)
                    <li>🏆 {{ $award->title }} {{ $award->year }}</li>
                  @endforeach
                </ul>
              </div>
              @endif

              <!-- Promo card -->
              @if($business->promotions->count() > 0)
                @php $promo = $business->promotions->first(); @endphp
                <div class="mb-3 p-3" style="background: linear-gradient(90deg, rgba(27,169,222,0.08), rgba(20,42,59,0.02)); border-radius:0px; border:1px solid rgba(27,169,222,0.12);">
                  <div class="d-flex align-items-start gap-2">
                    <div style="flex:1;">
                      <div style="font-weight:700; color:var(--dark-color);">Promo</div>
                      <div class="small text-muted">{{ $promo->title }}</div>
                    </div>
                    <div>
                      <span class="badge" style="background: var(--accent-color); color: #fff; padding:.45rem .6rem; border-radius:0px; font-weight:700;">
                        {{ $promo->discount_percentage }}% OFF
                      </span>
                    </div>
                  </div>
                  <div class="mt-2 d-grid">
                    <a href="#" class="btn btn-sm btn-outline-primary">Redeem Offer</a>
                  </div>
                </div>
              @endif

              <!-- Response time + progress -->
              <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <small class="text-muted">Avg response time</small>
                  <small class="fw-bold text-dark">~ 2 hours</small>
                </div>
                <div class="progress" style="height:8px; border-radius:0px;">
                  <div class="progress-bar" role="progressbar" style="width:65%; background: linear-gradient(90deg,var(--light-color), #fff);" aria-valuenow="65" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
              </div>

              <!-- Views / Saves / Favorites counters -->
              <div class="mb-3 row text-center">
                <div class="col-4">
                  <div class="small text-muted">Views (week)</div>
                  <div style="font-weight:700;">{{ number_format($business->views_count) }}</div>
                </div>
                <div class="col-4">
                  <div class="small text-muted">Saves</div>
                  <div style="font-weight:700;">{{ number_format($business->favorites_count) }}</div>
                </div>
                <div class="col-4">
                  <div class="small text-muted">Favorites</div>
                  <div style="font-weight:700;">{{ number_format($business->favorites_count) }}</div>
                </div>
              </div>

              <!-- Report SBO Button -->
              @if($isLegacyBusiness && auth()->check())
              <div class="d-grid gap-2 mb-2">
                <button class="btn btn-danger d-flex align-items-center justify-content-center" data-bs-toggle="modal" data-bs-target="#reportModal" title="Report business">
                  <i class="bi bi-exclamation-circle me-2" aria-hidden="true"></i>
                  Report SBO
                </button>
              </div>
              @endif

            </div>
          </section>
        </aside>
      </div>
    </div>
  </div>

  <!-- Lightbox modal for photos -->
  <div class="modal fade" id="photoLightbox" tabindex="-1" aria-label="Photo viewer" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down modal-dialog-centered">
      <div class="modal-content bg-transparent border-0">
        <div class="modal-body p-0 d-flex justify-content-center align-items-center" style="min-height:80vh;">
          <button type="button" class="btn btn-dark btn-sm position-absolute top-0 end-0 m-3 z-3" data-bs-dismiss="modal" aria-label="Close">
            <i class="bi bi-x-lg"></i>
          </button>
          <img id="lightboxImage" src="" alt="Enlarged photo" style="max-width:100%; max-height:92vh; border-radius:0px; box-shadow: 0 18px 40px rgba(2,6,23,0.6);">
        </div>
      </div>
    </div>
  </div>

  <!-- Share Modal -->
  <div class="modal fade" id="shareModal" tabindex="-1" aria-labelledby="shareModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="shareModalLabel"><i class="bi bi-share me-2"></i>Share this business</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">Share {{ $business->name }} with friends and family</p>
          <div class="input-group">
            <input type="text" class="form-control" id="shareUrl" value="{{ url()->current() }}" readonly>
            <button class="btn btn-outline-secondary" type="button" id="copyLinkBtn">
              <i class="bi bi-clipboard"></i> Copy
            </button>
          </div>
          <div id="copySuccess" class="alert alert-success mt-2 d-none">Link copied to clipboard!</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Review Modal -->
  <div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="reviewModalLabel"><i class="bi bi-star me-2"></i>Write a review</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="reviewForm">
            @csrf
            <div class="mb-3">
              <label class="form-label">Rating</label>
              <div id="starRating" class="d-flex gap-2 fs-3" style="cursor:pointer;">
                <i class="bi bi-star star-icon" data-rating="1"></i>
                <i class="bi bi-star star-icon" data-rating="2"></i>
                <i class="bi bi-star star-icon" data-rating="3"></i>
                <i class="bi bi-star star-icon" data-rating="4"></i>
                <i class="bi bi-star star-icon" data-rating="5"></i>
              </div>
              <input type="hidden" id="ratingValue" name="rating" value="0">
            </div>
            <div class="mb-3">
              <label for="reviewComment" class="form-label">Your review</label>
              <textarea class="form-control" id="reviewComment" name="comment" rows="4" placeholder="Share your experience with this business..." required></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="submitReviewBtn">Submit Review</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Report Modal -->
  <div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form id="reportForm">
          @csrf
          <div class="modal-header">
            <h6 class="modal-title" id="reportModalLabel">Report this business</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body">
            <div class="mb-2 text-muted small">Please tell us why you are reporting this listing. Our moderation team will review and respond.</div>

            <div class="mb-3">
              <label class="form-label">Reason</label>
              <select id="reportReason" name="reason" class="form-select" required>
                <option value="">Choose a reason</option>
                <option value="inaccurate">Inaccurate information</option>
                <option value="spam">Spam or scam</option>
                <option value="inappropriate">Inappropriate content</option>
                <option value="other">Other</option>
              </select>
            </div>

            <div class="mb-3 d-none" id="reportDetailsWrapper">
              <label class="form-label">Details (optional)</label>
              <textarea id="reportDetails" name="details" rows="3" class="form-control" placeholder="Additional details..."></textarea>
            </div>

            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="reportContact" name="contact" value="1" />
              <label class="form-check-label small" for="reportContact">I want to be contacted about this report</label>
            </div>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-danger" type="submit">Submit report</button>
          </div>
        </form>
      </div>
  </div>
</div>
</div>
@endsection

@push('styles')
<style>
  .thumb-btn:hover .thumb-small {
    transform: scale(1.08);
    box-shadow: 0 8px 20px rgba(20,42,59,0.15);
  }

  .thumb-btn:focus {
    outline: 2px solid var(--light-color);
    outline-offset: 2px;
  }

  #photoLightbox .modal-content {
    background: rgba(0,0,0,0.92) !important;
  }

  #starRating .star-icon {
    color: #d1d5db;
    transition: color 0.15s ease;
  }

  #starRating .star-icon:hover,
  #starRating .star-icon.active {
    color: #fbbf24;
  }
</style>
@endpush

@push('scripts')
<script>
  // Gallery thumbnail switcher
  document.querySelectorAll('.thumb-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const newSrc = this.getAttribute('data-src');
      const heroImage = document.getElementById('profile-heroImage');
      const lightboxImage = document.getElementById('lightboxImage');

      heroImage.src = newSrc;
      lightboxImage.src = newSrc;
    });
  });

  // Lightbox: set image when main image is clicked
  document.getElementById('profile-heroImage').addEventListener('click', function() {
    document.getElementById('lightboxImage').src = this.src;
  });

  // Share: Copy link functionality
  document.getElementById('copyLinkBtn').addEventListener('click', function() {
    const shareUrlInput = document.getElementById('shareUrl');
    shareUrlInput.select();
    shareUrlInput.setSelectionRange(0, 99999); // For mobile devices

    navigator.clipboard.writeText(shareUrlInput.value).then(() => {
      const successAlert = document.getElementById('copySuccess');
      successAlert.classList.remove('d-none');
      setTimeout(() => successAlert.classList.add('d-none'), 3000);
    });
  });

  // Review: Star rating functionality
  const starIcons = document.querySelectorAll('.star-icon');
  const ratingInput = document.getElementById('ratingValue');

  starIcons.forEach(star => {
    star.addEventListener('click', function() {
      const rating = parseInt(this.getAttribute('data-rating'));
      ratingInput.value = rating;

      // Update star display
      starIcons.forEach((s, index) => {
        if (index < rating) {
          s.classList.remove('bi-star');
          s.classList.add('bi-star-fill', 'active');
        } else {
          s.classList.remove('bi-star-fill', 'active');
          s.classList.add('bi-star');
        }
      });
    });
  });

  // Review: Submit review
  document.getElementById('submitReviewBtn').addEventListener('click', function() {
    const rating = document.getElementById('ratingValue').value;
    const comment = document.getElementById('reviewComment').value;

    if (rating == 0) {
      alert('Please select a rating');
      return;
    }

    // Submit the review via AJAX
    fetch('{{ route("businesses.reviews.store", $business->slug) }}', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value,
        'Accept': 'application/json'
      },
      body: JSON.stringify({ rating, comment })
    })
    .then(response => {
      if (!response.ok) {
        return response.json().then(err => { throw err; });
      }
      return response.json();
    })
    .then(data => {
      const modal = bootstrap.Modal.getInstance(document.getElementById('reviewModal'));
      modal.hide();
      alert(data.message);
      location.reload();
    })
    .catch(error => {
      console.error('Error:', error);
      if (error.errors) {
        const messages = Object.values(error.errors).flat().join('\n');
        alert(messages);
      } else {
        alert('Failed to submit review. Please try again.');
      }
    });
  });

  // Report: Show details field based on reason
  document.getElementById('reportReason').addEventListener('change', function() {
    const detailsWrapper = document.getElementById('reportDetailsWrapper');
    if (this.value && this.value !== '') {
      detailsWrapper.classList.remove('d-none');
    } else {
      detailsWrapper.classList.add('d-none');
    }
  });

  // Report: Submit report
  document.getElementById('reportForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const reason = document.getElementById('reportReason').value;
    const details = document.getElementById('reportDetails').value;
    const contact = document.getElementById('reportContact').checked;

    if (!reason) {
      alert('Please select a reason');
      return;
    }

    // Submit the report via AJAX
    fetch('{{ route("businesses.reports.store", $business->slug) }}', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value,
        'Accept': 'application/json'
      },
      body: JSON.stringify({ reason, details, contact })
    })
    .then(response => response.json().then(data => {
      if (!response.ok) {
        throw data;
      }

      return data;
    }))
    .then(data => {
      this.reset();
      const modal = bootstrap.Modal.getInstance(document.getElementById('reportModal'));
      modal.hide();
      alert('Report submitted successfully. Our team will review it shortly.');
    })
    .catch(error => {
      console.error('Error:', error);
      if (error.errors) {
        const messages = Object.values(error.errors).flat().join('\n');
        alert(messages);
      } else {
        alert(error.message || 'Failed to submit report. Please try again.');
      }
    });
  });
</script>
@endpush
