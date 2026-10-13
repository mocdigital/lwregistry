<?php

use App\Models\Business;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Business Details – Admin Dashboard',
        'dataPage' => 'businesses',
        'pageTitle' => 'Business Details'
    ]
)] class extends Component {

    //EDIT BUSINESS
    public bool $showEditModal = false;

    public string $name = '';
    public ?string $description = null;
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $website = null;
    public ?string $address = null;

    public function openEditModal(): void
    {
        $this->name = $this->business->name;
        $this->description = $this->business->description;
        $this->phone = $this->business->phone;
        $this->email = $this->business->email;
        $this->website = $this->business->website;
        $this->address = $this->business->address;

        $this->showEditModal = true;
    }

    public function updateBusiness(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'website' => ['nullable', 'url'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $this->business->update([
            'name' => $this->name,
            'description' => $this->description,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'address' => $this->address,
        ]);

        $this->showEditModal = false;

        session()->flash('success', 'Business details updated successfully.');
    }


    public Business $business;

    public function mount(Business $business): void
    {
        $this->business = $business->load([
            'user',
            'category',
            'reviews.user',
            'products',
            'media',
        ]);
    }
};
?>

<div>

  <style>
    /* Header wrapper */
    .business-header {
      padding-bottom: 1rem;
      border-bottom: 1px solid #e5e7eb;
    }

    /* Bigger logo badge */
    .business-logo-lg {
      width: 64px;
      height: 64px;
      border-radius: 16px;
      background: #e9eef2;
      color: #101828;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      font-weight: 700;
      letter-spacing: -0.02em;
    }

    /* Business name */
    .business-title {
      font-size: 1.35rem;
      font-weight: 700;
      letter-spacing: -0.02em;
      color: #0f172a;
    }

    /* Meta line */
    .business-meta {
      font-size: 0.9rem;
      color: #667085;
    }

    /* Chip style if you don’t already have it */
    .chip {
      display: inline-block;
      padding: 0.25rem 0.6rem;
      font-size: 0.72rem;
      border-radius: 999px;
      background: rgba(15, 124, 146, 0.08);
      color: #0f7c92;
      font-weight: 500;
    }


      .business-overview-row {
        align-items: stretch;
      }

      /* small chips used throughout */
      .chip {
        display: inline-flex;
        align-items: center;
        padding: 0.18rem 0.6rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 500;
        background-color: #f3f4f6;
        color: #4b5563;
      }

      /* gallery */
      .gallery-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 0.75rem;
      }

      .gallery-thumb {
        position: relative;
        border-radius: 0.75rem;
        overflow: hidden;
        background: #e5e7eb;
        padding-top: 60%; /* 5:3 ratio */
      }

      .gallery-thumb img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
      }

      /* ratings */
      .rating-stars i {
        font-size: 0.8rem;
      }

      /* Business analytics cards */
      .business-metric-card .card-body {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 1rem 1.25rem;
      }

      .business-metric-header {
        display: flex;
        align-items: flex-start;
      }

      .business-metric-header .title {
        font-size: 0.84rem;
        color: var(--muted);
      }

      .business-metric-number {
        font-size: 1.6rem;
        font-weight: 600;
        line-height: 1.1;
      }

      .business-metric-icon {
        margin-left: auto;
        width: 44px;
        height: 44px;
        border-radius: 999px;
        background: rgba(15, 124, 146, 0.08);
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }

      .business-metric-icon i {
        font-size: 1.4rem;
        color: var(--text-blue);
      }

      /* tiny bars */
      .metric-bar {
        position: relative;
        height: 6px;
        border-radius: 999px;
        background: #edf2f7;
        overflow: hidden;
        margin-top: 0.4rem;
      }

      .metric-bar-fill {
        height: 100%;
        border-radius: 999px;
      }

      .metric-bar-fill-success {
        background: #1DBF73;
      }

      .metric-bar-fill-info {
        background: #0F7C92;
      }

      /* ratings stars a bit smaller */
      .rating-stars i {
        font-size: 0.8rem;
      }

      /* checklist in completeness */
      .completeness-list {
        list-style: none;
        padding-left: 0;
        margin-bottom: 0;
      }

      .completeness-list li {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.8rem;
      }
      .completeness-list i {
        font-size: 0.9rem;
      }
  </style>

    <!-- HEADER -->
    <section class="business-header mb-4">

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show small">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
            </div>
        @endif

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="{{ route('admin.businesses.index') }}"
               class="btn btn-sm btn-outline-secondary rounded-pill">
                <i class="bi bi-arrow-left me-1"></i> Back to businesses
            </a>

            <div class="d-flex gap-2">
                <button
                    class="btn btn-sm btn-outline-primary rounded-pill"
                    wire:click="openEditModal"
                >
                    Edit business
                </button>

                <!--
                <div class="btn-group">
                    <button class="btn btn-sm btn-outline-secondary rounded-pill dropdown-toggle"
                            data-bs-toggle="dropdown">
                        More
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <button class="dropdown-item">Hide listing</button>
                        </li>
                        <li>
                            <button class="dropdown-item text-danger">Delete business</button>
                        </li>
                    </ul>
                </div>
                -->
            </div>
        </div>

        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="business-logo-lg">
                {{ Str::upper(Str::substr($business->name, 0, 2)) }}
            </div>

            <div>
                <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                    <h1 class="business-title mb-0">
                        {{ $business->name }}
                    </h1>

                    @if($business->is_verified)
                        <span class="badge bg-success-subtle text-success border rounded-pill">
                            Verified
                        </span>
                    @else
                        <span class="badge bg-warning-subtle text-warning border rounded-pill">
                            Pending
                        </span>
                    @endif

                    @if($business->category)
                        <span class="chip">{{ $business->category->name }}</span>
                    @endif
                </div>

                <div class="business-meta">
                    Owned by
                    <strong>{{($business->user->username ?? $business->user->name) }}</strong>
                    · Joined
                    <strong>{{ $business->created_at->format('d M Y') }}</strong>
                </div>
            </div>
        </div>
    </section>

    <!-- METRICS -->
    <section class="mb-4">
        <div class="row g-3 g-md-4">

            <div class="col-12 col-md-4">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <div>
                                <div class="small text-muted-soft">Rating</div>
                                <div class="h4 mb-0">
                                    {{ number_format($business->avg_rating, 1) }} / 5
                                </div>
                            </div>
                            <div class="icon-circle">
                                <i class="bi bi-star-fill"></i>
                            </div>
                        </div>
                        <div class="small text-muted-soft">
                            Based on {{ $business->total_reviews }} reviews
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <div>
                                <div class="small text-muted-soft">Engagement</div>
                                <div class="h4 mb-0">
                                    {{ number_format($business->views_count) }}
                                </div>
                            </div>
                            <div class="icon-circle">
                                <i class="bi bi-graph-up"></i>
                            </div>
                        </div>
                        <div class="small text-muted-soft">Total views</div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <div>
                                <div class="small text-muted-soft">Status</div>
                                <div class="h4 mb-0">
                                    {{ $business->is_verified ? 'Verified' : 'Pending' }}
                                </div>
                            </div>
                            <div class="icon-circle">
                                <i class="bi bi-patch-check"></i>
                            </div>
                        </div>
                        <div class="small text-muted-soft">
                            Registry visibility
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- PRODUCTS & ABOUT -->
    <section class="mb-4">
        <div class="row g-3 g-md-4">

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">Products & Services</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush small">
                        @forelse($business->products as $product)
                            <li class="list-group-item px-0">
                                <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold">{{ $product->name }}</div>
                                    <div class="text-muted-soft">
                                    Product or Service
                                    </div>
                                    <div class="mt-1 d-flex flex-wrap gap-1">
                                    <span class="chip"> Available </span>
                                    </div>
                                </div>
                                </div>
                            </li>
                        @empty
                            <p class="small text-muted-soft">No products listed.</p>
                        @endforelse
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">About</h5>
                    </div>
                    <div class="card-body small">
                        <p>{{ $business->description ?: 'No description provided.' }}</p>

                        <div class="row g-2 mt-3">
                            <div class="col-6">
                                <div class="text-muted-soft">Phone</div>
                                <div>{{ $business->phone ?? '—' }}</div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted-soft">Email</div>
                                <div>{{ $business->email ?? '—' }}</div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted-soft">Address</div>
                                <div>{{ $business->address ?? '—' }}</div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted-soft">Website</div>
                                <div>
                                    @if($business->website)
                                        <a href="{{ $business->website }}" target="_blank">
                                            {{ $business->website }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- GALLERY & REVIEWS -->
    <section>
        <div class="row g-3 g-md-4">

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">Gallery</h5>
                    </div>
                    <div class="card-body">
                        <div class="gallery-grid">
                            @forelse($business->getMedia('gallery') as $media)
                                <div class="gallery-thumb">
                                    <img src="{{ $media->getUrl() }}" alt="Gallery image for {{ $business->name }}" loading="lazy" decoding="async">
                                </div>
                            @empty
                                <p class="small text-muted-soft">No images uploaded.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between">
                        <h5 class="mb-0">Reviews</h5>
                        <span class="small text-muted-soft">
                            {{ $business->total_reviews }} total
                        </span>
                    </div>
                    <div class="card-body">
                        @forelse($business->reviews->take(5) as $review)
                            <div class="mb-3">
                                <strong>{{ '@' . $review->user->username }}</strong>
                                <span class="text-muted-soft"> • {{ $review->rating }}/5</span>
                                <p class="mb-1">{{ $review->comment }}</p>
                                <div class="small text-muted-soft">
                                    {{ $review->created_at->diffForHumans() }}
                                </div>
                            </div>
                        @empty
                            <p class="small text-muted-soft">No reviews yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>

        </div>
    </section>

    @if($showEditModal)
    <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="editBusinessModalLabel" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="editBusinessModalLabel">Edit Business Details</h5>
                    <button type="button" class="btn-close" wire:click="$set('showEditModal', false)" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <form wire:submit.prevent="updateBusiness">

                        <div class="row g-3">

                            <div class="col-12">
                                <label class="form-label small">Business name</label>
                                <input
                                    type="text"
                                    class="form-control form-control-sm"
                                    wire:model.defer="name"
                                >
                                @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label small">Description</label>
                                <textarea
                                    class="form-control form-control-sm"
                                    rows="3"
                                    wire:model.defer="description"
                                ></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small">Phone</label>
                                <input
                                    type="text"
                                    class="form-control form-control-sm"
                                    wire:model.defer="phone"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small">Email</label>
                                <input
                                    type="email"
                                    class="form-control form-control-sm"
                                    wire:model.defer="email"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small">Website</label>
                                <input
                                    type="url"
                                    class="form-control form-control-sm"
                                    wire:model.defer="website"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small">Address</label>
                                <input
                                    type="text"
                                    class="form-control form-control-sm"
                                    wire:model.defer="address"
                                >
                            </div>

                        </div>

                    </form>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-outline-secondary rounded-pill"
                        wire:click="$set('showEditModal', false)"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        class="btn btn-primary rounded-pill"
                        wire:click="updateBusiness"
                        wire:loading.attr="disabled"
                    >
                        Save changes
                    </button>
                </div>

            </div>
        </div>
    </div>

    <div class="modal-backdrop fade show"></div>
    @endif


</div>
