@extends('layouts.app')

@section('title', 'Give Feedback - Loveworld Business Registry')

@php
    use App\Models\FeedbackSubmission;

    $feedbackFormUrl = route('feedback') . '#feedback-form';
    $feedbackUser = auth()->user();
    $feedbackName = $feedbackUser
        ? trim(($feedbackUser->firstname ?? '') . ' ' . ($feedbackUser->lastname ?? '')) ?: $feedbackUser->name
        : '';
@endphp

@section('content')
<section class="advertise-hero feedback-hero">
    <div class="container">
        <div class="advertise-hero__content">
            <span class="advertise-kicker">Loveworld Registry Feedback</span>
            <h1>Give Feedback</h1>
            <p>
                Share technical issues, business suggestions, feature ideas, and anything else
                that can make the registry clearer, faster, and more useful.
            </p>
            <a
                href="#feedback-form"
                class="btn btn-primary advertise-hero__button"
                @guest
                    data-bs-toggle="modal"
                    data-bs-target="#feedbackAccessModal"
                @endguest
            >
                Share Feedback
            </a>
        </div>
    </div>
</section>

<section class="advertise-section feedback-section">
    <div class="container">
        <div class="advertise-section__heading">
            <span class="advertise-kicker">What to send</span>
            <h2>Help the registry team improve the platform.</h2>
            <p>Choose the feedback category that fits best. Every submission is routed to admins for review.</p>
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <article class="feedback-topic-card h-100">
                    <div class="feedback-topic-card__icon"><i class="bi bi-tools"></i></div>
                    <h3>Technical</h3>
                    <p>Report broken pages, errors, confusing flows, upload issues, or anything that blocks a task.</p>
                </article>
            </div>
            <div class="col-md-4">
                <article class="feedback-topic-card h-100">
                    <div class="feedback-topic-card__icon"><i class="bi bi-briefcase"></i></div>
                    <h3>Business</h3>
                    <p>Suggest improvements for SBO listings, procurement, advertising, verification, or payments.</p>
                </article>
            </div>
            <div class="col-md-4">
                <article class="feedback-topic-card h-100">
                    <div class="feedback-topic-card__icon"><i class="bi bi-lightbulb"></i></div>
                    <h3>Ideas</h3>
                    <p>Share feature requests, usability ideas, content corrections, or general platform suggestions.</p>
                </article>
            </div>
        </div>
    </div>
</section>

<section class="advertise-inquiry feedback-form-section" id="feedback-form">
    <div class="container">
        <div class="advertise-inquiry__panel">
            <div class="advertise-inquiry__intro">
                <span class="advertise-kicker">Feedback form</span>
                <h2>Send feedback to the registry team.</h2>
                <p>Feedback submission is available to signed-in users so admins can follow up if needed.</p>
            </div>

            @if (session('feedback_success'))
                <div class="premium-summary-panel mb-3">
                    <div class="premium-summary-panel__label">Feedback received</div>
                    <div class="premium-summary-panel__value">Thank you for helping improve the registry.</div>
                    <p class="small text-muted mb-0 mt-2">{{ session('feedback_success') }}</p>
                </div>
            @endif
            @if (session('feedback_auth_message'))
                <div class="alert alert-info py-2">{{ session('feedback_auth_message') }}</div>
            @endif

            @auth
                <form action="{{ route('feedback.submit') }}" method="POST" class="feedback-form premium-flow-card" data-ui-form>
                    @csrf

                    <div class="row g-3 premium-flow-card__body">
                        <div class="col-md-6">
                            <label for="feedback-name" class="form-label">Name</label>
                            <input id="feedback-name" type="text" name="name" value="{{ old('name', $feedbackName) }}" class="form-control @error('name') is-invalid @enderror" autocomplete="name" data-form-help="Enter your full name so the registry team knows who submitted the feedback." required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="feedback-kc-handle" class="form-label">KC Handle</label>
                            <input id="feedback-kc-handle" type="text" name="kc_handle" value="{{ old('kc_handle', $feedbackUser->kc_handle ?? '') }}" class="form-control @error('kc_handle') is-invalid @enderror" data-form-help="Enter your KingsChat handle so admins can identify or follow up with you there." required>
                            @error('kc_handle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="feedback-email" class="form-label">Email</label>
                            <input id="feedback-email" type="email" name="email" value="{{ old('email', $feedbackUser->email) }}" class="form-control @error('email') is-invalid @enderror" autocomplete="email" data-form-help="Enter the email address admins should use if they need to follow up." required>
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="feedback-type" class="form-label">Feedback type</label>
                            <select id="feedback-type" name="feedback_type" class="form-select @error('feedback_type') is-invalid @enderror" data-form-help="Choose the category that best describes your feedback so admins can review it quickly." required>
                                <option value="">Choose a category</option>
                                @foreach(FeedbackSubmission::typeOptions() as $key => $label)
                                    <option value="{{ $key }}" @selected(old('feedback_type') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('feedback_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="feedback-priority" class="form-label">Priority</label>
                            <select id="feedback-priority" name="priority" class="form-select @error('priority') is-invalid @enderror" data-form-help="Choose normal for general feedback, high when the issue blocks an important workflow, or low for minor suggestions." required>
                                @foreach(FeedbackSubmission::priorityOptions() as $key => $label)
                                    <option value="{{ $key }}" @selected(old('priority', FeedbackSubmission::PRIORITY_NORMAL) === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('priority') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="feedback-page-url" class="form-label">Related page URL</label>
                            <input id="feedback-page-url" type="url" name="page_url" value="{{ old('page_url') }}" class="form-control @error('page_url') is-invalid @enderror" placeholder="https://registry.lwcommerce.org/..." data-form-help="Paste the page link related to your feedback, if one applies.">
                            @error('page_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-12">
                            <label for="feedback-subject" class="form-label">Subject</label>
                            <input id="feedback-subject" type="text" name="subject" value="{{ old('subject') }}" class="form-control @error('subject') is-invalid @enderror" data-form-help="Enter a short summary of the feedback or issue." required>
                            @error('subject') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-12">
                            <label for="feedback-message" class="form-label">Message</label>
                            <textarea id="feedback-message" name="message" rows="5" class="form-control @error('message') is-invalid @enderror" data-form-help="Describe what happened, what you expected, or what improvement you would like to see." required>{{ old('message') }}</textarea>
                            @error('message') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-12 premium-action-bar">
                            <span class="small text-muted">Admins receive your feedback and can follow up using your account details.</span>
                            <button type="submit" class="btn btn-primary rounded-pill px-4">
                                Send Feedback
                            </button>
                        </div>
                    </div>
                </form>
            @else
                <div class="advertise-inquiry__locked" data-feedback-auth-required>
                    <div class="d-flex gap-3 align-items-start">
                        <div class="advertise-inquiry__lock-icon" aria-hidden="true">
                            <i class="bi bi-chat-heart"></i>
                        </div>
                        <div>
                            <h3 class="h5 mb-2">Register or log in to send feedback.</h3>
                            <p class="mb-3">
                                You can read about the feedback channels now, but feedback submission is available only to logged-in users.
                            </p>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="{{ route('join') }}" class="btn btn-primary" wire:navigate>
                                    Register your Business
                                </a>
                                <button type="button" class="btn btn-outline-primary" data-auth-redirect="{{ $feedbackFormUrl }}" data-bs-toggle="modal" data-bs-target="#loginModal">
                                    Log in
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endauth
        </div>
    </div>
</section>

@guest
<div class="modal fade" id="feedbackAccessModal" tabindex="-1" aria-labelledby="feedbackAccessModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="feedbackAccessModalLabel">Log in to send feedback</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">{{ session('feedback_auth_message', 'Please register or log in before submitting feedback to the registry team.') }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Not now</button>
                <button type="button" class="btn btn-outline-primary" data-auth-redirect="{{ $feedbackFormUrl }}" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#loginModal">Log in</button>
                <a href="{{ route('join') }}" class="btn btn-primary" data-bs-dismiss="modal">Register your Business</a>
            </div>
        </div>
    </div>
</div>
@endguest
@endsection

@push('styles')
<style>
    .feedback-hero {
        background:
            linear-gradient(115deg, rgba(11, 58, 102, 0.84), rgba(11, 111, 71, 0.72)),
            url("{{ asset('images/hero-work-bg.jpg') }}") center/cover;
    }

    .feedback-topic-card {
        background: #ffffff;
        border: 1px solid rgba(13, 110, 253, 0.12);
        border-radius: 8px;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
        padding: 1.25rem;
    }

    .feedback-topic-card__icon {
        align-items: center;
        background: rgba(13, 110, 253, 0.1);
        border-radius: 999px;
        color: var(--bs-primary);
        display: inline-flex;
        font-size: 1.25rem;
        height: 44px;
        justify-content: center;
        margin-bottom: 1rem;
        width: 44px;
    }

    .feedback-topic-card h3 {
        color: #172033;
        font-size: 1.05rem;
        font-weight: 800;
        margin-bottom: 0.5rem;
    }

    .feedback-topic-card p {
        color: #596579;
        margin-bottom: 0;
    }

    .feedback-form-section .advertise-inquiry__locked {
        background: #f8fbff;
        border: 1px solid rgba(13, 110, 253, 0.16);
        border-radius: 8px;
        padding: 1.25rem;
    }

    .feedback-form-section .advertise-inquiry__lock-icon {
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
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pageUrlInput = document.getElementById('feedback-page-url');

    if (!pageUrlInput || pageUrlInput.value) {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    const explicitSource = params.get('source') || params.get('page') || params.get('url');

    if (explicitSource) {
        pageUrlInput.value = explicitSource;
        return;
    }

    if (!document.referrer) {
        return;
    }

    try {
        const referrer = new URL(document.referrer);

        if (referrer.origin === window.location.origin && referrer.href !== window.location.href) {
            pageUrlInput.value = referrer.href;
        }
    } catch (error) {
        // Ignore malformed referrers.
    }
});
</script>
@endpush
