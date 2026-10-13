    @extends('layouts.app')

    @section('title', 'My Account - Loveworld Business Registry')

    @push('styles')
        <style>
            body { background:#f6f8fa; color: var(--dark-color); }

            /* Show search bar on dashboard */
            .navbar-search {
                display: flex !important;
            }

            /* Page header */
            .page-header {
                display:flex;
                align-items:flex-end;
                justify-content:space-between;
                gap:20px;
                margin-bottom:26px;
                padding-top: 24px;
                padding-bottom: 20px;
            }
            .page-title {
                font-size:32px;
                font-weight:800;
                letter-spacing:.2px;
                color: var(--dark-color);
                margin-bottom: 6px;
                font-family: 'YLocal Book', sans-serif;
            }
            .page-subtitle {
                font-size: 15px;
                color: var(--muted);
                font-weight: 400;
                line-height: 1.5;
                margin-top: 6px;
            }
            .header-actions {
                display:flex;
                gap:12px;
                align-items:center;
            }

            .header-actions .btn {
                font-size: 14px;
                font-weight: 500;
                padding: 8px 16px;
            }

            /* Profile tabs styling */
            .profile-tabs-wrapper .nav {
                display: flex !important;
                gap: 1.25rem;
                align-items: center;
                border-bottom: 0 !important;
                padding-left: 0;
                margin-bottom: 0;
            }

            .profile-tabs-wrapper .nav-tabs { border-bottom: 0 !important; }
            .profile-tabs-wrapper .nav-item { margin-bottom: 0; }

            .profile-tabs-wrapper .nav-link {
                color: var(--muted) !important;
                background: transparent !important;
                border: none !important;
                border-radius: 0 !important;
                padding: .5rem .25rem !important;
                font-weight: 700 !important;
                font-size: 1rem;
                position: relative;
                transition: color .15s ease, transform .12s ease;
                text-transform: none;
                font-family: 'YLocal Book', sans-serif;
            }

            .profile-tabs-wrapper .nav-link:hover,
            .profile-tabs-wrapper .nav-link:focus {
                color: var(--dark-color) !important;
                transform: translateY(-2px);
                text-decoration: none;
                outline: none;
                box-shadow: none;
            }

            .profile-tabs-wrapper .nav-link.active,
            .profile-tabs-wrapper .nav-link[aria-selected="true"] {
                color: var(--dark-color) !important;
                font-weight: 800 !important;
            }

            .profile-tabs-wrapper .nav-link.active::after,
            .profile-tabs-wrapper .nav-link[aria-selected="true"]::after {
                content: '';
                position: absolute;
                left: 0;
                right: 0;
                bottom: -1px;
                height: 3px;
                background: var(--light-color);
                border-radius: 0;
            }

            .profile-tabs-wrapper .nav-link { display: inline-block; }

            /* Tab content spacing to connect with tabs */
            .profile-tabs-wrapper .tab-content {
                padding-top: 0;
                position: relative;
                margin-top: 0;
            }

            .profile-tabs-wrapper .nav-tabs {
                margin-bottom: 0 !important;
            }

            /* Card-ghost connects with tab underline */
            .profile-tabs-wrapper .tab-content .card-ghost {
                border-top-left-radius: 0;
                margin-top: 0;
            }

            /* Cards */
            .card-ghost {
                background:var(--bg-light-gray);
                border-radius:0px;
                padding:18px;
                box-shadow: 0 10px 28px rgba(20,42,59,0.06);
            }

            .listing-card {
                display:grid;
                grid-template-columns:84px 1fr 140px;
                gap:16px;
                align-items:center;
                padding:14px;
                border-radius:0px;
                background:#fff;
                border:1px solid rgba(20,42,59,0.03);
                transition: transform .15s ease, box-shadow .15s ease;
            }

            .listing-card:hover {
                transform: translateY(-6px);
                box-shadow: 0 18px 40px rgba(20,42,59,0.06);
            }

            .listing-thumb {
                width:84px;
                height:84px;
                object-fit:contain;
                border-radius:0px;
                border:1px solid rgba(20,42,59,0.04);
            }

            .listing-title {
                font-weight:700;
                color:var(--dark-color);
                margin-bottom:6px;
                text-decoration:none;
                display:inline-block;
                transition: color .12s ease, transform .12s ease;
                font-size: 15px;
                font-family: 'YLocal Book', sans-serif;
            }

            .listing-title:hover {
                color:var(--light-color);
                transform: translateY(-2px);
            }

            .listing-meta {
                color:#6b7280;
                font-size:13px;
                line-height: 1.5;
            }

            .rating {
                color:#F4B942;
                font-weight:700;
                letter-spacing:1px;
            }

            .rating .view-count {
                color: var(--muted);
                font-size: 13px;
                margin-bottom: 8px;
                font-weight: 400;
            }

            /* Empty state */
            .empty-state {
                text-align:center;
                padding:48px 24px;
                color:var(--muted);
            }

            .empty-icon {
                width:92px;
                height:92px;
                border-radius:0px;
                display:inline-flex;
                align-items:center;
                justify-content:center;
                border:2px dashed rgba(20,42,59,0.06);
                margin-bottom:16px;
            }

            /* Buttons */
            .btn-cta {
                background: var(--light-color);
                color:#fff;
                border: none;
                border-radius:0px;
                padding:.5rem .9rem;
                font-weight:700;
                font-size: 14px;
                transition: transform .12s ease, box-shadow .12s ease;
                font-family: 'YLocal Book', sans-serif;
            }

            .btn-cta:hover {
                background: #17A05C;
                transform: translateY(-2px);
                box-shadow: 0 8px 22px rgba(29,191,115,0.15);
                color: #fff;
            }

            .btn-ghost {
                background:transparent;
                border:1px solid rgba(20,42,59,0.06);
                border-radius:0px;
                color:var(--dark-color);
                padding:.5rem .9rem;
                font-weight:500;
                font-size: 14px;
                font-family: 'YLocal Book', sans-serif;
            }

            .btn-ghost:hover {
                background: rgba(20,42,59,0.03);
                color: var(--dark-color);
            }

            .btn-ghost.btn-icon {
                padding: .4rem .6rem;
                color: #6b7280;
            }

            .btn-ghost.btn-icon:hover {
                background: rgba(20,42,59,0.03);
                color: var(--dark-color);
            }

            /* Subtle focus styles */
            .listing-title:focus, .list-group-item:focus {
                outline:3px solid rgba(29,191,115,0.10);
                outline-offset:6px;
            }

            /* Small-screen responsive adjustments for tabs */
            @media (max-width: 767.98px) {

                /* Page header */
                .page-header {
                    gap:20px;
                    margin-bottom:10px;
                    padding-top: 0px;
                    padding-bottom: 10px;
                    margin-top: -1.1em;
                }

                .profile-tabs-wrapper .nav {
                    gap: .75rem;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                    padding-bottom: .5rem;
                }
                .profile-tabs-wrapper .nav-link {
                    padding: .5rem .25rem !important;
                    font-size: 10pt;
                }

                .listing-card {
                    grid-template-columns:72px 1fr;
                    grid-template-areas: "img content" "img actions";
                    gap:12px;
                }
                .listing-card > :nth-child(3) { grid-column: 2 / -1; }
                .listing-title {
                    margin-left: 10px;
                }

                .listing-meta {
                    margin-left: 10px;
                }

                table tbody tr td {
                    font-size: 10pt;
                }
            }

        </style>
    @endpush

    @section('content')
        <div style="padding-top: 120px !important; padding-bottom: 48px;">
            <div class="container">
                <x-ad-slot name="dashboard_top" />
                <div class="page-header">
                    <div>
                        <div class="page-title">My Account</div>
                        <div class="page-subtitle">Manage your Listings, Favorites and grow your brand</div>
                    </div>
                    <!--
                    <div class="header-actions">
                        <a href="{{ route('profile.edit') }}" class="btn btn-ghost">
                            <i class="bi bi-person-gear me-2"></i> Account Settings
                        </a>
                    </div>
                    -->
                </div>

                <div class="row g-4">
                    <!-- Main column -->
                    <div class="col-12 profile-tabs-wrapper">
                        <!-- Tabs -->
                        <ul class="nav nav-tabs" id="accountTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="verified-tab" data-bs-toggle="tab"
                                        data-bs-target="#verified" type="button" role="tab">
                                    Verified Listings
                                </button>
                            </li>

                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="unverified-tab" data-bs-toggle="tab"
                                        data-bs-target="#unverified" type="button" role="tab">
                                    Unverified Listings
                                </button>
                            </li>

                            @if($sboNetworkReady ?? false)
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="rfqs-tab" data-bs-toggle="tab"
                                        data-bs-target="#rfqs" type="button" role="tab">
                                    Requests
                                </button>
                            </li>
                            @endif

                            @if(($sboNetworkReady ?? false) || $bidsSent->count())
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="bids-sent-tab" data-bs-toggle="tab"
                                        data-bs-target="#bids-sent" type="button" role="tab">
                                    Bids Sent
                                </button>
                            </li>
                            @endif

                            @if(($sboNetworkReady ?? false) || $bidsAccepted->count())
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="bids-accepted-tab" data-bs-toggle="tab"
                                        data-bs-target="#bids-accepted" type="button" role="tab">
                                    Bids Accepted
                                </button>
                            </li>
                            @endif

                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="favorites-tab" data-bs-toggle="tab"
                                        data-bs-target="#favorites" type="button" role="tab">
                                    Favorites
                                </button>
                            </li>

                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="viewed-tab" data-bs-toggle="tab"
                                        data-bs-target="#viewed" type="button" role="tab">
                                    Recently Viewed
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <!-- Verified Listings -->
                            <div class="tab-pane fade show active" id="verified" role="tabpanel">
                                <div class="card-ghost mb-3">
                                    <div class="d-flex align-item"s-center justify-content-between mb-3">
                                        <div class="hidden-mobile">
                                            <div style="font-size:14px; color:#6b7280;">Manage, edit and promote your business listings</div>
                                        </div>
                                        <div>
                                            <a href="{{ route('businesses.index') }}" class="btn btn-ghost me-2">
                                                <i class="bi bi-eye"></i> View SBOs
                                            </a>
                                            <button class="btn btn-cta" data-bs-toggle="modal" 
                                            onclick="
                                            if (window.Livewire) {
                                                    Livewire.dispatch('open-create-business');
                                            }              
                                            "
                                            >
                                                <i class="bi bi-plus-lg me-2"></i> New Listing
                                            </button>
                                        </div>
                                    </div>

                                    @if($verifiedBusinesses->count() > 0)
                                        <div class="d-flex flex-column gap-3">
                                            @foreach($verifiedBusinesses as $business)
                                                <div class="listing-card">
                                                    <img src="{{ $business->getFirstMediaUrl('logo') ?: asset('images/placeholder-logo.svg') }}"
                                                        alt="{{ $business->name }}"
                                                        class="listing-thumb" loading="lazy" decoding="async">

                                                    <div class="listing-block">
                                                        <a href="{{ route('businesses.show', $business->slug) }}" class="listing-title">
                                                            {{ $business->name }}
                                                        </a>
                                                        <div class="listing-meta">
                                                            {{ $business->address ?? $business->city }}<br>
                                                            <span class="text-warning">★★★★☆</span> 4 YP rating
                                                        </div>
                                                    </div>

                                                    <div class="text-end rating">
                                                        <div class="view-count">
                                                            {{ number_format($business->views_count) }} views
                                                        </div>
                                                        <div class="d-flex gap-2 justify-content-end">
                                                            <a href="{{ route('businesses.show', $business->slug) }}" class="btn btn-sm btn-ghost btn-icon" title="View">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                            <button class="btn btn-sm btn-ghost btn-icon" title="Edit"
                                                                    onclick="Livewire.dispatch('edit-business', { businessId: {{ $business->id }} })">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-ghost btn-icon text-danger" title="Delete"
                                                                    onclick="if(confirm('Are you sure you want to delete this listing? This action cannot be undone.')) { Livewire.dispatch('delete-business', { businessId: {{ $business->id }} }) }">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="bi bi-briefcase" style="font-size: 3rem; color: var(--muted);"></i>
                                            </div>
                                            <h5 style="font-weight:700;">No verified listings yet</h5>
                                            <p class="text-muted">Start by adding your first business listing</p>
                                            <button class="btn btn-cta mt-2" data-bs-toggle="modal" onclick="Livewire.dispatch('open-create-business')">>
                                                <i class="bi bi-plus-lg me-2"></i> Add Listing
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <!-- Unverified Listings -->
                            <div class="tab-pane fade" id="unverified" role="tabpanel">
                                <div class="card-ghost mb-3">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div>
                                            <div style="font-weight:800; font-size:1.05rem;">Unverified Listings</div>
                                            <div class="text-muted" style="font-size:.95rem;">SBOs pending verification</div>
                                        </div>
                                    </div>

                                    @if($unverifiedBusinesses->count() > 0)
                                        <div class="d-flex flex-column gap-3">
                                            @foreach($unverifiedBusinesses as $business)
                                                <div class="listing-card">
                                                    <img src="{{ $business->getFirstMediaUrl('logo') ?: asset('images/placeholder-logo.svg') }}"
                                                        alt="{{ $business->name }}"
                                                        class="listing-thumb" loading="lazy" decoding="async">

                                                    <div>
                                                        <a href="{{ route('businesses.show', $business->slug) }}" class="listing-title">
                                                            {{ $business->name }}
                                                        </a>
                                                        <div class="listing-meta">
                                                            {{ $business->address ?? $business->city }}<br>
                                                            <span class="text-warning">★★★★☆</span> 4 YP rating
                                                        </div>
                                                    </div>

                                                    <div class="text-end rating">
                                                        <div class="view-count">
                                                            {{ number_format($business->views_count) }} views
                                                        </div>
                                                        <div class="d-flex gap-2 justify-content-end">
                                                            <a href="{{ route('businesses.show', $business->slug) }}" class="btn btn-sm btn-ghost btn-icon" title="View">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                            <button class="btn btn-sm btn-ghost btn-icon" title="Edit"
                                                                    onclick="Livewire.dispatch('edit-business', { businessId: {{ $business->id }} })">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-ghost btn-icon text-danger" title="Delete"
                                                                    onclick="if(confirm('Are you sure you want to delete this listing? This action cannot be undone.')) { Livewire.dispatch('delete-business', { businessId: {{ $business->id }} }) }">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="bi bi-lock-fill" style="font-size: 3rem; color: var(--muted);"></i>
                                            </div>
                                            <h5 style="font-weight:700;">No unverified listings </h5>
                                            <p class="text-muted">Start by adding your first business listing</p>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @if($sboNetworkReady ?? false)
                            <!-- Procurement Requests -->
                            <div class="tab-pane fade" id="rfqs" role="tabpanel">
                                <div class="card-ghost mb-3">

                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <div>
                                            <div style="font-weight:800; font-size:1.05rem;">Available Procurement Requests</div>
                                            <div class="text-muted" style="font-size:.95rem;">
                                                Requests you are eligible to bid on
                                            </div>
                                        </div>
                                    </div>

                                    @if($availableRfqs->count() > 0)
                                        <div class="d-flex flex-column gap-3">

                                            @foreach($availableRfqs as $rfq)
                                                @php
                                                    $requestTypeLabels = [
                                                        'product' => 'Product',
                                                        'service' => 'Service',
                                                        'product_service' => 'Product & Service',
                                                    ];
                                                    $requestType = $rfq->request_type ?? 'product';
                                                    $requestTypeLabel = $requestTypeLabels[$requestType] ?? 'Product';
                                                @endphp
                                                <div class="listing-card">

                                                    <!-- Icon / thumbnail -->
                                                    <div class="d-flex align-items-center justify-content-center"
                                                        style="width:84px;height:84px;border:1px solid rgba(20,42,59,0.04);background:#f6f8fa;">
                                                        <i class="bi bi-clipboard-check" style="font-size:2rem;color:#6b7280;"></i>
                                                    </div>

                                                    <!-- RFQ info -->
                                                    <div>
                                                        <div class="listing-title">
                                                            {{ $rfq->title }}
                                                        </div>
                                                        <div class="mb-2">
                                                            <span class="badge bg-info-subtle text-info">{{ $requestTypeLabel }}</span>
                                                        </div>

                                                        <div class="listing-meta">
                                                            <strong>Organization:</strong> {{ $rfq->sbo->name ?? '—' }}<br>
                                                            <strong>Category:</strong> {{ $rfq->category->name ?? '—' }}<br>
                                                            @if(in_array($requestType, ['product', 'product_service'], true) && $rfq->quantity)
                                                                <strong>Quantity:</strong> {{ $rfq->quantity }}{{ $rfq->unit_specification ? ' '.$rfq->unit_specification : '' }}<br>
                                                            @endif
                                                            @if(in_array($requestType, ['service', 'product_service'], true) && $rfq->service_duration)
                                                                <strong>Service Duration:</strong> {{ $rfq->service_duration }}<br>
                                                            @endif

                                                            @if($rfq->order_deadline)
                                                                <strong>Deadline:</strong>
                                                                {{ $rfq->order_deadline->format('M d, Y') }}
                                                            @else
                                                                <strong>Deadline:</strong> Open
                                                            @endif
                                                        </div>
                                                    </div>

                                                    <!-- Actions -->
                                                    <div class="text-end">
                                                        <div class="d-flex flex-column gap-2 align-items-end">

                                                            @if($rfq->order_deadline && $rfq->order_deadline->isPast())
                                                                <span class="badge bg-warning-subtle text-warning">
                                                                    Deadline passed
                                                                </span>
                                                            @else
                                                                <span class="badge bg-success-subtle text-success">
                                                                    Open
                                                                </span>
                                                            @endif

                                                            <button
                                                                class="btn btn-sm btn-cta"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#rfqDetailsModal"
                                                                data-rfq='@json($rfq)'
                                                            >
                                                                View Request
                                                            </button>

                                                        </div>
                                                    </div>

                                                </div>
                                            @endforeach

                                        </div>
                                    @else
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="bi bi-clipboard" style="font-size:3rem;color:var(--muted);"></i>
                                            </div>
                                            <h5 style="font-weight:700;">No procurement requests available</h5>
                                            <p class="text-muted">
                                                Public and private procurement requests will appear here when one of your fully verified SBOs is eligible to bid.
                                            </p>
                                        </div>
                                    @endif

                                </div>
                            </div>
                            @endif

                            @if(($sboNetworkReady ?? false) || $bidsSent->count())
                            <!-- Bids Sent -->
                            <div class="tab-pane fade" id="bids-sent" role="tabpanel">
                                <div class="card-ghost">

                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <div style="font-weight:800; font-size:1.05rem;">
                                                Bids Sent
                                            </div>
                                            <div class="text-muted" style="font-size:.95rem;">
                                                Procurement requests you have responded to
                                            </div>
                                        </div>
                                    </div>

                                    @if($bidsSent->count())
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle mb-0">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th>Request</th>
                                                        <th>SBO</th>
                                                        <th>Status</th>
                                                        <th>Submitted</th>
                                                        <th class="text-end">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($bidsSent as $bid)
                                                        <tr>
                                                            <td>
                                                                <div class="fw-semibold">
                                                                    {{ $bid->purchaseOrder->title }}
                                                                </div>
                                                            </td>

                                                            <td>
                                                                {{ $bid->bidderSbo?->name ?? $bid->business?->name ?? '—' }}
                                                            </td>

                                                            <td>
                                                                @if($bid->status === 'pending')
                                                                    <span class="badge bg-warning-subtle text-warning border">
                                                                        Pending
                                                                    </span>
                                                                @elseif($bid->status === 'accepted')
                                                                    <span class="badge bg-success-subtle text-success border">
                                                                        Accepted
                                                                    </span>
                                                                @else
                                                                    <span class="badge bg-danger-subtle text-danger border">
                                                                        Rejected
                                                                    </span>
                                                                @endif
                                                            </td>

                                                            <td class="text-muted">
                                                                {{ $bid->submitted_at?->format('M d, Y') }}
                                                            </td>
                                                            <td class="text-end">
                                                                <button
                                                                    class="btn btn-outline-success"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#rfqDetailsModal"
                                                                    data-rfq='@json($bid->purchaseOrder)'
                                                                >
                                                                    View
                                                                </button>
                                                            </td>

                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @else
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="bi bi-send" style="font-size: 3rem; color: var(--muted);"></i>
                                            </div>
                                            <h5 style="font-weight:700;">No bids submitted yet</h5>
                                            <p class="text-muted">
                                                When you respond to procurement requests, your bids will appear here.
                                            </p>
                                        </div>
                                    @endif

                                </div>
                            </div>
                            @endif

                            @if(($sboNetworkReady ?? false) || $bidsAccepted->count())
                                                    
                            <!-- Bids Accepted -->
                            <div class="tab-pane fade" id="bids-accepted" role="tabpanel">
                                <div class="card-ghost">

                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <div style="font-weight:800; font-size:1.05rem;">
                                                Bids Accepted
                                            </div>
                                            <div class="text-muted" style="font-size:.95rem;">
                                                Procurement requests where your bid has been awarded
                                            </div>
                                        </div>
                                    </div>

                                    @if($bidsAccepted->count())
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle mb-0">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th>Request</th>
                                                        <th>Organization</th>
                                                        <th>Status</th>
                                                        <th class="text-end">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($bidsAccepted as $bid)
                                                        <tr>
                                                            <td>
                                                                <div class="fw-semibold">
                                                                    {{ $bid->purchaseOrder->title }}
                                                                </div>
                                                                <div class="small text-muted">
                                                                    {{ $bid->purchaseOrder->category->name ?? '—' }}
                                                                </div>
                                                            </td>

                                                            <td>
                                                                {{ $bid->purchaseOrder->sbo->name ?? '—' }}
                                                            </td>

                                                            <td>
                                                                <span class="badge bg-success-subtle text-success border">
                                                                    Awarded
                                                                </span>
                                                                <div id="vendorBidAcceptedDate" class="small text-muted mt-1"></div>
                                                            </td>

                                                            <td class="text-end">
                                                                <button
                                                                    class="btn btn-sm btn-outline-success"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#rfqDetailsModal"
                                                                    data-rfq='@json($bid->purchaseOrder)'
                                                                >   
                                                                    View
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @else
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="bi bi-award" style="font-size:3rem;color:var(--muted);"></i>
                                            </div>
                                            <h5 style="font-weight:700;">
                                                No accepted bids yet
                                            </h5>
                                            <p class="text-muted">
                                                When an organization awards your bid, it will appear here.
                                            </p>
                                        </div>
                                    @endif

                                </div>
                            </div>


                            @endif

                            <!-- Favorites -->
                            <div class="tab-pane fade" id="favorites" role="tabpanel">
                                <div class="card-ghost mb-3">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div>
                                            <div style="font-weight:800; font-size:1.05rem;">Favorites</div>
                                            <div class="text-muted" style="font-size:.95rem;">SBOs you saved</div>
                                        </div>
                                        @if($favorites->count() > 0)
                                            <button onclick="confirmClearFavorites()" class="btn btn-ghost btn-sm">Clear</button>
                                        @endif
                                    </div>

                                    @if($favorites->count() > 0)
                                        <div class="d-flex flex-column gap-3">
                                            @foreach($favorites as $business)
                                                <div class="listing-card">
                                                    <img src="{{ $business->getFirstMediaUrl('logo') ?: asset('images/placeholder-logo.svg') }}"
                                                        alt="{{ $business->name }}"
                                                        class="listing-thumb" loading="lazy" decoding="async">

                                                    <div>
                                                        <a href="{{ route('businesses.show', $business->slug) }}" class="listing-title">
                                                            {{ $business->name }}
                                                        </a>
                                                        <div class="listing-meta">
                                                            {{ $business->category->name }} • {{ $business->city }}
                                                        </div>
                                                    </div>

                                                    <div class="text-end">
                                                        @livewire('favorite-button', ['businessId' => $business->id], key($business->id))
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="bi bi-heart" style="font-size: 3rem; color: var(--muted);"></i>
                                            </div>
                                            <h5 style="font-weight:700;">You haven't added any favorites</h5>
                                            <p class="text-muted">Keep track of businesses you do business with. Add them to your favourites to save their contact info in one handy location.</p>
                                            <a href="{{ route('businesses.index') }}" class="btn btn-cta mt-2">
                                                Browse SBOs
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <!-- Recently Viewed -->
                            <div class="tab-pane fade" id="viewed" role="tabpanel">
                                <div class="card-ghost">
                                    @if($recentlyViewed->count() > 0)
                                        <div class="d-flex flex-column gap-3">
                                            @foreach($recentlyViewed as $business)
                                                <div class="listing-card">
                                                    <img src="{{ $business->getFirstMediaUrl('logo') ?: asset('images/placeholder-logo.svg') }}"
                                                        alt="{{ $business->name }}"
                                                        class="listing-thumb" loading="lazy" decoding="async">

                                                    <div>
                                                        <a href="{{ route('businesses.show', $business->slug) }}" class="listing-title">
                                                            {{ $business->name }}
                                                        </a>
                                                        <div class="listing-meta">
                                                            {{ $business->category->name }} • {{ $business->city }}
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <svg width="68" height="68" viewBox="0 0 24 24" fill="none">
                                                    <circle cx="12" cy="12" r="10" stroke="#d8dde0" stroke-width="1.6"/>
                                                    <path d="M12 7v5l3 1" stroke="#c6c6c6" stroke-width="1.6" stroke-linecap="round"/>
                                                </svg>
                                            </div>
                                            <h5 style="font-weight:700;">No recently viewed SBOs</h5>
                                            <p class="text-muted">You haven't viewed any business profiles recently. When you view listings, they'll appear here so you can quickly return to them.</p>
                                            <a href="{{ route('businesses.index') }}" class="btn btn-cta mt-2">
                                                Browse SBOs
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <script>
        function confirmClearFavorites() {
            if (confirm('Are you sure you want to remove all favorites? This action cannot be undone.')) {
                // Create a form and submit it as DELETE request
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '{{ route("favorites.clear") }}';

                const csrfToken = document.createElement('input');
                csrfToken.type = 'hidden';
                csrfToken.name = '_token';
                csrfToken.value = '{{ csrf_token() }}';

                const methodInput = document.createElement('input');
                methodInput.type = 'hidden';
                methodInput.name = '_method';
                methodInput.value = 'DELETE';

                form.appendChild(csrfToken);
                form.appendChild(methodInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Function to handle tab activation
        function activateTabFromUrl() {
            // Get hash from URL (e.g., #favorites)
            let hash = window.location.hash;

            if (hash) {
                // Activate tab from hash
                const tabButton = document.querySelector(`[data-bs-target="${hash}"]`);
                if (tabButton) {
                    const tab = new bootstrap.Tab(tabButton);
                    tab.show();
                }
            } else {
                // Fall back to session storage
                const savedTab = sessionStorage.getItem('activeTab');
                if (savedTab) {
                    const tabButton = document.querySelector(`[data-bs-target="${savedTab}"]`);
                    if (tabButton) {
                        const tab = new bootstrap.Tab(tabButton);
                        tab.show();
                    }
                    sessionStorage.removeItem('activeTab');
                }
            }
        }

        // Run on initial page load
        document.addEventListener('DOMContentLoaded', activateTabFromUrl);

        // Run after Livewire navigation
        document.addEventListener('livewire:navigated', activateTabFromUrl);

        // Update URL hash when tabs are clicked
        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(tabButton) {
            tabButton.addEventListener('shown.bs.tab', function(event) {
                const target = event.target.getAttribute('data-bs-target');
                if (target) {
                    // Update URL hash without scrolling
                    history.replaceState(null, null, target);
                }
            });
        });
        </script>

        @auth
            @livewire('create-business-modal')
            @if($sboNetworkReady ?? false)
                @livewire('submit-bid-modal')
            @endif
        @endauth

        <div
            class="modal fade"
            id="rfqDetailsModal"
            tabindex="-1"
            aria-labelledby="rfqModalTitle"
            aria-hidden="true"
        >
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title" id="rfqModalTitle">Procurement Request Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">

                        <div class="mb-3">
                            <div class="small text-muted">Organization</div>
                            <div class="fw-semibold" id="rfqModalOrg">—</div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <div class="small text-muted">Category</div>
                                <div id="rfqModalCategory">—</div>
                            </div>

                            <div class="col-md-6">
                                <div class="small text-muted">Request Type</div>
                                <div id="rfqModalRequestType">—</div>
                            </div>

                            <div class="col-md-6">
                                <div class="small text-muted">Submission Deadline</div>
                                <div id="rfqModalDeadline">—</div>
                            </div>

                            <div class="col-md-6" id="rfqModalBudgetWrap" style="display:none;">
                                <div class="small text-muted">Budget</div>
                                <div id="rfqModalBudget">—</div>
                            </div>

                            <div class="col-md-6" id="rfqModalQuantityWrap" style="display:none;">
                                <div class="small text-muted">Quantity</div>
                                <div id="rfqModalQuantity">—</div>
                            </div>

                            <div class="col-md-6" id="rfqModalUnitWrap" style="display:none;">
                                <div class="small text-muted">Unit / Specification</div>
                                <div id="rfqModalUnit">—</div>
                            </div>

                            <div class="col-md-6" id="rfqModalDeliveryWrap" style="display:none;">
                                <div class="small text-muted">Delivery Location</div>
                                <div id="rfqModalDelivery">—</div>
                            </div>

                            <div class="col-md-6" id="rfqModalDurationWrap" style="display:none;">
                                <div class="small text-muted">Service Duration</div>
                                <div id="rfqModalDuration">—</div>
                            </div>

                            <div class="col-md-6" id="rfqModalExpertiseWrap" style="display:none;">
                                <div class="small text-muted">Required Expertise</div>
                                <div id="rfqModalExpertise">—</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="small text-muted">Description</div>
                            <div id="rfqModalDescription" class="mt-1"></div>
                        </div>

                        <div class="mb-3" id="rfqModalServiceScopeWrap" style="display:none;">
                            <div class="small text-muted">Service Scope</div>
                            <div id="rfqModalServiceScope" class="mt-1"></div>
                        </div>

                        <div class="mb-3" id="rfqModalDocsWrap" style="display:none;">
                            <div class="small text-muted">Required Documents from Bidders</div>
                            <div id="rfqModalRequiredDocs" class="mt-1"></div>
                        </div>

                        <div class="mb-3" id="rfqModalTermsWrap" style="display:none;">
                            <div class="small text-muted">Terms &amp; Conditions</div>
                            <div id="rfqModalTerms" class="mt-1"></div>
                        </div>

                        <div class="mb-3">
                            <div class="small text-muted">Request Document</div>
                            <a
                                href="#"
                                id="rfqModalDocument"
                                target="_blank"
                                class="btn btn-sm btn-outline-success mt-1"
                                style="display:none;"
                            >
                                View Request Brief
                            </a>
                            <div id="rfqModalNoDocument" class="text-muted-soft">
                                No document uploaded
                            </div>
                        </div>

                    </div>

                    <!-- Vendor Bid Highlight -->
                    <div id="vendorBidSection" class="mt-4" style="display:none;">
                        <hr>

                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-person-check me-1"></i>
                            Your Bid
                        </h6>

                        <div class="p-3 rounded-2 bg-light border">

                            <div class="mb-2">
                                <span class="badge" id="vendorBidStatus"></span>
                                <span class="small text-muted ms-2" id="vendorBidDate"></span>
                            </div>

                            <div class="mb-3">
                                <div class="fw-semibold mb-1">SBO</div>
                                <div id="vendorBidBusiness" class="small text-muted"></div>
                            </div>

                            <div class="mb-3">
                                <div class="fw-semibold mb-1">Proposal Notes</div>
                                <div id="vendorBidProposal" class="small text-muted"></div>
                            </div>

                            <div>
                                <a
                                    href="#"
                                    target="_blank"
                                    id="vendorBidAttachment"
                                    class="btn btn-sm btn-outline-success"
                                >
                                    <i class="bi bi-download me-1"></i>
                                    Download Proposal Document
                                </a>
                            </div>

                        </div>
                    </div>


                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-outline-danger"
                            data-bs-dismiss="modal"
                        >
                            Close
                        </button>

                        <button
                            type="button"
                            class="btn btn-cta"
                            id="rfqSubmitBidBtn"
                        >
                            Submit Bid
                        </button>
                    </div>

                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {

                /*
                 * Where public uploads live. These links were built as
                 * `/storage/${path}`, and live nginx returns 403 for /storage/*
                 * before Laravel ever sees the request — so every RFQ document
                 * and bid attachment was a dead link in production while working
                 * fine locally.
                 *
                 * The base comes from the public disk itself rather than being
                 * written out again here, so it follows the config instead of
                 * drifting from it a second time.
                 */
                const UPLOAD_BASE = @json(rtrim(\Illuminate\Support\Facades\Storage::disk('public')->url(''), '/'));

                const uploadUrl = (path) =>
                    `${UPLOAD_BASE}/${String(path).replace(/^\/+/, '')}`;

                const modal = document.getElementById('rfqDetailsModal');
                const submitBidBtn = document.getElementById('rfqSubmitBidBtn');

                modal.addEventListener('show.bs.modal', function (event) {

                    const button = event.relatedTarget;
                    if (!button) return;

                    const rfq = JSON.parse(button.getAttribute('data-rfq'));
                    const deadlineEl = document.getElementById('rfqModalDeadline');

                    const requestTypeLabels = {
                        product: 'Product',
                        service: 'Service',
                        product_service: 'Product & Service'
                    };
                    const requestType = rfq.request_type ?? 'product';

                    /* ---------------------------------------------
                    * Basic request details
                    * ------------------------------------------- */
                    document.getElementById('rfqModalTitle').textContent = rfq.title;
                    document.getElementById('rfqModalOrg').textContent = rfq.sbo?.name ?? '—';
                    document.getElementById('rfqModalCategory').textContent = rfq.category?.name ?? '—';
                    document.getElementById('rfqModalRequestType').textContent =
                        requestTypeLabels[requestType] ?? 'Product';

                    if (rfq.order_deadline) {
                        const deadlineDate = new Date(rfq.order_deadline);
                        deadlineEl.textContent = deadlineDate.toLocaleDateString(undefined, {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });
                    } else {
                        deadlineEl.textContent = 'Open';
                    }

                    document.getElementById('rfqModalDescription').textContent =
                        rfq.description ?? '';

                    /* ---------------------------------------------
                    * Budget / product / service / required docs / terms
                    * ------------------------------------------- */
                    const toggleField = (wrapId, valueId, value, formatter) => {
                        const wrap = document.getElementById(wrapId);
                        const el = document.getElementById(valueId);
                        if (value !== null && value !== undefined && value !== '') {
                            el.textContent = formatter ? formatter(value) : value;
                            wrap.style.display = '';
                        } else {
                            wrap.style.display = 'none';
                        }
                    };

                    toggleField('rfqModalBudgetWrap', 'rfqModalBudget', rfq.budget,
                        (v) => Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 }));
                    toggleField('rfqModalQuantityWrap', 'rfqModalQuantity', rfq.quantity);
                    toggleField('rfqModalUnitWrap', 'rfqModalUnit', rfq.unit_specification);
                    toggleField('rfqModalDeliveryWrap', 'rfqModalDelivery', rfq.delivery_location);
                    toggleField('rfqModalServiceScopeWrap', 'rfqModalServiceScope', rfq.service_scope);
                    toggleField('rfqModalDurationWrap', 'rfqModalDuration', rfq.service_duration);
                    toggleField('rfqModalExpertiseWrap', 'rfqModalExpertise', rfq.required_expertise);
                    toggleField('rfqModalDocsWrap', 'rfqModalRequiredDocs', rfq.required_documents);
                    toggleField('rfqModalTermsWrap', 'rfqModalTerms', rfq.terms);

                    /* ---------------------------------------------
                    * Request document
                    * ------------------------------------------- */
                    const docBtn = document.getElementById('rfqModalDocument');
                    const noDoc = document.getElementById('rfqModalNoDocument');

                    if (rfq.document_path) {
                        docBtn.href = uploadUrl(rfq.document_path);
                        docBtn.style.display = 'inline-block';
                        noDoc.style.display = 'none';
                    } else {
                        docBtn.style.display = 'none';
                        noDoc.style.display = 'block';
                    }

                    /* ---------------------------------------------
                    * Submit bid button setup
                    * ------------------------------------------- */
                    submitBidBtn.disabled = false;
                    submitBidBtn.innerHTML = 'Submit Bid';
                    submitBidBtn.setAttribute('data-rfq-id', rfq.id);

                    /* ---------------------------------------------
                    * Vendor bid highlighting (ONLY if exists)
                    * ------------------------------------------- */
                    const vendorBidSection = document.getElementById('vendorBidSection');

                    if (rfq.user_bid) {

                        vendorBidSection.style.display = 'block';

                        document.getElementById('vendorBidProposal').textContent =
                            rfq.user_bid.proposal;

                        document.getElementById('vendorBidBusiness').textContent =
                            rfq.user_bid.business_name;

                        const statusBadge = document.getElementById('vendorBidStatus');
                        statusBadge.textContent = rfq.user_bid.status.toUpperCase();

                        statusBadge.className =
                            'badge ' +
                            (rfq.user_bid.status === 'accepted'
                                ? 'bg-success'
                                : rfq.user_bid.status === 'rejected'
                                    ? 'bg-danger'
                                    : 'bg-warning text-dark');

                        // Submission / acceptance date
                        const dateEl = document.getElementById('vendorBidAcceptedDate');

                        if (rfq.user_bid.status === 'accepted' && rfq.user_bid.accepted_at) {
                            dateEl.textContent =
                                'Accepted on ' +
                                new Date(rfq.user_bid.accepted_at).toLocaleDateString(undefined, {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                });
                        } else {
                            dateEl.textContent =
                                'Submitted on ' +
                                new Date(rfq.user_bid.submitted_at).toLocaleDateString(undefined, {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                });
                        }

                        const attachmentLink =
                            document.getElementById('vendorBidAttachment');
                        attachmentLink.href = uploadUrl(
                            rfq.user_bid.attachment_path,
                        );

                        /* 🔒 Always disable Submit Bid if user_bid exists */
                        submitBidBtn.disabled = true;
                        submitBidBtn.style.display = 'none'; // 👈 hide completely

                    } else {
                        vendorBidSection.style.display = 'none';
                        submitBidBtn.style.display = 'inline-block';
                    }

                });

            });
        </script>


        <script>
            document.addEventListener('livewire:initialized', () => {

                const submitBidBtn = document.getElementById('rfqSubmitBidBtn');
                const rfqModalEl = document.getElementById('rfqDetailsModal');

                submitBidBtn.addEventListener('click', function () {
                    const rfqId = submitBidBtn.getAttribute('data-rfq-id');

                    if (!rfqId) {
                        console.error('RFQ ID not found');
                        return;
                    }

                    // ✅ 1. Close RFQ modal FIRST
                    const rfqModalInstance = bootstrap.Modal.getInstance(rfqModalEl);
                    if (rfqModalInstance) {
                        rfqModalInstance.hide();
                    }

                    // ✅ 2. Dispatch Livewire event AFTER close
                    Livewire.dispatch('open-submit-bid', {
                        rfqId: parseInt(rfqId)
                    });
                });

            });
        </script>



    @endsection
