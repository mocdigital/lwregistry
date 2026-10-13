<?php

declare(strict_types=1);

use App\Http\Controllers\AccountAnalyticsController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountDocumentsController;
use App\Http\Controllers\AdCampaignController;
use App\Http\Controllers\AdCampaignPaymentController;
use App\Http\Controllers\AdClickController;
use App\Http\Controllers\AdImpressionController;
use App\Http\Controllers\AdvertiseController;
use App\Http\Controllers\AdvertisingInquiryController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\BusinessReportController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeedbackSubmissionController;
use App\Http\Controllers\GbpcConferenceRegistrationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Local\SqliteBrowserController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentProofDownloadController;
use App\Http\Controllers\ProcurementAccessController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\ProcurementDocumentController;
use App\Http\Controllers\ProcurementWriteController;
use App\Http\Controllers\PublicMediaController;
use App\Http\Controllers\ReferralLandingController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SboCertificateDownloadController;
use App\Http\Controllers\SboEspeesPaymentController;
use App\Http\Controllers\SboFeeController;
use App\Http\Controllers\SboListingProfileController;
use App\Http\Controllers\SboMessageActionController;
use App\Http\Controllers\SboMessageAttachmentController;
use App\Http\Controllers\SboMessageTranslationController;
use App\Http\Controllers\SboNetworkWriteController;
use App\Http\Controllers\SboPaymentRequiredController;
use App\Http\Controllers\SboReportController;
use App\Http\Controllers\SboSealDownloadController;
use App\Http\Controllers\SboSealImageController;
use App\Http\Controllers\SboVerificationController;
use App\Http\Controllers\SboWorkspaceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StatesController;
use App\Http\Controllers\SubscriberController;
use App\Http\Controllers\TwoFactorSettingsController;
use App\Models\Sbo;
use App\Support\AdminPages;
use App\Support\SboWorkspaceAccess;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

/*
 * The sales assistant. Deliberately open to guests: someone deciding whether
 * to register is exactly who it is for, and behind a login it would only ever
 * answer people who had already joined.
 *
 * Throttled because it is an unauthenticated write — every question is stored.
 * 60 a minute, not 20: a visitor tapping through the button menus and typing a
 * few questions reaches twenty within a minute easily, and the 429 that follows
 * surfaces to them as "I could not reach the registry" — the assistant looking
 * broken for using it normally. Sixty still stops a script hammering it.
 */
Route::post('/assistant/open', [ChatbotController::class, 'open'])
    ->middleware('throttle:60,1')
    ->name('chatbot.open');
Route::post('/assistant/ask', [ChatbotController::class, 'ask'])
    ->middleware('throttle:60,1')
    ->name('chatbot.ask');
// The home screen's cards and composer — a question answered straight from the
// bank, without the guided flow's greeting and lead questions.
Route::post('/assistant/direct', [ChatbotController::class, 'direct'])
    ->middleware('throttle:60,1')
    ->name('chatbot.direct');
// Start the conversation again, from wherever it has got to.
Route::post('/assistant/restart', [ChatbotController::class, 'restart'])
    ->middleware('throttle:60,1')
    ->name('chatbot.restart');
// Polled by the widget while a person is handling the conversation — the
// handoff state and any replies the visitor has not seen. Higher limit because
// this fires on a timer, not on an action.
Route::post('/assistant/poll', [ChatbotController::class, 'poll'])
    ->middleware('throttle:120,1')
    ->name('chatbot.poll');
// "Talk to a support person" — a direct request for a human, available at any
// point rather than only where the flow offers it.
Route::post('/assistant/handoff', [ChatbotController::class, 'handoff'])
    ->middleware('throttle:20,1')
    ->name('chatbot.handoff');
/*
 * Refresh the CSRF cookie.
 *
 * A GET passes the CSRF middleware as a "read", which is what makes it reissue
 * a current XSRF-TOKEN cookie — a rejected POST (419) throws before doing that,
 * so the stale token would otherwise stay stale and every retry fail the same
 * way. The widget calls this when it sees a 419, then retries with the fresh
 * token. Empty body: only the Set-Cookie matters.
 */
Route::get('/assistant/token', fn () => response()->noContent())
    ->name('chatbot.token');

// Public uploads. Live nginx blocks /storage/*, so generated public-disk URLs
// use this Laravel-owned route instead.
Route::get('/media/{path}', PublicMediaController::class)
    ->where('path', '.*')
    ->name('public-media.show');

/*
 * The header language selector. Deliberately open to guests: someone deciding
 * whether to register should be able to read the site in their own language
 * first. See App\Support\Locales for how the choice is resolved.
 */
Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');
Route::get('/about', fn () => Inertia\Inertia::render('public/AboutView'))->name('about');
// The standalone /join page is retired in favour of the /register wizard; the
// name stays so the many route('join') links across the app still resolve.
Route::redirect('/join', '/register')->name('join');
/*
 * The advertise screen shipped with NO props at all, so the Vue version lost
 * everything the Blade one had: the real placement slots and their pixel
 * dimensions (config/ads.php is the single source of truth, and the inquiry
 * endpoint validates `ad_location` against exactly those keys), the signed-in
 * advertiser's prefill, and the Turnstile site key.
 */
Route::get('/advertise', [AdvertiseController::class, 'show'])->name('advertise');
/*
 * The screen sent only name and email, but FeedbackSubmissionController
 * requires `kc_handle`, `feedback_type` and `priority` too — and validates the
 * last two against FeedbackSubmission's own option lists, so a screen offering
 * anything else is offering choices the endpoint rejects.
 */
Route::get('/feedback', fn () => Inertia\Inertia::render('public/FeedbackView', [
    'user' => [
        'name' => mb_trim(auth()->user()->firstname . ' ' . auth()->user()->lastname),
        'email' => auth()->user()->email,
        'kcHandle' => auth()->user()->getAttributes()['kc_handle'] ?? null,
    ],
    'types' => collect(App\Models\FeedbackSubmission::typeOptions())
        ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
        ->values(),
    'priorities' => collect(App\Models\FeedbackSubmission::priorityOptions())
        ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
        ->values(),
]))
    ->middleware(['auth', 'verified', 'sbo_payment_approved'])
    ->name('feedback');
// Reference data for the registration wizard's country/state pair.
Route::get('/states', StatesController::class)->name('states');

// Conference registrations from the GBPC landing page (gbpc.lwregistry.org),
// posted cross-origin from a static site. Public, CSRF-exempt (see bootstrap),
// CORS-scoped (config/cors.php) and rate-limited.
Route::post('/gbpc/conference-registrations', [GbpcConferenceRegistrationController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('gbpc.conference-registrations.store');

// The current GBPC live-stream URL, fetched by the landing page's video player.
// Public and CORS-scoped to the gbpc subdomain, like the registration endpoint.
Route::get('/gbpc/stream-url', App\Http\Controllers\GbpcStreamUrlController::class)
    ->name('gbpc.stream-url');

/*
 * Opportunity alerts. Guests included — the card that collects them is on the
 * public home page, which is the point of it.
 */
Route::post('/subscribe', [SubscriberController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('subscribe');

Route::post('/advertise/inquiry', AdvertisingInquiryController::class)
    ->middleware('throttle:5,1')
    ->name('advertise.inquiry');
Route::post('/feedback', FeedbackSubmissionController::class)
    ->middleware(['auth', 'throttle:5,1'])
    ->name('feedback.submit');
Route::post('/advertise/campaigns', [AdCampaignController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('advertise.campaigns.store');
// The self-service builder: create a dated campaign and go straight to Espees.
Route::post('/advertise/campaigns/submit', [AdCampaignController::class, 'submit'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('advertise.campaigns.submit');
Route::post('/advertise/campaigns/{campaign}/pay', [AdCampaignPaymentController::class, 'start'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('advertise.campaigns.pay');
Route::get('/advertise/campaigns/payments/{transaction}/success', [AdCampaignPaymentController::class, 'success'])
    ->middleware('auth')
    ->name('advertise.campaigns.payment.success');
Route::get('/advertise/campaigns/payments/{transaction}/failure', [AdCampaignPaymentController::class, 'failure'])
    ->middleware('auth')
    ->name('advertise.campaigns.payment.failure');

// Zone referral link → records the referring zone in session, then lands on the
// home page with the signup modal flagged to auto-open (?join=1).
Route::get('/join/{code}', ReferralLandingController::class)->name('join.referral');

// Ad click tracking → records click, redirects to the advertiser URL.
Route::get('/go/ad/{ad}', AdClickController::class)->name('ads.click');
// Ad view beacon → the SPA calls this when a slot actually renders an ad.
Route::get('/go/ad/{ad}/seen', AdImpressionController::class)->name('ads.seen');

// Business routes
/*
 * The registry itself is members-only, in both senses. Only Home, About,
 * Support and Advertise are reachable signed out — and the platform is paid to
 * use, so `sbo_payment_approved` additionally requires a confirmed
 * registration fee. See SboWorkspaceAccess::requiresPayment.
 *
 * The gate is applied to route groups rather than globally on purpose: the
 * screens that let someone *stop* being gated (the fee step, both payment
 * methods, registering a listing, support, settings, logout) must stay
 * reachable, and an allowlist of those is far easier to get wrong than an
 * explicit list of what is behind the wall.
 */
Route::middleware(['auth', 'verified', 'sbo_payment_approved'])->group(function (): void {
    Route::get('/businesses', [BusinessController::class, 'index'])->name('businesses.index');
    /*
     * The home page's five "Explore the Global Directory" rows. Behind the same
     * gate as the directory itself: a browse page is an index of the registry,
     * so leaving it open would list the membership to anyone.
     */
    Route::get('/browse/{facet}', [App\Http\Controllers\BrowseController::class, 'show'])
        ->name('browse.show');
    Route::get('/categories', [BusinessController::class, 'categories'])->name('categories.index');
    Route::get('/businesses/categories/{category:slug}', [BusinessController::class, 'indexByCategory'])->name('businesses.category');
    Route::get('/business/{business:slug}', [BusinessController::class, 'show'])->name('businesses.show');
    // Linked from the profile's verified badge (sbo-verify 223:4647).
    Route::get('/business/{business:slug}/verification', [BusinessController::class, 'verification'])
        ->name('businesses.verification');
});

/*
|--------------------------------------------------------------------------
| Procurement / Opportunities
|--------------------------------------------------------------------------
|
| The Vue shell links here 15 times and none of it was routed. Public read
| screens; bidding and RFQ creation still have no POST behind them.
*/
Route::middleware(['auth', 'verified', 'sbo_payment_approved'])->group(function (): void {
    Route::get('/procurement', [ProcurementController::class, 'index'])->name('procurement.index');
    // Won & awarded bids — the platform's success stories, signed-in members.
    Route::get('/procurement/awarded', [ProcurementController::class, 'awarded'])->name('procurement.awarded');
    Route::get('/procurement/new', [ProcurementController::class, 'create'])->name('procurement.create');
    Route::get('/procurement/{procurement}/bid/{step?}', [ProcurementController::class, 'bid'])
        ->whereNumber('procurement')
        ->whereIn('step', ['1', '2', '3', '4', '5'])
        ->name('procurement.bid');
    Route::get('/procurement/{procurement}/{tab?}', [ProcurementController::class, 'show'])
        ->whereNumber('procurement')
        ->whereIn('tab', ['overview', 'documents', 'qa', 'interest'])
        ->name('procurement.show');
});

/*
|--------------------------------------------------------------------------
| Writes — the endpoints the designed forms had no target for
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    // Procurement
    Route::post('procurement', [ProcurementWriteController::class, 'store'])->name('procurement.store');
    // Save a request as a draft to finish later (create or update a draft).
    Route::post('procurement/draft', [ProcurementWriteController::class, 'draft'])->name('procurement.draft');
    // Editing a request already posted — owner only, enforced in the controllers.
    Route::get('procurement/{procurement}/edit', [ProcurementController::class, 'edit'])
        ->whereNumber('procurement')->name('procurement.edit');
    Route::put('procurement/{procurement}', [ProcurementWriteController::class, 'update'])
        ->whereNumber('procurement')->name('procurement.update');
    // Businesses to invite to a private request (the builder's picker).
    Route::get('procurement-invitees', [ProcurementController::class, 'invitees'])->name('procurement.invitees');
    // Invite-only access: a non-invited member asks, the owner answers.
    Route::post('procurement/{procurement}/access-requests', [ProcurementAccessController::class, 'request'])
        ->whereNumber('procurement')->middleware('throttle:10,1')->name('procurement.access.request');
    Route::post('procurement/{procurement}/access-requests/{accessRequest}/approve', [ProcurementAccessController::class, 'approve'])
        ->whereNumber('procurement')->name('procurement.access.approve');
    Route::post('procurement/{procurement}/access-requests/{accessRequest}/decline', [ProcurementAccessController::class, 'decline'])
        ->whereNumber('procurement')->name('procurement.access.decline');
    /*
     * Documents tab. "Download Selected Items" had no route at all — the file
     * list beside it was hardcoded, and a request could hold only one file.
     */
    Route::get('procurement/{procurement}/documents/download', [ProcurementDocumentController::class, 'download'])
        ->whereNumber('procurement')->name('procurement.documents.download');
    Route::post('procurement/{procurement}/documents', [ProcurementDocumentController::class, 'store'])
        ->whereNumber('procurement')->name('procurement.documents.store');
    Route::delete('procurement/{procurement}/documents/{document}', [ProcurementDocumentController::class, 'destroy'])
        ->whereNumber('procurement')->name('procurement.documents.destroy');
    Route::post('procurement/{procurement}/bid', [ProcurementWriteController::class, 'bid'])
        ->whereNumber('procurement')->name('procurement.bid.store');
    Route::post('procurement/{procurement}/award', [ProcurementWriteController::class, 'award'])
        ->whereNumber('procurement')->name('procurement.award');
    // The winning business's testimonial about the engagement.
    Route::post('procurement/{procurement}/testimonial', [ProcurementWriteController::class, 'storeTestimonial'])
        ->whereNumber('procurement')->name('procurement.testimonial.store');
    // Q&A — the tab was fully designed with no table behind it.
    Route::post('procurement/{procurement}/questions', [ProcurementWriteController::class, 'question'])
        ->whereNumber('procurement')->name('procurement.questions.store');
    Route::post('procurement/{procurement}/questions/{question}/answer', [ProcurementWriteController::class, 'answerQuestion'])
        ->whereNumber('procurement')->name('procurement.questions.answer');

    // SBO network
    Route::post('sbo/connections', [SboNetworkWriteController::class, 'connect'])->name('sbo.connections.store');
    /*
     * Take a group conversation aside into a private one. Covers all of
     * member-to-admin, admin-to-member and member-to-member: sharing a room is
     * what authorises it, so one endpoint serves the three.
     */
    Route::post('sbo/conversations/{conversation}/private', [SboNetworkWriteController::class, 'privateConversation'])
        ->name('sbo.conversations.private');
    // The member's one thread with the registry, from whichever end starts it.
    Route::post('sbo/conversations/admin', [SboNetworkWriteController::class, 'messageAdmin'])
        ->name('sbo.conversations.admin');
    /*
     * What you can do to a message that is not writing another one. Reply is
     * not here: a reply IS a message, and goes through sbo.messages.store
     * carrying the id of the one it answers.
     */
    Route::post('sbo/messages/{message}/react', [SboMessageActionController::class, 'react'])
        ->name('sbo.messages.react');
    Route::post('sbo/messages/{message}/pin', [SboMessageActionController::class, 'pin'])
        ->name('sbo.messages.pin');
    Route::delete('sbo/messages/{message}', [SboMessageActionController::class, 'destroy'])
        ->name('sbo.messages.destroy');

    // A chat attachment, gated on membership of the conversation it belongs to.
    Route::get('sbo/message-attachments/{attachment}', SboMessageAttachmentController::class)
        ->name('sbo.message-attachments.show');
    Route::patch('sbo/connections/{connection}', [SboNetworkWriteController::class, 'respond'])->name('sbo.connections.respond');
    // Disconnect. Either side may, unlike respond() which is the recipient's.
    Route::delete('sbo/connections/{connection}', [SboNetworkWriteController::class, 'disconnect'])
        ->name('sbo.connections.destroy');
    // Compose — opens (or reuses) the conversation for an accepted connection.
    Route::post('sbo/conversations', [SboNetworkWriteController::class, 'storeConversation'])->name('sbo.conversations.store');
    // Member-created group chats: create one, and (owner only) manage its roster.
    Route::post('sbo/groups', [SboNetworkWriteController::class, 'storeGroup'])->name('sbo.groups.store');
    Route::post('sbo/groups/{conversation}/members', [SboNetworkWriteController::class, 'addGroupMembers'])
        ->name('sbo.groups.members.add');
    Route::delete('sbo/groups/{conversation}/members', [SboNetworkWriteController::class, 'removeGroupMember'])
        ->name('sbo.groups.members.remove');
    Route::delete('sbo/groups/{conversation}/leave', [SboNetworkWriteController::class, 'leaveGroup'])
        ->name('sbo.groups.leave');
    Route::post('sbo/conversations/{conversation}/messages', [SboNetworkWriteController::class, 'message'])->name('sbo.messages.store');
    Route::post('sbo/conversations/{conversation}/read', [SboNetworkWriteController::class, 'readMessages'])->name('sbo.messages.read');
    // The chat header's icons, all three of which were disabled buttons.
    // Silence one conversation's bell, for the member who asked.
    Route::post('sbo/conversations/{conversation}/mute', [SboNetworkWriteController::class, 'mute'])
        ->name('sbo.conversations.mute');
    Route::post('sbo/conversations/{conversation}/star', [SboNetworkWriteController::class, 'star'])
        ->name('sbo.conversations.star');
    Route::delete('sbo/conversations/{conversation}/star', [SboNetworkWriteController::class, 'unstar'])
        ->name('sbo.conversations.unstar');
    // Reporting a message, and the business behind it. Verified reporters only.
    /*
     * "See translation" on a chat message. JSON, because it expands one bubble
     * in place rather than re-rendering a thread the reader is mid-way through.
     */
    Route::post('sbo/messages/{message}/translate', [SboMessageTranslationController::class, 'store'])
        ->name('sbo.messages.translate');
    Route::post('sbo/messages/{message}/report', [SboReportController::class, 'message'])
        ->name('sbo.messages.report');

    // Manual payment proof
    Route::post('sbo/applications/{sbo}/payments/proof', [SboNetworkWriteController::class, 'storePaymentProof'])
        ->name('sbo.payments.proof.store');

    // Compliance documents (application step 3)
    Route::post('sbo/documents', [SboNetworkWriteController::class, 'storeDocument'])->name('sbo.documents.store');
});

// Support is reachable signed out, so its enquiry form is too.
Route::post('support/enquiries', [SboNetworkWriteController::class, 'supportEnquiry'])
    ->middleware('throttle:5,1')
    ->name('support.enquiries.store');

// Public MOC-style verification page for finalized SBO registration numbers.
Route::get('/verify/sbo/{mocRegistrationNumber}', SboVerificationController::class)
    ->name('sbo.verify');

/*
|--------------------------------------------------------------------------
| Auth-required actions
|--------------------------------------------------------------------------
*/

Route::post('/business/{business:slug}/reviews', [ReviewController::class, 'store'])
    // `{business}` resolves to an Sbo — the model every profile page renders.
    ->middleware(['auth', 'verified', 'throttle:10,1'])
    ->name('businesses.reviews.store');

Route::post('/business/{business:slug}/report', [BusinessReportController::class, 'store'])
    ->middleware('auth')
    ->name('businesses.reports.store');

/*
 * Reporting an SBO. The route above targets the legacy Business model and
 * cannot report a listing; this writes SboNetworkReport, which the admin's
 * Network Reports queue already reads. Verified reporters only — see the
 * controller.
 */
Route::post('/business/{business:slug}/reports', [SboReportController::class, 'store'])
    ->middleware(['auth', 'verified', 'sbo_payment_approved'])
    ->name('sbo.reports.store');

Route::delete('favorites/clear', [DashboardController::class, 'clearFavorites'])
    ->middleware('auth')
    ->name('favorites.clear');

/*
|--------------------------------------------------------------------------
| 🔑 Post-login router
|--------------------------------------------------------------------------
| This route handles:
| - email verification
| - role-based redirects
| - classic HTTP redirects (no Livewire SPA)
*/

Route::get('/post-login', function () {
    $user = auth()->user();

    // Email not verified → verification notice
    if ( ! $user->hasVerifiedEmail()) {
        return redirect()->route('verification.notice');
    }

    // Admins → admin dashboard.
    //
    // The admin panel is Blade, not part of the Inertia SPA. Login posts over
    // Inertia's XHR, and a plain redirect is followed inside that XHR — landing
    // on HTML the Inertia client cannot render, which it then shows inside an
    // error modal. Inertia::location answers with the header that makes the
    // client do a real browser navigation to the admin page instead; a
    // non-Inertia request (a direct hit, a test) degrades to an ordinary
    // redirect on its own.
    if ($user->role?->isAdmin()) {
        return Inertia\Inertia::location(
            route(AdminPages::firstAccessibleRouteName($user) ?? 'home'),
        );
    }

    // SBO managers with unpaid registration fees → payment step first.
    if ($user->role?->isSboManager()) {
        // A member who has verified their email but not yet added a business is
        // sent straight to "Add your business" — that is their next step.
        if ( ! $user->sbos()->exists()) {
            return redirect()->route('sbo.listings.create');
        }

        $targetSbo = app(SboWorkspaceAccess::class)->paymentTarget($user);

        // Payment lives on the add-business page's final step now, so an owner
        // who still owes the fee returns there — not to a separate fees screen.
        if ($targetSbo && ! $targetSbo->feesPaid()) {
            return redirect()->route('sbo.listings.create', ['pay' => $targetSbo->id]);
        }

        // A verified owner lands on their profile, not the dashboard: once a
        // registration is through, the profile is the page that shows the seal,
        // the certificate and the live listing — the thing they signed in to
        // see. The dashboard remains where an owner mid-registration goes,
        // because for them it is still a to-do list.
        $verified = $user->sbos()->get()->contains(
            static fn (Sbo $sbo): bool => $sbo->isFullyVerified(),
        );

        if ($verified) {
            return redirect()->route('account.profile');
        }

        // Until an admin approves the business the whole workspace is locked, so
        // an owner still awaiting approval is landed on the wall — their status
        // screen and the way on to the application flow — not the SBO dashboard.
        if (app(SboWorkspaceAccess::class)->requiresVerification($user)) {
            return redirect()->route('sbo.payment-required');
        }

        return redirect()->route('sbo.dashboard');
    }

    // Regular users → user dashboard
    return redirect()->route('dashboard');
})
    ->middleware('auth')
    ->name('post-login');

/*
|--------------------------------------------------------------------------
| User dashboard (verified only)
|--------------------------------------------------------------------------
*/

/*
 * `guest` redirects a signed-in visitor here, so clicking "Sign in" while
 * already authenticated landed on this page — the last Blade screen in the
 * signed-in flow. It now defers to post-login, which already routes by role
 * to the Vue workspace.
 */
Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'sbo_payment_approved'])
    ->name('dashboard');

Route::get('profile', [DashboardController::class, 'profile'])
    ->middleware(['auth', 'verified', 'sbo_payment_approved'])
    ->name('account.profile');

// The Vue shell links to /account six times; there was no such route.
// "New Listing" — add another SBO to an existing account. /register is
// guest-only, so it bounced signed-in owners to the dashboard.
Route::get('sbo/listings/new', [SboWorkspaceController::class, 'createListing'])
    ->middleware(['auth', 'verified'])
    ->name('sbo.listings.create');
// The COB entry to the same add-business form — so a Citizen-Owned Business
// signup has its own route and does not come in through the SBO one. The entity
// type is still chosen on the form; this only separates the entry point.
Route::get('cob/listings/new', [SboWorkspaceController::class, 'createListing'])
    ->middleware(['auth', 'verified'])
    ->name('cob.listings.create');
// The registration-fee schedule for a country, for the inline fee calculator on
// the add-business form (before any business record exists).
Route::get('fees/preview', App\Http\Controllers\FeePreviewController::class)
    ->middleware(['auth', 'verified'])
    ->name('fees.preview');
Route::post('sbo/listings', [SboWorkspaceController::class, 'storeListing'])
    ->middleware(['auth', 'verified'])
    ->name('sbo.listings.store');
// The bin icon on /account. Owner-only, and refused once approved.
Route::delete('sbo/listings/{sbo}', [SboWorkspaceController::class, 'destroyListing'])
    ->middleware(['auth', 'verified'])
    ->name('sbo.listings.destroy');

/*
 * Owner-maintained public profile.
 *
 * Before these, nothing in the app could edit an existing listing: a business
 * could not fix a typo in its own description, and the profile's services,
 * gallery and opening hours were a hard-coded array shared by every SBO.
 *
 * Each endpoint re-checks ownership itself rather than trusting the group —
 * see SboListingProfileController::authorizeOwner(). The controller writes
 * only the open fields; name, registration number, legal structure, category,
 * address and every verification column stay as the MoC approved them.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('sbo/listings/{sbo}/edit', [SboListingProfileController::class, 'edit'])
        ->name('sbo.listings.edit');
    Route::patch('sbo/listings/{sbo}/profile', [SboListingProfileController::class, 'update'])
        ->name('sbo.listings.profile.update');
    Route::put('sbo/listings/{sbo}/services', [SboListingProfileController::class, 'updateServices'])
        ->name('sbo.listings.services.update');
    Route::put('sbo/listings/{sbo}/addresses', [SboListingProfileController::class, 'updateAddresses'])
        ->name('sbo.listings.addresses.update');
    Route::put('sbo/listings/{sbo}/hours', [SboListingProfileController::class, 'updateHours'])
        ->name('sbo.listings.hours.update');
    /*
     * Card branding — the cover photo and round logo every listing card draws.
     * Single-file collections, so a re-upload replaces.
     */
    Route::post('sbo/listings/{sbo}/branding', [SboListingProfileController::class, 'storeBranding'])
        ->name('sbo.listings.branding.store');
    Route::delete('sbo/listings/{sbo}/branding/{collection}', [SboListingProfileController::class, 'destroyBranding'])
        ->name('sbo.listings.branding.destroy');
    Route::post('sbo/listings/{sbo}/gallery', [SboListingProfileController::class, 'storeGalleryImage'])
        ->name('sbo.listings.gallery.store');
    Route::delete('sbo/listings/{sbo}/gallery/{media}', [SboListingProfileController::class, 'destroyGalleryImage'])
        ->name('sbo.listings.gallery.destroy');
});

Route::get('account', [AccountController::class, 'index'])
    ->middleware(['auth', 'verified', 'sbo_payment_approved'])
    ->name('account.index');

/*
 * The profile's Quick Actions cards. "View Analytics" and "Manage Documents"
 * both linked to /dashboard — the profile they sit on — so neither did
 * anything. Documents stays outside the payment gate: a fee held up on
 * paperwork is exactly when an owner needs to reach their documents.
 */
Route::get('account/analytics', AccountAnalyticsController::class)
    ->middleware(['auth', 'verified', 'sbo_payment_approved'])
    ->name('account.analytics');
Route::get('account/documents', AccountDocumentsController::class)
    ->middleware(['auth', 'verified'])
    ->name('account.documents');

/*
 * The Vue application screens. They link without an SBO id, so the controller
 * resolves the owner's current application; sbo/applications/{sbo}/progress
 * stays the explicit form for owners with more than one.
 */
Route::get('application/{step?}', [ApplicationController::class, 'show'])
    ->whereIn('step', ['submitted', 'fees', 'documents', 'verification'])
    ->middleware(['auth', 'verified'])
    ->name('application.show');

// The typed Tax Identification Number for the current application.
Route::post('application/tin', [ApplicationController::class, 'storeTin'])
    ->middleware(['auth', 'verified'])
    ->name('application.tin.store');

/*
 * Full notification history (any authenticated user). The screen used to
 * render seven hardcoded cards; it reads the `notifications` table now, and
 * the three writes below are what its controls needed.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])
        ->middleware('sbo_payment_approved')
        ->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.read');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])
        ->name('notifications.destroy');
});

// Signed out is a supported state here — one of the four public pages.
Route::get('support', fn () => Inertia\Inertia::render('public/SupportView', [
    'user' => auth()->check() ? [
        'firstname' => auth()->user()->firstname,
        'lastname' => auth()->user()->lastname,
        'email' => auth()->user()->email,
    ] : null,
]))
    ->name('support.index');

// Secure download of a compliance document (owner or compliance admin only).
Route::get('compliance-documents/{document}/download', App\Http\Controllers\ComplianceDocumentDownloadController::class)
    ->middleware('auth')
    ->name('compliance-documents.download');

// Secure download of an SBO's payment proof (owner or compliance admin only).
Route::get('payment-proofs/{proof}/download', PaymentProofDownloadController::class)
    ->middleware('auth')
    ->name('payment-proofs.download');

// Secure download of an SBO's personalized digital seal.
Route::get('sbo-seals/{seal}/download', SboSealDownloadController::class)
    ->middleware('auth')
    ->name('sbo.seals.download');

// Inline seal image preview. This avoids depending on the web server exposing
// storage/app/public through a /storage symlink.
Route::get('sbo-seals/{seal}/image', SboSealImageController::class)
    ->name('sbo.seals.image');

/*
 * The certificate — the seal plus the business's own details, as a PDF.
 * Deliberately NOT behind `sbo_payment_approved`: it is only reachable once
 * the fee is paid anyway, and gating it would mean the wall could bounce a
 * member away from the very artifact paying earned them.
 */
Route::get('sbo/{sbo}/certificate', [SboCertificateDownloadController::class, 'download'])
    ->middleware(['auth', 'verified'])
    ->name('sbo.certificate.download');

// The same document served inline, so it can be read before it is kept.
Route::get('sbo/{sbo}/certificate/preview', [SboCertificateDownloadController::class, 'preview'])
    ->middleware(['auth', 'verified'])
    ->name('sbo.certificate.preview');

/*
|--------------------------------------------------------------------------
| SBO Manager — RFQs (verified SBO managers only)
|--------------------------------------------------------------------------
*/

Route::get('sbo/dashboard', [SboWorkspaceController::class, 'dashboard'])
    ->middleware(['auth', 'verified', 'sbo_manager', 'sbo_payment_approved'])
    ->name('sbo.dashboard');

// The COB workspace home. Reuses the shared dashboard (which relabels itself
// "COB" from the business's entity type), so a Citizen-Owned Business owner
// lands here — /cob — instead of on the SBO route.
Route::get('cob/dashboard', [SboWorkspaceController::class, 'dashboard'])
    ->middleware(['auth', 'verified', 'sbo_manager', 'sbo_payment_approved'])
    ->name('cob.dashboard');

Route::get('sbo/applications', [ApplicationController::class, 'index'])
    ->middleware(['auth', 'verified', 'sbo_manager'])
    ->name('sbo.applications.index');

// Application progress for a named SBO. post-login sends unpaid owners here,
// so it was the first thing a new registrant saw in the old design.
Route::get('sbo/applications/{sbo}/progress', [ApplicationController::class, 'forSbo'])
    ->middleware(['auth', 'verified', 'sbo_manager'])
    ->name('sbo.applications.progress');

// Resubmitting after an info request. The applicant only; see the controller.
Route::post('sbo/applications/{sbo}/resubmit', [ApplicationController::class, 'resubmit'])
    ->middleware(['auth', 'verified', 'sbo_manager'])
    ->name('sbo.applications.resubmit');

/*
 * Confirming the registration fee. Both payment methods refuse to start until
 * `calculated_fee` is set, and the designed calculator posted nowhere — so
 * this is what unblocks Espees checkout and the manual proof alike.
 */
Route::post('sbo/applications/{sbo}/fees', [SboFeeController::class, 'confirm'])
    ->middleware(['auth', 'verified', 'sbo_manager'])
    ->name('sbo.fees.confirm');
Route::delete('sbo/applications/{sbo}/fees', [SboFeeController::class, 'reset'])
    ->middleware(['auth', 'verified', 'sbo_manager'])
    ->name('sbo.fees.reset');

// Native Espees checkout is available to authenticated SBO owners with a
// confirmed unpaid fee; the dedicated test account uses the fixed 1-Espee path.
Route::post('sbo/applications/{sbo}/payments/espees/start', [SboEspeesPaymentController::class, 'start'])
    ->middleware(['auth', 'verified', 'sbo_manager', 'throttle:10,1'])
    ->name('sbo.payments.espees.start');
Route::get('sbo/payments/espees/{transaction}/success', [SboEspeesPaymentController::class, 'success'])
    ->middleware(['auth', 'verified', 'sbo_manager'])
    ->name('sbo.payments.espees.success');
Route::get('sbo/payments/espees/{transaction}/failure', [SboEspeesPaymentController::class, 'failure'])
    ->middleware(['auth', 'verified', 'sbo_manager'])
    ->name('sbo.payments.espees.failure');

/*
 * The wall itself. Deliberately NOT behind `sbo_manager`: every gated user is
 * sent here, including one who owns no listing yet and therefore is not a
 * manager — 403ing them would leave the gate with no way to explain itself.
 */
Route::get('sbo/complete-payment-required', SboPaymentRequiredController::class)
    ->middleware(['auth', 'verified'])
    ->name('sbo.payment-required');

/*
 * The last step of the application journey. ApplicationView links here by
 * path, which is why the earlier href-only audit did not catch it as missing.
 */
Route::get('sbo/progress', [ApplicationController::class, 'progress'])
    ->middleware(['auth', 'verified', 'sbo_manager'])
    ->name('sbo.progress');

Route::get('my-rfqs', [SboWorkspaceController::class, 'rfqs'])
    ->middleware(['auth', 'verified', 'sbo_manager', 'sbo_payment_approved', 'sbo_network_verified'])
    ->name('sbo.rfqs.index');

Route::get('sbo/network/{tab?}', [SboWorkspaceController::class, 'network'])
    ->whereIn('tab', ['connections', 'requests', 'messages'])
    ->middleware(['auth', 'verified', 'sbo_manager', 'sbo_network_verified'])
    ->name('sbo.network.index');

// Same screen as /categories (sbo-categories-redesign 10:116); one design,
// so one implementation rather than a second copy.
Route::get('sbo/categories', [BusinessController::class, 'categories'])
    ->middleware(['auth', 'verified', 'sbo_manager', 'sbo_payment_approved'])
    ->name('sbo.categories.index');

/*
|--------------------------------------------------------------------------
| User settings (Volt)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', 'settings/profile');

    /*
     * Settings are served from Vue now. The route NAMES are kept because Blade
     * layouts still call route('profile.edit') and route('password.edit'); only
     * the pages behind them changed. `security` is what Volt called
     * `password`, so the old URL redirects rather than 404ing a bookmark.
     */
    Route::get('settings/profile', [SettingsController::class, 'show'])
        ->defaults('tab', 'profile')->name('profile.edit');

    /*
     * The listing-profile editor, inside Settings. Same forms and same
     * endpoints as /sbo/listings/{id}/edit — see SboListingProfilePayload.
     */
    Route::get('settings/about', [SettingsController::class, 'show'])
        ->defaults('tab', 'about')->name('settings.about');
    Route::get('settings/appearance', [SettingsController::class, 'show'])
        ->defaults('tab', 'appearance')->name('appearance.edit');

    Route::get('settings/security', [SettingsController::class, 'show'])
        ->defaults('tab', 'security')->name('password.edit');

    Route::get('settings/notifications', [SettingsController::class, 'show'])
        ->defaults('tab', 'notifications')->name('settings.notifications');
    // Privacy was a rail label with no route behind it.
    Route::get('settings/privacy', [SettingsController::class, 'show'])
        ->defaults('tab', 'privacy')->name('settings.privacy');

    /*
     * Leading slash matters: without it the Location header is relative and
     * resolves against /settings/, giving /settings/settings/security.
     * Route::redirect also registers ANY, so the update below cannot share
     * this URI — it lives on settings/security instead.
     */
    Route::redirect('settings/password', '/settings/security');

    Route::patch('settings/profile', [SettingsController::class, 'updateProfile'])
        ->name('settings.profile.update');
    Route::put('settings/security', [SettingsController::class, 'updatePassword'])
        ->name('settings.password.update');
    /*
     * Danger Zone. Reversible suspension rather than a row delete — see
     * SettingsController::deactivate for why deleting the user would orphan
     * their approved listings rather than remove them.
     */
    Route::post('settings/deactivate', [SettingsController::class, 'deactivate'])
        ->name('settings.deactivate');

    /*
     * Two-factor auth. Enrolment is two steps on purpose — `enable` mints the
     * secret, `confirm` puts it into force once the member has proved their
     * authenticator produces valid codes. See TwoFactorSettingsController.
     */
    Route::post('settings/two-factor', [TwoFactorSettingsController::class, 'enable'])
        ->name('two-factor.enable');
    Route::post('settings/two-factor/confirm', [TwoFactorSettingsController::class, 'confirm'])
        ->name('two-factor.confirm');
    Route::delete('settings/two-factor', [TwoFactorSettingsController::class, 'disable'])
        ->name('two-factor.disable');
    Route::post('settings/two-factor/recovery-codes', [TwoFactorSettingsController::class, 'regenerateRecoveryCodes'])
        ->name('two-factor.recovery-codes');
    // Avatar and the two preference panels, none of which had an endpoint.
    Route::post('settings/photo', [SettingsController::class, 'updatePhoto'])
        ->name('settings.photo.update');
    Route::delete('settings/photo', [SettingsController::class, 'deletePhoto'])
        ->name('settings.photo.destroy');
    Route::patch('settings/preferences', [SettingsController::class, 'updatePreferences'])
        ->name('settings.preferences.update');

    if (app()->environment('local')) {
        Route::get('local/sqlite', SqliteBrowserController::class)
            ->name('local.sqlite-browser');
    }
});

/*
|--------------------------------------------------------------------------
| Unlisted registered-business list
|--------------------------------------------------------------------------
|
| /rlist/businesses — every registered business, behind one password.
|
| Deliberately not linked from anywhere in the app, not in any sitemap, and
| served with a noindex X-Robots-Tag (see the controller). It is NOT named in
| robots.txt on purpose: that file is world-readable, so a Disallow line there
| would publish the very URL this is meant to keep unlisted.
|
| The unlock is throttled because a single shared password with an unlimited
| number of guesses is not a password.
*/

Route::get('rlist/businesses', [App\Http\Controllers\RegisteredBusinessListController::class, 'show'])
    ->name('rlist.businesses');

Route::post('rlist/businesses/unlock', [App\Http\Controllers\RegisteredBusinessListController::class, 'unlock'])
    ->middleware('throttle:10,1')
    ->name('rlist.businesses.unlock');

Route::post('rlist/businesses/lock', [App\Http\Controllers\RegisteredBusinessListController::class, 'lock'])
    ->name('rlist.businesses.lock');

/*
|--------------------------------------------------------------------------
| Auth & Admin routes
|--------------------------------------------------------------------------
*/

require __DIR__ . '/auth.php';
require __DIR__ . '/admin.php';
