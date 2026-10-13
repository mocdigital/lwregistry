<!-- Full Loveworld Footer -->
<footer class="footer-full pt-5 pb-4">
  <div class="container">
    @php
      $appliedSboCount = \Illuminate\Support\Facades\Cache::remember(
          'footer_sbo_applied_count',
          now()->addMinutes(15),
          // The registry's own listing is not an applicant; counting it would
          // overstate the figure by one forever.
          fn (): int => \App\Models\Sbo::query()->where('is_registry', false)->count()
      );
    @endphp

    @guest
      <div class="footer-sbo-callout mb-4">
        <div class="footer-sbo-callout__content">
          <div class="footer-sbo-callout__text">
            <div class="footer-sbo-callout__eyebrow">Loveworld Registry momentum</div>
            <div class="footer-sbo-callout__headline">
              <span>{{ number_format((int) $appliedSboCount) }}</span> Strategic Business Organizations have applied
            </div>
            <p class="footer-sbo-callout__copy mb-0">
              Join SBOs using the registry to build visibility, complete verification, and access ministry commerce opportunities.
            </p>
          </div>

          <div class="footer-sbo-callout__actions">
            <a href="{{ route('join') }}" class="btn btn-primary" wire:navigate>
              Register your SBO
            </a>
            <a href="{{ route('businesses.index') }}" class="btn btn-outline-primary" wire:navigate>
              Browse SBOs
            </a>
          </div>
        </div>
      </div>
      <div class="row mb-4">
        <div class="col-12">
          <h6 class="fw-bold text-dark mb-3">Popular SBO Categories</h6>
        </div>

        @php
          $categoriesChunked = collect($allCategories ?? [])->chunk(8);
        @endphp

        @foreach($categoriesChunked as $chunk)
        <div class="col-md-3 col-6">
          <ul class="list-unstyled">
            @foreach($chunk as $category)
            <li><a href="{{ route('businesses.category', $category) }}" wire:navigate>{{ $category->name }}</a></li>
            @endforeach
          </ul>
        </div>
        @endforeach
      </div>

      <hr class="my-2" />
    @endguest

    <!-- Mid Footer -->
    <div class="row align-items-center mb-3">
      <div class="col-md-6">
        <img src="{{ asset('images/logo-colored.png') }}" alt="Loveworld Registry Logo">
      </div>
    </div>

    @guest
      <hr class="my-2" />

      <!-- Link Sections -->
      <div class="row mb-4">
        <div class="col-md-2 col-sm-6 mb-3">
          <h6 class="fw-bold text-dark mb-3">Popular Local Searches:</h6>
          <p class="small mb-1">Churches, Ministries, Schools</p>
          <p class="small mb-1">Restaurants, Cafés, Events</p>
          <p class="small mb-1">Clinics, Pharmacies, Dentists</p>
        </div>

        <div class="col-md-2 col-sm-6 mb-3">
          <h6 class="fw-bold text-dark mb-3">Advertisers</h6>
          <ul class="list-unstyled small">
            <li><a href="{{ route('advertise') }}">Advertise with Us</a></li>
          </ul>
        </div>

        <div class="col-md-2 col-sm-6 mb-3">
          <h6 class="fw-bold text-dark mb-3">About Us</h6>
          <ul class="list-unstyled small">
            <li><a href="{{ route('about') }}">About Loveworld Registry</a></li>
            <li><a href="{{ route('about') }}">Our Mission</a></li>
          </ul>
        </div>

        <div class="col-md-2 col-sm-6 mb-3">
          <h6 class="fw-bold text-dark mb-3">Quick Links</h6>
          <ul class="list-unstyled small">
            <li><a href="#">Browse by Category</a></li>
            <li><a href="#">Browse by Location</a></li>
            <li><a href="{{ route('feedback') }}">Give Feedback</a></li>
          </ul>
        </div>

        <div class="col-md-2 col-sm-6 mb-3">
          <h6 class="fw-bold text-dark mb-3">Mobile & Tools</h6>
          <ul class="list-unstyled small">
            <li><a href="#">Follow us on Facebook</a></li>
            <li><a href="#">Follow us on Instagram</a></li>
            <li><a href="#">Follow us on X</a></li>
          </ul>
        </div>

        <div class="col-md-2 col-sm-6 mb-3">
          <h6 class="fw-bold text-dark mb-3">Partners</h6>
          <ul class="list-unstyled small">
            <li><a href="#">Loveworld Ministry Portal</a></li>
            <li><a href="#">KingsChat</a></li>
            <li><a href="#">Loveworld SAT</a></li>
          </ul>
        </div>
      </div>

      <hr class="my-4" />
    @endguest

    <!-- Copyright -->
    <div class="text-center small text-muted">
       Copyright © 2025 Loveworld Business Registry. All Rights Reserved.
    </div>
  </div>
</footer>

<style>
  .footer-sbo-callout {
    background: linear-gradient(135deg, #f6fbff 0%, #f2fbf6 100%);
    border: 1px solid rgba(13, 110, 253, 0.16);
    border-radius: 8px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
    padding: 1.25rem;
  }

  .footer-sbo-callout__content {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
  }

  .footer-sbo-callout__text {
    max-width: 720px;
  }

  .footer-sbo-callout__eyebrow {
    color: #0f6fb2;
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0;
    margin-bottom: 0.35rem;
    text-transform: uppercase;
  }

  .footer-sbo-callout__headline {
    color: #172033;
    font-size: 1.25rem;
    font-weight: 700;
    line-height: 1.3;
  }

  .footer-sbo-callout__headline span {
    color: #0f6fb2;
  }

  .footer-sbo-callout__copy {
    color: #596579;
    font-size: 0.95rem;
    margin-top: 0.35rem;
  }

  .footer-sbo-callout__actions {
    display: flex;
    flex: 0 0 auto;
    flex-wrap: wrap;
    gap: 0.75rem;
    justify-content: flex-end;
  }

  .footer-sbo-callout__actions .btn {
    border-radius: 8px;
    font-weight: 700;
    min-height: 42px;
    padding-left: 1rem;
    padding-right: 1rem;
  }

  @media (max-width: 767.98px) {
    .footer-sbo-callout {
      padding: 1rem;
    }

    .footer-sbo-callout__content {
      align-items: stretch;
      flex-direction: column;
    }

    .footer-sbo-callout__headline {
      font-size: 1.05rem;
    }

    .footer-sbo-callout__actions {
      flex-direction: column;
      justify-content: stretch;
    }

    .footer-sbo-callout__actions .btn {
      width: 100%;
    }
  }
</style>
