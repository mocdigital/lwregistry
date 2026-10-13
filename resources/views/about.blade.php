@extends('layouts.info')

@section('title', 'About Loveworld Registry – Trusted Business Ecosystem')

@section('meta_description', 
    'Learn about Loveworld Registry, our mission, vision, members, registered businesses, and strategic business organizations.'
)
@section('og_title', 'About Loveworld Registry')
@section('og_description', 'Discover how Loveworld Registry connects members, businesses, and organizations to real opportunities.')

@section('content')

<div class="public-about">

{{-- ================= HERO ================= --}}
<section class="hero-tech hero-premium text-white">
    <div class="container hero-content" data-aos="fade-up">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <span class="hero-eyebrow mb-3 d-inline-block">
                    Loveworld Business Registry
                </span>

                <h1 class="hero-title mb-4">
                    Powering a Trusted<br>
                    Digital Business Economy
                </h1>

                <p class="hero-subtitle mb-4">
                    A centralized registry connecting Loveworld members,
                    registered businesses, and strategic organizations
                    to real opportunities across industries.
                </p>

                <div class="d-flex flex-wrap gap-3 mt-4">
                    <a href="{{ route('register') }}" class="btn btn-cta btn-lg">
                        Join the Registry
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>



{{-- ================= ABOUT ================= --}}
<section id="about" class="py-5 feature-section" >
    <div class="container">

        {{-- Intro --}}
        <div class="row mb-5 text-center" data-aos="fade-up">
            <div class="col-lg-9 mx-auto">
                <span class="feature-eyebrow mb-2 d-inline-block">
                    The Loveworld Ecosystem
                </span>

                <h2 class="fw-bold section-title display-6 mt-2">
                    Building a Trusted Digital Economy
                </h2>

                <p class="text-muted fs-5 mt-3">
                    Loveworld Registry is the digital backbone that connects members,
                    businesses, and Loveworld-owned organizations — enabling collaboration,
                    transparency, and access to real opportunities.
                </p>
            </div>
        </div>

        {{-- Mission & Vision --}}
        <div class="row g-4 justify-content-center">

            {{-- Mission --}}
            <div class="col-md-6">
                <div class="glass-card p-5 h-100 text-start" data-aos="fade-up" data-aos-delay="100">

                    <div class="d-flex align-items-center mb-4">
                        <div class="me-3 icon-wrap">
                            {{-- Mission SVG --}}
                            <svg width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6">
                                <circle cx="17" cy="17" r="14"/>
                                <path d="M17 9v7l5 3"/>
                            </svg>
                        </div>
                        <h4 class="fw-bold mb-0">Our Mission</h4>
                    </div>

                    <p class="text-muted fs-5">
                        To empower Loveworld members by providing a trusted,
                        technology-driven platform where businesses can grow,
                        collaborate, and access meaningful opportunities.
                    </p>
                </div>
            </div>

            {{-- Vision --}}
            <div class="col-md-6">
                <div class="glass-card p-5 h-100 text-start" data-aos="fade-up" data-aos-delay="200">

                    <div class="d-flex align-items-center mb-4">
                        <div class="me-3 icon-wrap">
                            {{-- Vision SVG --}}
                            <svg width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path d="M1 17s6-10 16-10 16 10 16 10-6 10-16 10S1 17 1 17z"/>
                                <circle cx="17" cy="17" r="4"/>
                            </svg>
                        </div>
                        <h4 class="fw-bold mb-0">Our Vision</h4>
                    </div>

                    <p class="text-muted fs-5">
                        To become the definitive business registry within Loveworld —
                        setting the standard for transparency, excellence, and
                        global economic impact.
                    </p>
                </div>
            </div>

        </div>
    </div>
</section>



{{-- ================= MEMBERS ================= --}}
<section id="members" class="py-5 bg-light feature-section">
    <div class="container">
        <div class="row align-items-center g-5">

            <div class="col-lg-6">
                <div class="image-frame" data-aos="fade-right">
                    <img
                        src="https://images.unsplash.com/photo-1521737604893-d14cc237f11d"
                        class="img-fluid rounded-4 shadow"
                        alt="Loveworld Members Collaboration"
                        loading="lazy"
                        decoding="async">
                </div>
            </div>

            <div class="col-lg-6">
                <div class="feature-card" data-aos="fade-up" data-aos-delay="150">
                    <span class="feature-eyebrow">Community</span>
                    <h2 class="fw-bold section-title display-6">
                        Loveworld Members
                    </h2>

                    <div class="feature-divider"></div>

                    <p class="text-muted">
                        Members are individuals within the Loveworld community who
                        register on the platform and can onboard multiple businesses
                        under a single trusted profile.
                    </p>

                    <ul class="tech-list text-muted mt-4">
                        <li>Manage multiple businesses</li>
                        <li>Discover verified Loveworld businesses</li>
                        <li>Collaborate within the ecosystem</li>
                    </ul>

                    <div class="mt-4 d-flex gap-3">
                        <a href="{{ route('register') }}" class="btn btn-cta">
                            Become a Member
                        </a>

                        <a href="#contact" class="btn btn-cta-outline">
                            Learn More
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

{{-- ================= BUSINESSES ================= --}}
<section id="businesses" class="py-5 feature-section">
    <div class="container">
        <div class="row align-items-center g-5 flex-lg-row-reverse">

            <div class="col-lg-6">
                <div class="image-frame" data-aos="fade-left">
                    <img
                        src="https://images.unsplash.com/photo-1556761175-5973dc0f32e7"
                        class="img-fluid rounded-4 shadow"
                        alt="Registered SBOs"
                        loading="lazy"
                        decoding="async">
                </div>
            </div>

            <div class="col-lg-6">
                <div class="feature-card" data-aos="fade-up" data-aos-delay="150">
                    <span class="feature-eyebrow">Marketplace</span>
                    <h2 class="fw-bold section-title display-6">
                        Registered SBOs
                    </h2>

                    <div class="feature-divider"></div>

                    <p class="text-muted">
                        Businesses are entities owned by Loveworld members and listed
                        in the registry to participate in procurement requests, contracts, and
                        partnerships with confidence.
                    </p>

                    <ul class="tech-list text-muted mt-4">
                        <li>Bid for procurement requests & contracts</li>
                        <li>Verified ownership & credibility</li>
                        <li>Access Loveworld SBO opportunities</li>
                    </ul>

                    <div class="mt-4 d-flex gap-3">
                        <a href="{{ route('register') }}" class="btn btn-cta">
                            Register SBO
                        </a>

                        <a href="{{ route('businesses.index') }}" class="btn btn-cta-outline">
                            Browse SBOs
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>



{{-- ================= SBOs ================= --}}
<section id="sbos" class="py-5 bg-light feature-section">
    <div class="container">
        <div class="row align-items-center g-5">

            <div class="col-lg-6">
                <div class="image-frame" data-aos="fade-right">
                    <img
                        src="https://images.unsplash.com/photo-1504384308090-c894fdcc538d"
                        class="img-fluid rounded-4 shadow"
                        alt="Strategic Business Organizations"
                        loading="lazy"
                        decoding="async">
                </div>
            </div>

            <div class="col-lg-6">
                <div class="feature-card" data-aos="fade-up" data-aos-delay="150">
                    <span class="feature-eyebrow">Enterprise</span>
                    <h2 class="fw-bold section-title display-6">
                        Loveworld SBOs
                    </h2>

                    <div class="feature-divider"></div>

                    <p class="text-muted">
                        Strategic Business Organizations (SBOs) are Loveworld-owned
                        enterprises across banking, fashion, media, manufacturing,
                        and other industries.
                    </p>

                    <ul class="tech-list text-muted mt-4">
                        <li>Publish procurement requests and projects</li>
                        <li>Connect with verified suppliers</li>
                        <li>Build trusted long-term networks</li>
                    </ul>

                    <div class="mt-4">
                        <a href="#contact" class="btn btn-cta">
                            Partner With Us
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>



{{-- ================= TEAM ================= --}}
<section id="team" class="py-5">
    <div class="container">
        <div class="row mb-4 text-center" data-aos="fade-up">
            <h2 class="fw-bold section-title display-6">Our Team</h2>
            <p class="text-muted">The people building and managing the Loveworld Registry</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="team-card text-center col-lg-3" data-aos="fade-up" data-aos-delay="100">
                <figure class="mb-3">
                    <img src="https://ltif.lwcommerce.org/images/team/stephanie-oforka.png" alt="Stephanie Oforka" class="mb-3 shadow" loading="lazy" decoding="async">
                </figure>
                <h6 class="fw-bold mb-0"> Stephanie Oforka </h6>
                <small class="text-muted"> Head of Commerce </small>
            </div>

            <div class="team-card text-center col-lg-3" data-aos="fade-up" data-aos-delay="200">
                <figure class="mb-3">
                    <img src="https://ltif.lwcommerce.org/images/team/yemisi-kudehinbu.jpg" alt="Yemisi Kudehinbu" class="mb-3 shadow" loading="lazy" decoding="async">
                </figure>
                <h6 class="fw-bold mb-0"> Yemisi Kudehinbu </h6>
                <small class="text-muted"> CEO, Loveworld Nation </small>
            </div>

            <div class="team-card text-center col-lg-3" data-aos="fade-up" data-aos-delay="300">
                <figure class="mb-3">
                    <img src="https://ltif.lwcommerce.org/images/team/olufemi-bakre.jpg" alt="Olufemi Bakre" class="mb-3 shadow" loading="lazy" decoding="async">
                </figure>
                <h6 class="fw-bold mb-0"> Olufemi Bakre </h6>
                <small class="text-muted"> MD, Parallex Bank </small>
            </div>

            <div class="team-card text-center col-lg-3" data-aos="fade-up" data-aos-delay="400">
                <figure class="mb-3">
                    <img src="https://ltif.lwcommerce.org/images/team/deola-phillips.jpg" alt="Deola Phillips" class="mb-3 shadow" loading="lazy" decoding="async">
                </figure>
                <h6 class="fw-bold mb-0">Deola Phillips</h6>
                <small class="text-muted"> Chairman, Parallex Bank </small>
            </div>
        </div>
    </div>
</section>



{{-- ================= CONTACT ================= --}}
<section id="contact" class="contact-tech text-white py-5">
    <div class="container position-relative">

        {{-- Header --}}
        <div class="row text-center mb-5">
            <div class="col-lg-8 mx-auto">
                <h2 class="fw-bold section-title display-6 mb-3 text-light">
                    Let’s Connect
                </h2>
                <p class="opacity-75 fs-5">
                    Get in touch with the Loveworld Business Registry
                </p>
            </div>
        </div>

        {{-- Contact Cards --}}
        <div class="row g-4 justify-content-center">

            {{-- Email --}}
            <div class="col-md-4">
                <div class="contact-card h-100 text-center">
                    <div class="icon-wrap mb-3">
                        {{-- Mail SVG --}}
                        <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.6">
                            <rect x="3" y="6" width="30" height="22" rx="4"/>
                            <path d="M3 10l15 10 15-10"/>
                        </svg>
                    </div>
                    <h6 class="fw-bold">Email</h6>
                    <p class="opacity-75 mb-0">registry@ lwcommerce.org</p>
                </div>
            </div>

            {{-- Phone --}}
            <div class="col-md-4">
                <div class="contact-card h-100 text-center">
                    <div class="icon-wrap mb-3">
                        {{-- Phone SVG --}}
                        <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path d="M22 3h-8a2 2 0 0 0-2 2v26a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/>
                            <circle cx="18" cy="28" r="1"/>
                        </svg>
                    </div>
                    <h6 class="fw-bold">Phone</h6>
                    <p class="opacity-75 mb-0">+234 000 000 0000</p>
                </div>
            </div>

            {{-- Address --}}
            <div class="col-md-4">
                <div class="contact-card h-100 text-center">
                    <div class="icon-wrap mb-3">
                        {{-- Map SVG --}}
                        <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path d="M21 10c0 4-6 10-6 10s-6-6-6-10a6 6 0 0 1 12 0z"/>
                            <circle cx="15" cy="10" r="2"/>
                        </svg>
                    </div>
                    <h6 class="fw-bold">Address</h6>
                    <p class="opacity-75 mb-0">
                        Oasis Centre, Loveworld City, Asese
                    </p>
                </div>
            </div>

        </div>
    </div>
</section>


</div>
@endsection
