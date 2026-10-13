@extends('layouts.app')

@section('title', 'Advertise with Us - Loveworld Business Registry')

@php
    use App\Models\AdCampaign;
    use App\Models\AdRateCard;

    $advertiseBuilderUrl = route('advertise') . '#advertise-campaign-builder';
    $slots = collect(config('ads.slots', []));
    $rateCards = AdRateCard::query()
        ->active()
        ->orderBy('slot')
        ->orderBy('sort_order')
        ->get()
        ->groupBy('slot');
    $advertiseUser = auth()->user();
    $advertiseName = $advertiseUser
        ? trim(($advertiseUser->firstname ?? '') . ' ' . ($advertiseUser->lastname ?? '')) ?: $advertiseUser->name
        : '';
    $campaigns = $advertiseUser
        ? AdCampaign::query()->where('user_id', $advertiseUser->id)->latest()->limit(8)->get()
        : collect();
@endphp

@section('content')
<section class="advertise-hero">
    <div class="container">
        <div class="advertise-hero__content">
            <span class="advertise-kicker">Loveworld Business Registry Ads</span>
            <h1>Advertise with Us</h1>
            <p>
                Choose a placement, upload your banner, pay securely with Espees, and submit
                your campaign for registry approval.
            </p>
            <a
                href="#advertise-campaign-builder"
                class="btn btn-primary advertise-hero__button"
                @guest
                    data-bs-toggle="modal"
                    data-bs-target="#advertisingAccessModal"
                @endguest
            >
                Apply for an Ad Spot
            </a>
            <a href="#advertise-inquiry" class="advertise-hero__secondary-link">
                Prefer to talk first? Send a general inquiry
            </a>
        </div>
    </div>
</section>

<section class="advertise-section">
    <div class="container">
        <div class="advertise-section__heading">
            <span class="advertise-kicker">Available placements</span>
            <h2>See where your banner appears</h2>
            <p>Each card highlights the placement in context and shows the required creative dimensions.</p>
        </div>

        <div class="row g-3">
            @foreach($slots as $key => $slot)
                @php
                    $mobile = $slot['mobile'] ?? null;
                @endphp
                <div class="col-md-6 col-xl-4">
                    <article class="ad-placement-card ad-placement-card--visual h-100">
                        <div class="ad-placement-card__diagram ad-placement-card__diagram--{{ $key }}" aria-hidden="true">
                            <div class="ad-diagram__bar"></div>
                            <div class="ad-diagram__body">
                                <div class="ad-diagram__content">
                                    <span></span><span></span><span></span>
                                </div>
                                <div class="ad-diagram__sidebar"></div>
                                <div class="ad-diagram__ad"></div>
                            </div>
                            <div class="ad-diagram__footer"></div>
                        </div>

                        <div>
                            <span class="ad-placement-card__group">{{ $slot['group'] ?? 'Website' }}</span>
                            <h3>{{ $slot['label'] }}</h3>
                        </div>
                        <div class="ad-placement-card__sizes">
                            <span>{{ $slot['width'] }}×{{ $slot['height'] }} px</span>
                            @if(is_array($mobile) && count($mobile) === 2)
                                <span>{{ $mobile[0] }}×{{ $mobile[1] }} mobile required</span>
                            @else
                                <span>Desktop creative only</span>
                            @endif
                        </div>
                        <div class="ad-placement-card__rates">
                            @forelse($rateCards->get($key, collect()) as $rate)
                                <span>{{ $rate->label }} · {{ number_format($rate->price) }} Espees</span>
                            @empty
                                <span>Rates coming soon</span>
                            @endforelse
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="advertise-inquiry" id="advertise-campaign-builder">
    <div class="container">
        <div class="advertise-inquiry__panel">
            <div class="advertise-inquiry__intro">
                <span class="advertise-kicker">Self-service campaign builder</span>
                <h2>Create and pay for your ad campaign.</h2>
                <p>Upload the exact creative size before payment. Paid campaigns are reviewed by admins before going live.</p>
            </div>

            @if (session('advertising_campaign_success'))
                <div class="alert alert-success py-2">{{ session('advertising_campaign_success') }}</div>
            @endif
            @if (session('advertising_campaign_error'))
                <div class="alert alert-danger py-2">{{ session('advertising_campaign_error') }}</div>
            @endif
            @if (session('advertising_auth_message'))
                <div class="alert alert-info py-2">{{ session('advertising_auth_message') }}</div>
            @endif

            @guest
                <div class="advertise-inquiry__locked" data-advertise-auth-required>
                    <div class="d-flex gap-3 align-items-start">
                        <div class="advertise-inquiry__lock-icon" aria-hidden="true">
                            <i class="bi bi-megaphone"></i>
                        </div>
                        <div>
                            <h3 class="h5 mb-2">Register or log in to buy an ad placement.</h3>
                            <p class="mb-3">
                                You can review ad placements now, but campaign submission and Espees payment are available only to registered users.
                                Payment is not required to send a general advertising inquiry.
                            </p>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="{{ route('join') }}" class="btn btn-primary" wire:navigate>
                                    Register your Business
                                </a>
                                <button type="button" class="btn btn-outline-primary" data-auth-redirect="{{ $advertiseBuilderUrl }}" data-bs-toggle="modal" data-bs-target="#loginModal">
                                    Log in
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="premium-campaign-shell">
                <form action="{{ route('advertise.campaigns.store') }}" method="POST" enctype="multipart/form-data" class="advertise-campaign-form premium-flow-card" data-ui-form>
                    @csrf

                    <div class="row g-3 premium-flow-card__body">
                        <div class="col-md-6">
                            <label for="campaign-slot" class="form-label">Placement</label>
                            <select id="campaign-slot" name="slot" class="form-select @error('slot') is-invalid @enderror" data-form-help="Choose the page position where your advert should appear. This controls the exact desktop and mobile banner dimensions." required>
                                <option value="">Choose a placement</option>
                                @foreach($slots as $key => $slot)
                                    @php
                                        $mobile = $slot['mobile'] ?? null;
                                    @endphp
                                    <option
                                        value="{{ $key }}"
                                        data-slot-label="{{ $slot['label'] }}"
                                        data-desktop-width="{{ $slot['width'] }}"
                                        data-desktop-height="{{ $slot['height'] }}"
                                        @if(is_array($mobile) && count($mobile) === 2)
                                            data-mobile-width="{{ $mobile[0] }}"
                                            data-mobile-height="{{ $mobile[1] }}"
                                        @endif
                                        @selected(old('slot') === $key)
                                    >
                                        {{ $slot['label'] }} · {{ $slot['width'] }}×{{ $slot['height'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('slot') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="campaign-rate" class="form-label">Duration package</label>
                            <select id="campaign-rate" name="ad_rate_card_id" class="form-select @error('ad_rate_card_id') is-invalid @enderror" data-form-help="Choose the campaign duration and price package for the selected placement. The price is locked when the campaign is created." required>
                                <option value="">Choose package</option>
                                @foreach($slots as $key => $slot)
                                    @foreach($rateCards->get($key, collect()) as $rate)
                                        <option
                                            value="{{ $rate->id }}"
                                            data-slot="{{ $key }}"
                                            data-slot-label="{{ $slot['label'] }}"
                                            data-rate-label="{{ $rate->label }}"
                                            data-price="{{ $rate->price }}"
                                            data-currency="Espees"
                                            @selected((string) old('ad_rate_card_id') === (string) $rate->id)
                                        >
                                            {{ $slot['label'] }} · {{ $rate->label }} · {{ number_format($rate->price) }} Espees
                                        </option>
                                    @endforeach
                                @endforeach
                            </select>
                            @error('ad_rate_card_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="campaign-title" class="form-label">Campaign title</label>
                            <input id="campaign-title" type="text" name="title" value="{{ old('title') }}" class="form-control @error('title') is-invalid @enderror" data-form-help="Enter a short campaign name that admins can recognize during review." required>
                            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="campaign-start" class="form-label">Desired start date</label>
                            <input id="campaign-start" type="date" name="desired_start_date" value="{{ old('desired_start_date') }}" class="form-control @error('desired_start_date') is-invalid @enderror" data-form-help="Pick the date you would like the advert to start. The campaign will still wait for payment and admin approval first.">
                            @error('desired_start_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="campaign-cta-text" class="form-label">CTA text</label>
                            <input id="campaign-cta-text" type="text" name="cta_text" value="{{ old('cta_text', 'Learn More') }}" class="form-control @error('cta_text') is-invalid @enderror" data-form-help="Enter the short call-to-action users will see when they interact with the advert." required>
                            @error('cta_text') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="campaign-cta-url" class="form-label">CTA URL</label>
                            <input id="campaign-cta-url" type="url" name="cta_url" value="{{ old('cta_url') }}" class="form-control @error('cta_url') is-invalid @enderror" placeholder="https://…" data-form-help="Enter the full page link users should visit after clicking the advert." required>
                            @error('cta_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="campaign-desktop" class="form-label">Desktop banner</label>
                            <input id="campaign-desktop" type="file" name="desktop_creative" class="form-control @error('desktop_creative') is-invalid @enderror" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="campaign-desktop-help" data-form-help="Upload the desktop banner at the exact pixel dimensions shown below after choosing a placement." required>
                            <div id="campaign-desktop-help" class="form-text ad-dimension-help is-warning" data-ad-desktop-help>Select a placement to see the exact required desktop banner size.</div>
                            @error('desktop_creative') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="campaign-mobile" class="form-label">Mobile banner</label>
                            <input id="campaign-mobile" type="file" name="mobile_creative" class="form-control @error('mobile_creative') is-invalid @enderror" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="campaign-mobile-help" data-form-help="Upload the mobile banner only when the selected placement shows a required mobile size below.">
                            <div id="campaign-mobile-help" class="form-text ad-dimension-help is-warning" data-ad-mobile-help>Select a placement to see whether a mobile banner is required.</div>
                            @error('mobile_creative') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-12 premium-action-bar">
                            <span class="small text-muted">Payment starts after your creative passes this validation step.</span>
                            <button type="submit" class="btn btn-primary rounded-pill px-4">
                                Create Campaign
                            </button>
                        </div>
                    </div>
                </form>

                <aside class="premium-campaign-summary" data-ad-campaign-summary aria-live="polite">
                    <div class="premium-campaign-summary__body">
                        <div class="premium-flow-card__eyebrow">Campaign summary</div>
                        <div class="premium-campaign-summary__heading">Ready when your placement is selected.</div>
                        <p class="premium-campaign-summary__hint">
                            Choose a placement and duration package to preview the required creative sizes and locked campaign price.
                        </p>

                        <div class="premium-summary-list">
                            <div class="premium-summary-list__item">
                                <span>Placement</span>
                                <strong data-ad-summary-placement>Not selected</strong>
                            </div>
                            <div class="premium-summary-list__item">
                                <span>Desktop creative</span>
                                <strong data-ad-summary-desktop>Choose placement</strong>
                            </div>
                            <div class="premium-summary-list__item">
                                <span>Mobile creative</span>
                                <strong data-ad-summary-mobile>Choose placement</strong>
                            </div>
                            <div class="premium-summary-list__item">
                                <span>Package</span>
                                <strong data-ad-summary-rate>Not selected</strong>
                            </div>
                        </div>

                        <div class="premium-status-timeline">
                            <div class="premium-status-timeline__item is-current">
                                <span class="premium-status-timeline__dot"></span>
                                <span>Build campaign</span>
                            </div>
                            <div class="premium-status-timeline__item">
                                <span class="premium-status-timeline__dot"></span>
                                <span>Pay with Espees</span>
                            </div>
                            <div class="premium-status-timeline__item">
                                <span class="premium-status-timeline__dot"></span>
                                <span>Admin creative review</span>
                            </div>
                        </div>
                    </div>
                </aside>
                </div>

                @if($campaigns->isNotEmpty())
                    <div class="advertise-campaign-list mt-4">
                        <h3 class="h5">Your recent ad campaigns</h3>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Campaign</th>
                                        <th>Placement</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($campaigns as $campaign)
                                        <tr>
                                            <td>{{ $campaign->title }}</td>
                                            <td>{{ $campaign->slotConfig()['label'] ?? $campaign->slot }}</td>
                                            <td>{{ number_format($campaign->price) }} {{ $campaign->currency }}</td>
                                            <td><span class="badge bg-light text-dark border">{{ str_replace('_', ' ', $campaign->status) }}</span></td>
                                            <td class="text-end">
                                                @if(in_array($campaign->status, [AdCampaign::STATUS_PENDING_PAYMENT, AdCampaign::STATUS_PAYMENT_FAILED], true))
                                                    <form method="POST" action="{{ route('advertise.campaigns.pay', $campaign) }}" class="d-inline" data-ui-form>
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-primary rounded-pill">Pay with Espees</button>
                                                    </form>
                                                @else
                                                    <span class="small text-muted">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @endguest
        </div>
    </div>
</section>

<section class="advertise-inquiry" id="advertise-inquiry">
    <div class="container">
        <div class="advertise-inquiry__panel">
            <div class="advertise-inquiry__intro">
                <span class="advertise-kicker">Need help?</span>
                <h2>Send an advertising inquiry.</h2>
                <p>Use this form if you need help preparing your campaign or have a custom placement request.</p>
            </div>

            @if (session('advertising_inquiry_success'))
                <div class="alert alert-success py-2">{{ session('advertising_inquiry_success') }}</div>
            @endif

            @auth
                <form action="{{ route('advertise.inquiry') }}" method="POST" class="advertise-inquiry__form" data-ui-form>
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="advertise-name" class="form-label">Name</label>
                            <input id="advertise-name" type="text" name="name" value="{{ old('name', $advertiseName) }}" class="form-control @error('name') is-invalid @enderror" autocomplete="name" required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="advertise-kc-handle" class="form-label">KC Handle</label>
                            <input id="advertise-kc-handle" type="text" name="kc_handle" value="{{ old('kc_handle') }}" class="form-control @error('kc_handle') is-invalid @enderror" required>
                            @error('kc_handle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="advertise-email" class="form-label">Email</label>
                            <input id="advertise-email" type="email" name="email" value="{{ old('email', $advertiseUser->email) }}" class="form-control @error('email') is-invalid @enderror" autocomplete="email" required>
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="advertise-ad-location" class="form-label">Ad location</label>
                            <select id="advertise-ad-location" name="ad_location" class="form-select @error('ad_location') is-invalid @enderror" required>
                                <option value="">Choose a placement</option>
                                @foreach($slots as $key => $slot)
                                    <option value="{{ $key }}" @selected(old('ad_location') === $key)>
                                        {{ $slot['label'] }} ({{ $slot['width'] }}×{{ $slot['height'] }} px)
                                    </option>
                                @endforeach
                            </select>
                            @error('ad_location') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-12">
                            <label for="advertise-message" class="form-label">Message</label>
                            <textarea id="advertise-message" name="message" rows="4" class="form-control @error('message') is-invalid @enderror" required>{{ old('message') }}</textarea>
                            @error('message') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-12">
                            <x-turnstile />
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-outline-primary rounded-pill">Send Inquiry</button>
                        </div>
                    </div>
                </form>
            @else
                <div class="advertise-inquiry__locked" data-advertise-auth-required>
                    <div class="d-flex gap-3 align-items-start">
                        <div class="advertise-inquiry__lock-icon" aria-hidden="true">
                            <i class="bi bi-megaphone"></i>
                        </div>
                        <div>
                            <h3 class="h5 mb-2">Register or log in to send an advertising inquiry.</h3>
                            <p class="mb-3">You can review ad placements now, but inquiry submission is available only to registered users.</p>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="{{ route('join') }}" class="btn btn-primary" wire:navigate>Register your Business</a>
                                <button type="button" class="btn btn-outline-primary" data-auth-redirect="{{ $advertiseBuilderUrl }}" data-bs-toggle="modal" data-bs-target="#loginModal">Log in</button>
                            </div>
                        </div>
                    </div>
                </div>
            @endauth
        </div>
    </div>
</section>

@guest
<div class="modal fade" id="advertisingAccessModal" tabindex="-1" aria-labelledby="advertisingAccessModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="advertisingAccessModalLabel">Register to advertise</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">{{ session('advertising_auth_message', 'Please register your business or log in before creating an advertising campaign.') }}</p>
                <p class="mb-0 text-muted small">Payment is not required to send an advertising inquiry. Campaign payments are handled after login through the builder.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Not now</button>
                <button type="button" class="btn btn-outline-primary" data-auth-redirect="{{ $advertiseBuilderUrl }}" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#loginModal">Log in</button>
                <a href="{{ route('join') }}" class="btn btn-primary" data-bs-dismiss="modal">Register your Business</a>
            </div>
        </div>
    </div>
</div>
@endguest
@endsection

@push('styles')
<style>
    .advertise-inquiry__locked {
        background: #f8fbff;
        border: 1px solid rgba(13, 110, 253, 0.16);
        border-radius: 8px;
        padding: 1.25rem;
    }

    .advertise-hero__secondary-link {
        color: rgba(255, 255, 255, 0.86);
        display: inline-block;
        font-weight: 600;
        margin-left: 1rem;
        text-decoration: underline;
        text-underline-offset: 0.25rem;
    }

    .advertise-hero__secondary-link:focus,
    .advertise-hero__secondary-link:hover {
        color: #ffffff;
    }

    @media (max-width: 575.98px) {
        .advertise-hero__secondary-link {
            display: block;
            margin-left: 0;
            margin-top: 0.85rem;
        }
    }

    .advertise-inquiry__lock-icon {
        align-items: center;
        background: rgba(13, 110, 253, 0.1);
        border-radius: 999px;
        color: var(--bs-primary);
        display: inline-flex;
        flex: 0 0 auto;
        font-size: 1.25rem;
        height: 42px;
        justify-content: center;
        width: 42px;
    }

    .ad-placement-card--visual {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .ad-placement-card__diagram {
        background: #f3f7fb;
        border: 1px solid rgba(13, 110, 253, 0.12);
        border-radius: 8px;
        min-height: 150px;
        overflow: hidden;
        padding: 0.7rem;
    }

    .ad-diagram__bar,
    .ad-diagram__footer,
    .ad-diagram__content span,
    .ad-diagram__sidebar {
        background: #dce8f5;
        border-radius: 6px;
    }

    .ad-diagram__bar {
        height: 14px;
        margin-bottom: 0.6rem;
    }

    .ad-diagram__body {
        display: grid;
        grid-template-columns: 1fr 72px;
        gap: 0.6rem;
        min-height: 92px;
        position: relative;
    }

    .ad-diagram__content {
        display: grid;
        gap: 0.45rem;
    }

    .ad-diagram__content span {
        display: block;
        height: 24px;
    }

    .ad-diagram__sidebar {
        min-height: 92px;
    }

    .ad-diagram__footer {
        height: 16px;
        margin-top: 0.6rem;
    }

    .ad-diagram__ad {
        background: linear-gradient(135deg, #0d6efd, #14b8a6);
        border-radius: 6px;
        box-shadow: 0 10px 24px rgba(13, 110, 253, 0.28);
        position: absolute;
    }

    .ad-placement-card__diagram--home_hero .ad-diagram__ad {
        height: 32px;
        left: 0;
        right: 0;
        top: 0;
    }

    .ad-placement-card__diagram--home_mid .ad-diagram__ad,
    .ad-placement-card__diagram--directory_inline .ad-diagram__ad,
    .ad-placement-card__diagram--company_below_about .ad-diagram__ad,
    .ad-placement-card__diagram--dashboard_top .ad-diagram__ad {
        height: 20px;
        left: 0;
        right: 86px;
        top: 42px;
    }

    .ad-placement-card__diagram--directory_sidebar .ad-diagram__ad,
    .ad-placement-card__diagram--company_sidebar .ad-diagram__ad {
        bottom: 0;
        right: 0;
        top: 0;
        width: 72px;
    }

    .ad-placement-card__diagram--global_footer .ad-diagram__ad {
        bottom: -28px;
        height: 22px;
        left: 0;
        right: 0;
    }

    .ad-placement-card__rates {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
    }

    .ad-placement-card__rates span {
        background: #eef5ff;
        border: 1px solid rgba(13, 110, 253, 0.12);
        border-radius: 999px;
        color: #174ea6;
        font-size: 0.78rem;
        padding: 0.25rem 0.55rem;
    }

    .advertise-campaign-form {
        background: #ffffff;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 8px;
        padding: 0;
    }
</style>
@endpush

@guest
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const shouldOpenAccessModal = @js((bool) session('advertising_auth_required'));
    const accessModal = document.getElementById('advertisingAccessModal');

    if (shouldOpenAccessModal && accessModal && window.bootstrap) {
        new bootstrap.Modal(accessModal).show();
    }
});
</script>
@endpush
@endguest
