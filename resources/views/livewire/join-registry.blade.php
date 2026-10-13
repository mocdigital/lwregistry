<div class="join-registry-page">

    {{-- ================= HERO ================= --}}
    <section class="join-registry-hero text-white">
        <div class="container">
            <div class="row justify-content-center text-center">
                <div class="col-lg-8">
                    <span class="join-registry-eyebrow">Loveworld Registry</span>
                    <h1 class="join-registry-title">Join the Registry</h1>
                    <p class="join-registry-subtitle mb-0">
                        Register your business to join the Loveworld commerce ecosystem. It takes a few
                        minutes — you can complete each section as you go.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ================= FORM ================= --}}
    <section class="join-registry-body">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-lg-9 col-xl-8">

                    <div class="join-registry-card premium-auth-form">
                        @include('partials.join-registry-form')
                    </div>

                    <p class="text-center mt-4 mb-0 join-registry-footnote">
                        Already have an account?
                        <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal">Log in instead</a>
                    </p>

                </div>
            </div>
        </div>
    </section>

    <style>
        .join-registry-page {
            background: var(--bs-body-bg, #f6f8fb);
        }

        .join-registry-hero {
            background: linear-gradient(135deg, #0b2545 0%, #12395f 55%, #1ba9de 100%);
            padding: clamp(2.5rem, 7vw, 4.5rem) 0 clamp(3.5rem, 9vw, 6rem);
        }

        .join-registry-eyebrow {
            display: inline-block;
            margin-bottom: .75rem;
            padding: .3rem .9rem;
            border: 1px solid rgba(255, 255, 255, .35);
            border-radius: 999px;
            font-size: .75rem;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .join-registry-title {
            font-weight: 700;
            font-size: clamp(1.85rem, 5vw, 3rem);
            margin-bottom: .75rem;
        }

        .join-registry-subtitle {
            font-size: clamp(.95rem, 2.2vw, 1.1rem);
            opacity: .9;
            max-width: 34rem;
            margin-inline: auto;
        }

        .join-registry-body {
            /* Pull the card up over the hero for a layered look. */
            margin-top: clamp(-4rem, -7vw, -2.5rem);
            padding-bottom: clamp(3rem, 8vw, 5rem);
        }

        .join-registry-card {
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 1.5rem 3rem rgba(11, 37, 69, .12);
            padding: clamp(1.25rem, 4vw, 2.5rem);
        }

        .join-registry-footnote {
            font-size: .925rem;
        }

        /* The form partial is shared with the modal, where inputs sit on a dark
           panel. On this light card, restore readable field styling. */
        .join-registry-card .form-control,
        .join-registry-card .form-select {
            padding: .7rem .9rem;
        }

        /* The shared partial's Next/Submit buttons rely on modal-scoped
           `.auth-right .login-btn` styling; restyle them for this page. */
        .join-registry-card .login-btn {
            background: var(--light-color, #0f7c92);
            border: none;
            color: #fff;
            font-weight: 700;
            min-height: 46px;
            padding-inline: 1.75rem;
        }

        .join-registry-card .login-btn:hover,
        .join-registry-card .login-btn:focus {
            background: var(--color-primary-hover, #0b5d6e);
            color: #fff;
        }

        /* Comfortable tap targets on small screens. */
        @media (max-width: 575.98px) {
            .join-registry-card .form-control,
            .join-registry-card .form-select {
                font-size: 1rem; /* prevents iOS zoom-on-focus */
            }

            /* Scoped to the action bar: a bare `.btn` selector here would also
               stretch the absolutely-positioned password toggles over their
               inputs and make them untypable. */
            .join-registry-card .premium-action-bar .btn {
                min-height: 48px;
                font-size: 1rem;
            }
        }
    </style>
</div>
