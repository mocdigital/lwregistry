@extends('layouts.app')

@section('title', 'Profile - Loveworld Business Registry')

@push('styles')
    <style>
        body { background:#f6f8fa; color: var(--dark-color); }

        .navbar-search {
            display: flex !important;
        }

        .profile-page-shell {
            padding-top: 120px !important;
            padding-bottom: 48px;
        }

        .profile-page-header {
            display:flex;
            align-items:flex-end;
            justify-content:space-between;
            gap:20px;
            margin-bottom:26px;
            padding-top:24px;
            padding-bottom:20px;
        }

        .profile-page-title {
            font-size:32px;
            font-weight:800;
            color:var(--dark-color);
            margin-bottom:6px;
            font-family:'YLocal Book', sans-serif;
        }

        .profile-page-subtitle {
            font-size:15px;
            color:var(--muted);
            line-height:1.5;
            margin-top:6px;
        }

        .account-card-enhanced {
            background:var(--bg-light-gray);
            border-radius:0;
            padding:24px;
            box-shadow:0 18px 45px rgba(20,42,59,0.06);
        }

        .ace-header {
            display:flex;
            gap:16px;
            align-items:center;
        }

        .ace-avatar {
            width:78px;
            height:78px;
            border-radius:0;
            object-fit:cover;
            border:2px solid rgba(20,42,59,0.04);
            box-shadow:0 6px 18px rgba(20,42,59,0.04);
            filter:hue-rotate(-80deg) saturate(1.2);
            display:inline-flex;
            align-items:center;
            justify-content:center;
            background:rgba(20,42,59,0.08);
            color:var(--dark-color);
            font-weight:800;
        }

        .ace-name {
            font-size:20px;
            font-weight:800;
            color:var(--dark-color);
            margin-bottom:2px;
            font-family:'YLocal Book', sans-serif;
        }

        .ace-email {
            font-size:14px;
            color:var(--muted);
            font-family:'YLocal Book', sans-serif;
        }

        .ace-verified {
            display:inline-block;
            margin-top:10px;
            background:rgba(29,191,115,0.08);
            color:var(--light-color);
            padding:.35rem .65rem;
            border-radius:0;
            font-weight:700;
            font-size:13px;
            border:1px solid rgba(29,191,115,0.12);
            font-family:'YLocal Book', sans-serif;
        }

        .ace-stats {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:18px;
            margin-top:14px;
        }

        .ace-stat {
            padding:18px;
            background:#fff;
            border:1px solid rgba(20,42,59,0.04);
        }

        .ace-stat .num {
            font-size:24px;
            font-weight:800;
            color:var(--dark-color);
            font-family:'YLocal Book', sans-serif;
        }

        .ace-stat .label {
            font-size:13px;
            color:var(--muted);
            margin-top:6px;
            font-family:'YLocal Book', sans-serif;
        }

        .ace-actions {
            margin-top:18px;
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
        }

        .ace-chat {
            display:inline-flex;
            gap:8px;
            align-items:center;
            background:rgba(29,191,115,0.08);
            color:var(--light-color);
            padding:.55rem .75rem;
            border-radius:0;
            font-weight:700;
            border:1px solid rgba(29,191,115,0.12);
            white-space:nowrap;
            text-decoration:none;
        }

        .ace-chat:hover,
        .ace-chat:focus {
            background:rgba(29,191,115,0.15);
            color:var(--light-color);
            text-decoration:none;
            outline:3px solid rgba(29,191,115,0.12);
            outline-offset:2px;
        }

        .profile-secondary-card {
            background:#fff;
            border:1px solid rgba(20,42,59,0.04);
            padding:22px;
            box-shadow:0 10px 28px rgba(20,42,59,0.04);
        }

        .profile-seal-card {
            background:linear-gradient(135deg, #fffdf7 0%, #f6fbff 52%, #f8fff9 100%);
            border:1px solid rgba(154,101,36,0.16);
            box-shadow:0 18px 45px rgba(20,42,59,0.07);
            overflow:hidden;
            position:relative;
        }

        .profile-seal-card::before {
            background:radial-gradient(circle, rgba(227,177,88,0.22), transparent 62%);
            content:"";
            height:180px;
            position:absolute;
            right:-70px;
            top:-80px;
            width:180px;
        }

        .profile-seal-item {
            align-items:center;
            background:rgba(255,255,255,0.7);
            border:1px solid rgba(15,23,42,0.06);
            display:flex;
            gap:16px;
            padding:14px;
            position:relative;
        }

        .profile-seal-preview {
            background:rgba(255,255,255,0.55);
            border-radius:50%;
            height:92px;
            object-fit:contain;
            width:92px;
        }

        .profile-seal-number {
            color:var(--dark-color);
            font-size:18px;
            font-weight:800;
            letter-spacing:0;
        }

        @media (max-width: 767.98px) {
            .profile-page-shell {
                padding-top: 100px !important;
            }

            .profile-page-header {
                display:block;
                padding-top:0;
            }

            .account-card-enhanced,
            .profile-secondary-card {
                padding:16px;
            }

            .profile-seal-item {
                align-items:flex-start;
                flex-direction:column;
            }

            .ace-avatar {
                width:64px;
                height:64px;
            }

            .ace-stats {
                grid-template-columns:1fr;
                gap:12px;
            }
        }
    </style>
@endpush

@section('content')
    <div class="profile-page-shell">
        <div class="container">
            <x-ui.page-header
                title="Profile"
                description="Manage your account details, security, and profile support options."
                :breadcrumbs="[
                    ['label' => 'Account', 'href' => route('dashboard'), 'wireNavigate' => true],
                    ['label' => 'Profile', 'current' => true],
                ]"
            >
                <x-slot:actions>
                    <a href="{{ route('profile.edit') }}" class="btn btn-outline-secondary rounded-pill" wire:navigate>
                        <i class="bi bi-sliders me-1" aria-hidden="true"></i> Account settings
                    </a>
                </x-slot:actions>
            </x-ui.page-header>

            <div class="row g-4">
                <div class="col-lg-8">
                    <section class="account-card-enhanced">
                        <div class="ace-header">
                            <x-user-avatar :user="$user" class="ace-avatar" />
                            <div style="flex:1;">
                                <div class="ace-name">{{ $user->name }}</div>
                                <div class="ace-email">{{ $user->email }}</div>
                                @if(array_key_exists('kc_handle', $user->getAttributes()) && $user->kc_handle)
                                    <div class="ace-email">{{ $user->kc_handle }}</div>
                                @endif
                                <div>
                                    @if($user->email_verified_at)
                                        <span class="ace-verified">Verified</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="ace-stats">
                            <div class="ace-stat">
                                <div class="num">{{ $totalListings }}</div>
                                <div class="label">Listings</div>
                            </div>

                            <div class="ace-stat">
                                <div class="num">{{ number_format($totalViews) }}</div>
                                <div class="label">Views (30d)</div>
                            </div>
                        </div>

                        <div class="ace-actions">
                            <a href="{{ route('support.index') }}" class="ace-chat">
                                <i class="bi bi-chat-dots me-1"></i> Support
                            </a>

                            <button type="button" class="ace-chat"
                                onclick="
                                if (window.Livewire) {
                                    Livewire.dispatch('open-profile-modal');
                                }"
                            >
                                <i class="bi bi-person-lines-fill me-1"></i> Edit profile
                            </button>

                            <button type="button" class="ace-chat"
                                onclick="
                                if (window.Livewire) {
                                    Livewire.dispatch('open-security-modal');
                                }"
                            >
                                <i class="bi bi-shield-lock-fill me-1"></i> Security
                            </button>
                        </div>
                    </section>

                    <section class="account-card-enhanced profile-seal-card mt-4">
                        <div class="d-flex justify-content-between gap-3 align-items-start flex-wrap mb-3">
                            <div>
                                <h2 class="h5 mb-1">Digital SBO Seal</h2>
                                <p class="text-muted mb-0">Download the personalized seal issued after full validation.</p>
                            </div>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">Fully validated badge</span>
                        </div>

                        @forelse($sboSeals as $sealedSbo)
                            <div class="profile-seal-item mb-3">
                                <img src="{{ $sealedSbo->seal->url() }}" alt="{{ $sealedSbo->name }} digital SBO seal" class="profile-seal-preview" loading="lazy" decoding="async">
                                <div class="flex-grow-1">
                                    <div class="profile-seal-number">{{ $sealedSbo->seal->issued_number }}</div>
                                    <div class="fw-semibold">{{ $sealedSbo->name }}</div>
                                    <div class="small text-muted">
                                        Issued {{ $sealedSbo->seal->issued_at?->format('M j, Y') ?? 'after validation' }}
                                        @if($sealedSbo->moc_registration_number)
                                            · MOC {{ $sealedSbo->moc_registration_number }}
                                        @endif
                                    </div>
                                </div>
                                <a href="{{ route('sbo.seals.download', $sealedSbo->seal) }}" class="btn btn-primary rounded-pill" target="_blank">
                                    <i class="bi bi-download me-1"></i> Download seal
                                </a>
                            </div>
                        @empty
                            <div class="profile-seal-item">
                                <div class="profile-seal-preview d-flex align-items-center justify-content-center">
                                    <i class="bi bi-patch-check fs-2 text-muted"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">No seal issued yet</div>
                                    <div class="small text-muted">Your seal will appear here once your SBO is approved registered, document compliant, and paid required fees.</div>
                                </div>
                            </div>
                        @endforelse
                    </section>
                </div>

                <div class="col-lg-4">
                    <aside class="profile-secondary-card">
                        <h2 class="h5 mb-2">Business Listings</h2>
                        <p class="text-muted mb-3">Your listings now live in their own workspace with verified, unverified, favorites, requests, and bid activity.</p>
                        <a href="{{ route('dashboard') }}" class="ace-chat">
                            <i class="bi bi-briefcase me-1"></i> Go to listings
                        </a>
                    </aside>
                </div>
            </div>
        </div>
    </div>

    @auth
        @livewire('profile-modal')
        @livewire('security-modal')
    @endauth
@endsection
