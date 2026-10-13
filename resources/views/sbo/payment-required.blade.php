@extends('layouts.app')

@section('title', 'Complete Payment - Loveworld Business Registry')

@section('content')
    <div class="container auth-dashboard-shell">
        <section class="payment-required-shell">
            <div class="payment-required-hero">
                <div class="payment-required-pulse" aria-hidden="true">
                    <i class="bi bi-credit-card-2-front"></i>
                </div>

                <div class="payment-required-copy">
                    <span class="payment-required-kicker">Workspace locked</span>
                    <h1>Complete Payment</h1>
                    <p>
                        Your Listings, Procurement Requests, bidding workspace, and related tools will unlock after
                        your SBO registration payment is approved by the registry team.
                    </p>

                    @if($targetSbo)
                        <div class="payment-required-target">
                            <span class="text-muted">Current SBO</span>
                            <strong>{{ $targetSbo->name }}</strong>
                        </div>
                    @endif

                    <div class="payment-required-actions">
                        <a href="{{ $progressUrl }}" class="btn btn-primary rounded-pill" wire:navigate>
                            <i class="bi bi-arrow-right-circle me-1"></i> Go to Payment Step
                        </a>
                        <a href="{{ $applicationsUrl }}" class="btn btn-outline-secondary rounded-pill" wire:navigate>
                            View Application Progress
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection

@push('styles')
    <style>
        .payment-required-shell {
            min-height: calc(100vh - 180px);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .payment-required-hero {
            width: min(100%, 920px);
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 2rem;
            align-items: center;
            padding: 2.5rem;
            border: 1px solid rgba(20, 42, 59, 0.08);
            background: #fff;
            box-shadow: 0 24px 70px rgba(20, 42, 59, 0.08);
        }

        .payment-required-pulse {
            width: 108px;
            height: 108px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            color: #fff;
            background: var(--light-color);
            box-shadow: 0 0 0 0 rgba(29, 191, 115, 0.35);
            animation: paymentPulse 1.8s ease-in-out infinite;
        }

        .payment-required-pulse i {
            font-size: 2.75rem;
        }

        .payment-required-kicker {
            display: inline-flex;
            color: var(--text-blue);
            font-weight: 700;
            font-size: 0.86rem;
            text-transform: uppercase;
            letter-spacing: 0;
            margin-bottom: 0.65rem;
        }

        .payment-required-copy h1 {
            font-size: clamp(2rem, 4vw, 3.5rem);
            line-height: 1.05;
            margin-bottom: 1rem;
            color: var(--dark-color);
        }

        .payment-required-copy p {
            max-width: 640px;
            color: #52616b;
            font-size: 1.05rem;
            margin-bottom: 1.25rem;
        }

        .payment-required-target {
            display: inline-flex;
            flex-direction: column;
            gap: 0.2rem;
            padding: 0.75rem 1rem;
            border: 1px solid rgba(20, 42, 59, 0.08);
            margin-bottom: 1.5rem;
        }

        .payment-required-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        @keyframes paymentPulse {
            0% { box-shadow: 0 0 0 0 rgba(29, 191, 115, 0.35); transform: scale(1); }
            70% { box-shadow: 0 0 0 18px rgba(29, 191, 115, 0); transform: scale(1.03); }
            100% { box-shadow: 0 0 0 0 rgba(29, 191, 115, 0); transform: scale(1); }
        }

        @media (max-width: 767.98px) {
            .payment-required-hero {
                grid-template-columns: 1fr;
                padding: 1.5rem;
            }

            .payment-required-pulse {
                width: 86px;
                height: 86px;
            }
        }
    </style>
@endpush
