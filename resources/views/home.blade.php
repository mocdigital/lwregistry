@extends('layouts.app')

@section('title', 'Loveworld Business Registry - Global Visibility Starts Here')

@section('content')
<div class="public-home">
    <section class="public-home__hero" id="hero" aria-labelledby="home-title">
        <div class="container public-home__hero-grid">
            <div class="public-home__hero-copy">
                <span class="public-home__eyebrow">Loveworld Business Registry</span>
                <h1 id="home-title">Global visibility starts here.</h1>
                <p class="public-home__hero-description">
                    Find trusted Spirit-led businesses, connect with the Loveworld economy, and make your next business relationship easier to start.
                </p>

                <div class="public-home__search" id="heroSearchWrapper">
                    @livewire('business-search', ['heroMode' => true])
                </div>

                <div class="public-home__hero-actions">
                    <a href="{{ route('businesses.index') }}" class="btn btn-primary rounded-pill" wire:navigate>
                        Browse the directory <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                    </a>
                    <a href="{{ route('join') }}" class="btn btn-outline-light rounded-pill" wire:navigate>Join the Registry</a>
                </div>
            </div>

            <aside class="public-home__hero-note" aria-label="Registry promise">
                <span class="public-home__hero-note-icon" aria-hidden="true"><i class="bi bi-patch-check"></i></span>
                <div>
                    <strong>Built for trust and discovery.</strong>
                    <p>Clear business profiles, meaningful connections, and a calmer way to navigate opportunities.</p>
                </div>
            </aside>
        </div>
    </section>

    <div class="container">
        <x-ad-slot name="home_hero" />
    </div>

    <section class="public-home__section public-home__categories" id="businesses" aria-labelledby="home-categories-title">
        <div class="container">
            <div class="public-home__section-heading">
                <div>
                    <span class="public-home__eyebrow">Explore the network</span>
                    <h2 id="home-categories-title">Discover SBOs by category.</h2>
                </div>
                <a href="{{ route('businesses.index') }}" class="public-home__text-link" wire:navigate>View all businesses <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
            </div>

            <div class="public-home__category-grid">
                @foreach($categories as $category)
                    <a href="{{ route('businesses.category', $category) }}" class="public-home__category" wire:navigate>
                        <span class="public-home__category-icon" aria-hidden="true"><i class="bi bi-grid-1x2"></i></span>
                        <span class="public-home__category-content">
                            <strong>{{ $category->name }}</strong>
                            <span>{{ $category->business_count }} {{ $category->business_count === 1 ? 'SBO' : 'SBOs' }}</span>
                        </span>
                        <i class="bi bi-arrow-up-right public-home__category-arrow" aria-hidden="true"></i>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <div class="container">
        <x-ad-slot name="home_mid" />
    </div>

    <section class="public-home__section public-home__journey" id="sbos" aria-labelledby="home-journey-title">
        <div class="container">
            <div class="public-home__section-heading public-home__section-heading--centered">
                <div>
                    <span class="public-home__eyebrow">A clear path forward</span>
                    <h2 id="home-journey-title">How the Registry works.</h2>
                    <p>Everything is designed to help you move from discovery to a trusted business relationship with confidence.</p>
                </div>
            </div>

            <ol class="public-home__steps">
                <li class="public-home__step">
                    <span class="public-home__step-number">01</span>
                    <div><h3>Create an account</h3><p>Join as an SBO manager or business owner and set up your workspace.</p></div>
                </li>
                <li class="public-home__step">
                    <span class="public-home__step-number">02</span>
                    <div><h3>Build your profile</h3><p>Share the business details people need to understand and reach you.</p></div>
                </li>
                <li class="public-home__step">
                    <span class="public-home__step-number">03</span>
                    <div><h3>Connect with opportunity</h3><p>Discover businesses, collaborate, and take part in procurement opportunities.</p></div>
                </li>
            </ol>
        </div>
    </section>

    <section class="public-home__closing" id="about" aria-labelledby="home-closing-title">
        <div class="container public-home__closing-inner">
            <div>
                <span class="public-home__eyebrow">Ready when you are</span>
                <h2 id="home-closing-title">Make your next business connection count.</h2>
            </div>
            <a href="{{ route('businesses.index') }}" class="btn btn-light rounded-pill" wire:navigate>Explore the directory <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
        </div>
    </section>
</div>
@endsection
