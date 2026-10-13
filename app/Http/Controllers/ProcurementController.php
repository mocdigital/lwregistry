<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAccessRequest;
use App\Models\PurchaseOrderBid;
use App\Models\PurchaseOrderDocument;
use App\Models\PurchaseOrderInvite;
use App\Models\PurchaseOrderQuestion;
use App\Support\DataLabels;
use App\Support\RichText;
use App\Support\SboNetworkAccess;
use App\Support\Timezones;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Procurement / Opportunities.
 *
 * The Vue shell links here 15 times and none of it was routed. There is no
 * procurement controller in the app at all — the only server-side handling is
 * inside DashboardController, which queries open PurchaseOrders for the
 * dashboard's "available RFQs" panel. These are read screens built on that
 * same model.
 *
 * NOT built here: submitting a bid, and creating an RFQ. `create()` renders
 * the form, but there is no POST behind it — the screen collects fields
 * (document uploads, expertise, terms) whose handling needs decisions this
 * commit does not make. See INTEGRATION.md.
 */
final class ProcurementController extends Controller
{
    /** Sort options offered by the listings header, and what each one means. */
    private const SORTS = ['recent', 'closing', 'value'];

    /** An RFQ is "closing soon" within this many days of its deadline. */
    private const CLOSING_SOON_DAYS = 14;

    public function index(Request $request): InertiaResponse
    {
        $sort = in_array($request->query('sort'), self::SORTS, true)
            ? $request->query('sort')
            : 'recent';

        /*
         * The filters sidebar was entirely inert: the keyword box had no model,
         * the category and status rows were display-only spans, "Clear All" did
         * nothing and the sort select drove a local ref. Every one of them is a
         * query parameter now, so the URL is shareable and pagination keeps the
         * filters via withQueryString().
         */
        $orders = $this->filtered($request)
            ->with(['category', 'sbo', 'media'])
            ->withCount('bids')
            ->when('recent' === $sort, fn ($q) => $q->latest())
            // Soonest deadline first, and an RFQ with no deadline set sorts
            // last rather than leading on a NULL.
            ->when('closing' === $sort, fn ($q) => $q
                ->orderByRaw('order_deadline is null')
                ->orderBy('order_deadline'))
            /*
             * `budget` holds a plain number. There is no currency column beside
             * it, so it is presented as stored — but it ranks perfectly well,
             * which is what "Highest Value" needs.
             */
            ->when('value' === $sort, fn ($q) => $q
                ->orderByRaw('cast(budget as decimal(15,2)) desc'))
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('procurement/ProcurementListView', [
            'requests' => [
                'data' => $orders->getCollection()->map(
                    fn (PurchaseOrder $o): array => $this->shape($o),
                )->values(),
                'links' => $orders->linkCollection(),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
            ],
            // Sidebar facets, counted from the open set rather than invented.
            'facets' => Category::query()
                ->whereHas('purchaseOrders', fn ($q) => $q->where('status', 'open')->viewableBy(auth()->user()))
                ->withCount(['purchaseOrders' => fn ($q) => $q->where('status', 'open')->viewableBy(auth()->user())])
                ->orderByDesc('purchase_orders_count')
                ->take(8)
                ->get()
                ->map(fn (Category $c): array => [
                    // The name is the filter value; the label is that name in
                    // the reader's language. See DataLabels.
                    'label' => DataLabels::get(DataLabels::CATEGORY, $c->name),
                    'count' => (int) $c->purchase_orders_count,
                ])->values(),
            /*
             * Status counts for the sidebar. Computed over the whole open set
             * rather than the current page, which is what the sidebar claims to
             * be counting — "Closing Soon 3" meant three on this page before.
             */
            'statusFacets' => [
                [
                    'value' => 'all',
                    'label' => __('procurement.facet.all_active'),
                    'count' => $this->openScope()->count(),
                ],
                [
                    'value' => 'closing',
                    'label' => __('procurement.facet.closing_soon'),
                    'count' => $this->closingSoon($this->openScope())->count(),
                ],
            ],
            'filters' => (object) $request->only(['category', 'search', 'status']),
            'sort' => $sort,
            'sorts' => [
                ['value' => 'recent', 'label' => __('procurement.sort.recent')],
                ['value' => 'closing', 'label' => __('procurement.sort.closing')],
                ['value' => 'value', 'label' => __('procurement.sort.value')],
            ],
        ]);
    }

    /**
     * Won & awarded bids — the platform's success stories.
     *
     * Signed-in members only (the route group enforces it). Public requests
     * only: an invite-only award stays confidential. The winner's testimonial
     * is attached where they left one and agreed to show it; otherwise the
     * award is listed factually, without a quote.
     */
    public function awarded(Request $request): InertiaResponse
    {
        $orders = PurchaseOrder::query()
            ->whereNotNull('awarded_bid_id')
            ->where(fn ($q) => $q->whereNull('visibility')
                ->orWhere('visibility', PurchaseOrder::VISIBILITY_PUBLIC))
            ->with(['sbo', 'category', 'awardedBid.bidderSbo', 'testimonials', 'media'])
            ->latest('completed_at')
            ->take(120)
            ->get();

        return Inertia::render('procurement/AwardedBidsView', [
            'awards' => $orders->map(function (PurchaseOrder $po): array {
                $winnerSboId = $po->awardedBid?->bidder_sbo_id;
                $winner = $po->awardedBid?->bidderSbo;
                $testimonial = $winnerSboId
                    ? $po->testimonials->firstWhere('sbo_id', $winnerSboId)
                    : null;
                $show = $testimonial && $testimonial->display_publicly;

                return [
                    'id' => (string) $po->id,
                    'title' => $po->title,
                    'image' => $po->getFirstMediaUrl(PurchaseOrder::FEATURED_COLLECTION) ?: null,
                    'buyer' => $po->sbo?->name,
                    'category' => $po->category
                        ? DataLabels::get(DataLabels::CATEGORY, $po->category->name)
                        : null,
                    'awardedAt' => $po->completed_at?->format('M j, Y'),
                    'winner' => $winner ? [
                        'name' => $winner->name,
                        'slug' => $winner->is_registry ? null : $winner->slug,
                    ] : null,
                    'testimonial' => $show ? [
                        'rating' => (int) $testimonial->rating,
                        'headline' => $testimonial->headline,
                        'body' => $testimonial->body,
                    ] : null,
                ];
            })->values(),
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        $sbos = $request->user()->sbos()->orderBy('name')->get(['id', 'name']);

        // The RFQ is posted *as* one of the owner's SBOs, and the form's "SBO"
        // select offered one hardcoded name. Counts feed the sidebar summary,
        // which was four fixed numbers.
        $sboIds = $sbos->pluck('id');
        $mine = PurchaseOrder::query()->whereIn('sbo_id', $sboIds);

        return Inertia::render('procurement/ProcurementCreateView', [
            'categories' => Category::orderBy('name')->pluck('name')->values(),
            // What a budget may be quoted in. From the model, so the form and
            // the validator cannot offer and accept different lists.
            'currencies' => PurchaseOrder::CURRENCIES,
            'sbos' => $sbos->map(fn ($sbo): array => [
                'id' => $sbo->id,
                'name' => $sbo->name,
            ])->values(),
            'summary' => [
                'total' => (clone $mine)->count(),
                'open' => (clone $mine)->where('status', 'open')->count(),
                'awarded' => (clone $mine)->whereNotNull('awarded_bid_id')->count(),
                'bids' => PurchaseOrderBid::whereIn(
                    'purchase_order_id',
                    (clone $mine)->select('id'),
                )->count(),
            ],
            // The owner's saved drafts, to pick up where they left off.
            'drafts' => (clone $mine)->where('status', 'draft')->latest('updated_at')->get()
                ->map(fn (PurchaseOrder $d): array => [
                    'title' => $d->title ?: 'Untitled draft',
                    'savedAt' => $d->updated_at?->diffForHumans(),
                    'editUrl' => route('procurement.edit', $d),
                ])
                ->values(),
        ]);
    }

    /**
     * Businesses matching a search, for the private-request invite picker.
     *
     * A short list by name; the builder adds one as an invited business. Kept
     * to active listings so a buyer is not offered an SBO that cannot take part.
     */
    public function invitees(Request $request): \Illuminate\Http\JsonResponse
    {
        $term = mb_trim((string) $request->query('q'));

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        // Only verified businesses can bid, so only they are worth inviting.
        // The cheap conditions filter in SQL; full verification (which includes
        // document compliance) is decided per row.
        $access = app(SboNetworkAccess::class);

        return response()->json(
            \App\Models\Sbo::query()
                ->where('name', 'like', '%' . $term . '%')
                ->where('is_active', true)
                ->where('status', 'active')
                ->where('verification_status', 'approved')
                ->where('payment_status', 'paid')
                ->orderBy('name')
                ->limit(40)
                ->get()
                ->filter(fn (\App\Models\Sbo $sbo): bool => $access->sboCanUseNetwork($sbo))
                ->take(10)
                ->map(fn (\App\Models\Sbo $sbo): array => ['id' => $sbo->id, 'name' => $sbo->name])
                ->values()
        );
    }

    /**
     * The same builder as create, prefilled — a request's owner amending it.
     *
     * Only the owner reaches this; the write endpoint enforces the same, so a
     * crafted PUT cannot edit a request the caller does not own.
     */
    public function edit(Request $request, PurchaseOrder $procurement): InertiaResponse
    {
        $sbos = $request->user()->sbos()->orderBy('name')->get(['id', 'name']);

        abort_unless($sbos->pluck('id')->contains($procurement->sbo_id), 403);

        $procurement->loadMissing('category');
        $mine = PurchaseOrder::query()->whereIn('sbo_id', $sbos->pluck('id'));

        return Inertia::render('procurement/ProcurementCreateView', [
            'categories' => Category::orderBy('name')->pluck('name')->values(),
            'currencies' => PurchaseOrder::CURRENCIES,
            'sbos' => $sbos->map(fn ($sbo): array => ['id' => $sbo->id, 'name' => $sbo->name])->values(),
            'summary' => [
                'total' => (clone $mine)->count(),
                'open' => (clone $mine)->where('status', 'open')->count(),
                'awarded' => (clone $mine)->whereNotNull('awarded_bid_id')->count(),
                'bids' => PurchaseOrderBid::whereIn('purchase_order_id', (clone $mine)->select('id'))->count(),
            ],
            /*
             * Present only when editing. The form fills itself from `values`
             * and posts to `updateUrl` instead of creating a new request.
             * Dates are formatted to what each input expects — whole-day fields
             * as Y-m-d, the briefing (which has a time) as the datetime-local
             * shape.
             */
            'editing' => [
                'id' => $procurement->id,
                'updateUrl' => route('procurement.update', $procurement),
                // A draft still shows the "Save draft" button and publishes on
                // "Post"; a live request just saves its changes.
                'isDraft' => 'draft' === $procurement->status,
                'featuredImageUrl' => $procurement->getFirstMediaUrl(PurchaseOrder::FEATURED_COLLECTION) ?: null,
                'values' => [
                    'sbo_id' => $procurement->sbo_id,
                    'title' => (string) $procurement->title,
                    'description' => (string) $procurement->description,
                    'category' => $procurement->category?->name ?? '',
                    'request_type' => $procurement->request_type ?? 'product',
                    'budget' => null === $procurement->budget ? '' : (string) $procurement->budget,
                    'budget_currency' => $procurement->budget_currency ?? 'Espees',
                    'quantity' => null === $procurement->quantity ? '' : (string) $procurement->quantity,
                    'delivery_location' => $procurement->delivery_location ?? '',
                    'order_deadline' => $procurement->order_deadline?->format('Y-m-d') ?? '',
                    'advertised_at' => $procurement->advertised_at?->format('Y-m-d') ?? '',
                    'briefing_at' => $procurement->briefing_at?->format('Y-m-d\TH:i') ?? '',
                    'briefing_link' => $procurement->briefing_link ?? '',
                    'briefing_location' => $procurement->briefing_location ?? '',
                    'briefing_mandatory' => (bool) $procurement->briefing_mandatory,
                    'inquiry_opens_at' => $procurement->inquiry_opens_at?->format('Y-m-d') ?? '',
                    'inquiry_closes_at' => $procurement->inquiry_closes_at?->format('Y-m-d') ?? '',
                    'inquiry_responses_at' => $procurement->inquiry_responses_at?->format('Y-m-d') ?? '',
                    'final_selection_at' => $procurement->final_selection_at?->format('Y-m-d') ?? '',
                    'visibility' => $procurement->visibility,
                    'terms' => $procurement->terms ?? '',
                ],
                // Who the request is already shared with, so the builder shows
                // them rather than losing them on the next save.
                'invites' => [
                    'emails' => $procurement->invites()->whereNotNull('invited_email')->pluck('invited_email')->values(),
                    'sbos' => $procurement->invites()->whereNotNull('invited_sbo_id')->with('invitedSbo:id,name')->get()
                        ->map(fn (PurchaseOrderInvite $i): array => ['id' => $i->invited_sbo_id, 'name' => $i->invitedSbo?->name ?? ''])
                        ->values(),
                ],
            ],
        ]);
    }

    public function show(Request $request, PurchaseOrder $procurement, string $tab = 'overview'): InertiaResponse
    {
        $procurement->loadMissing(['category', 'sbo'])->loadCount('bids');

        // Only the buyer sees the bids, and only the buyer can award.
        $isOwner = $request->user()->sbos()->pluck('id')->contains($procurement->sbo_id);

        // An administrator oversees procurement: they see the bids for review
        // without owning the request or asking for access. Awarding stays with
        // the buyer (and the admin dashboard) — this is a read-only look.
        $isAdmin = $request->user()->isAdmin();
        $canReviewBids = $isOwner || $isAdmin;

        // A draft exists only for its owner; to anyone else it is not there yet.
        abort_if('draft' === $procurement->status && ! $isOwner, 404);

        // Whether this member has already bid — the button then edits rather
        // than places, since a re-submission replaces the existing bid.
        $hasBid = ! $isOwner && PurchaseOrderBid::query()
            ->where('purchase_order_id', $procurement->id)
            ->whereIn('bidder_sbo_id', $request->user()->sbos()->pluck('id'))
            ->exists();

        // The winner is invited to leave a testimonial; the form pre-fills from
        // one they already left. Only the winning business sees this.
        $winnerSboId = $procurement->awardedBid?->bidder_sbo_id;
        $isWinner = null !== $winnerSboId
            && $request->user()->sbos()->pluck('id')->contains($winnerSboId);
        $myTestimonial = $isWinner
            ? $procurement->testimonials()->where('sbo_id', $winnerSboId)->first()
            : null;

        /*
         * A private request the viewer was not invited to reveals nothing — not
         * the title, not the buyer — only that it exists and can be asked for.
         * Everyone else (owner, invited, or any request that is public) falls
         * through to the request itself.
         */
        if ( ! $procurement->isViewableBy($request->user())) {
            $pending = $procurement->accessRequests()
                ->where('user_id', $request->user()->id)
                ->where('status', PurchaseOrderAccessRequest::STATUS_PENDING)
                ->exists();

            return Inertia::render('procurement/ProcurementLockedView', [
                'id' => (string) $procurement->id,
                'requested' => $pending,
                'requestUrl' => route('procurement.access.request', $procurement),
            ]);
        }

        return Inertia::render('procurement/ProcurementDetailView', [
            'id' => (string) $procurement->id,
            'tab' => in_array($tab, ['overview', 'documents', 'qa', 'interest'], true) ? $tab : 'overview',
            'request' => $this->shape($procurement),
            'isOwner' => $isOwner,
            'isAdmin' => $isAdmin,
            'hasBid' => $hasBid,
            'awardedBidId' => $procurement->awarded_bid_id ? (string) $procurement->awarded_bid_id : null,
            /*
             * The Q&A tab rendered two invented questions and an "Ask Question"
             * button that did nothing. Real thread now — and the asker is
             * named only to the buyer, which is the rule the screen itself
             * states ("displayed anonymously to all other users").
             */
            /*
             * The Timeline block was six hardcoded rows — "July 1, 2026", a
             * Teams link to a meeting nobody scheduled — shown identically on
             * every request. It is the RFQ's own schedule now; see the
             * add_schedule_to_purchase_orders migration.
             */
            'timeline' => $this->timeline($procurement),
            /*
             * The Documents tab listed the same two invented files on every
             * request, with checkboxes and a Download Selected Items button
             * that had no route behind it.
             */
            'documents' => $this->documents($procurement),
            'documentsDownloadUrl' => route('procurement.documents.download', $procurement),
            'documentsUploadUrl' => route('procurement.documents.store', $procurement),
            'questions' => $this->questions($procurement, $isOwner, $request),
            'canAsk' => ! $isOwner
                && [] !== app(SboNetworkAccess::class)->verifiedSboIdsFor($request->user()),
            // Why the controls are disabled, so the screen can say so.
            'verifiedToParticipate' => [] !== app(SboNetworkAccess::class)
                ->verifiedSboIdsFor($request->user()),
            /*
             * The Interest tab listed six invented vendors. It is the real
             * bidders, and "View Details" opens what they actually submitted —
             * which is only possible now the wizard stores its answers as
             * fields rather than one text blob.
             */
            'interest' => $this->interest($procurement, $isOwner, $request),
            /*
             * "Review & Award" had no route, no panel and no bids behind it.
             * `awarded_bid_id` has been a column all along.
             */
            'bids' => $canReviewBids ? $this->bids($procurement) : [],
            // The owner's controls for a private request: who is invited, and
            // who is waiting to be let in. Null for a public request or a
            // non-owner, which is what hides the panel.
            'access' => $isOwner && $procurement->isInviteOnly()
                ? $this->accessPanel($procurement)
                : null,
            // The testimonial prompt, for the winning business only.
            'isWinner' => $isWinner,
            'testimonial' => $myTestimonial ? [
                'rating' => (int) $myTestimonial->rating,
                'headline' => $myTestimonial->headline,
                'body' => $myTestimonial->body,
                'displayPublicly' => (bool) $myTestimonial->display_publicly,
            ] : null,
        ]);
    }

    public function bid(Request $request, PurchaseOrder $procurement, string $step = '1'): InertiaResponse
    {
        $procurement->loadMissing(['category', 'sbo']);

        $user = $request->user();
        $sbos = $user->sbos()->orderBy('name')->get(['id', 'name']);

        // Bidding on your own request is refused by the endpoint; saying so on
        // the wizard is kinder than letting five steps end in a 403.
        $ownRequest = $sbos->pluck('id')->contains($procurement->sbo_id);

        $existing = $ownRequest ? null : PurchaseOrderBid::query()
            ->where('purchase_order_id', $procurement->id)
            ->whereIn('bidder_sbo_id', $sbos->pluck('id'))
            ->first();

        return Inertia::render('procurement/BidWizardView', [
            'id' => (string) $procurement->id,
            'step' => $step,
            'request' => $this->shape($procurement),
            'sbos' => $sbos->map(fn ($sbo): array => [
                'id' => $sbo->id,
                'name' => $sbo->name,
            ])->values(),
            'ownRequest' => $ownRequest,
            'closed' => 'open' !== $procurement->status,
            /*
             * Verified businesses only may bid. Said on the wizard's first
             * screen rather than after five steps and a 403 — the same courtesy
             * `ownRequest` already gets.
             */
            'unverified' => [] === app(SboNetworkAccess::class)->verifiedSboIdsFor($user),
            /*
             * One bid per SBO per RFQ — re-submitting replaces. The header says
             * so rather than letting the owner discover it after five steps.
             */
            'existingBid' => $existing ? [
                'submittedAt' => $existing->submitted_at?->format('F j, Y'),
                'status' => $existing->status,
            ] : null,
            'contact' => [
                'name' => mb_trim($user->firstname . ' ' . $user->lastname),
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * The invite / access-request panel shown to a private request's owner.
     *
     * @return array<string, mixed>
     */
    private function accessPanel(PurchaseOrder $procurement): array
    {
        return [
            'requests' => $procurement->accessRequests()
                ->where('status', PurchaseOrderAccessRequest::STATUS_PENDING)
                ->with('user')
                ->latest()
                ->get()
                ->map(fn (PurchaseOrderAccessRequest $r): array => [
                    'name' => mb_trim(($r->user?->firstname ?? '') . ' ' . ($r->user?->lastname ?? ''))
                        ?: ($r->user?->email ?? 'A member'),
                    'email' => $r->user?->email,
                    'approveUrl' => route('procurement.access.approve', [$procurement, $r]),
                    'declineUrl' => route('procurement.access.decline', [$procurement, $r]),
                ])
                ->values(),
            'invitees' => $procurement->invites()
                ->where('status', '!=', PurchaseOrderInvite::STATUS_DECLINED)
                ->with(['invitedSbo:id,name', 'invitedUser:id,firstname,lastname,email'])
                ->get()
                ->map(fn (PurchaseOrderInvite $i): string => $i->invited_email
                    ?? $i->invitedUser?->email
                    ?? $i->invitedSbo?->name
                    ?? 'Invited')
                ->values(),
        ];
    }

    /** Open RFQs, before any of the sidebar's filters. */
    private function openScope(): Builder
    {
        // Invite-only requests are excluded for anyone not the owner or invited,
        // so the list, the counts and the facets all speak of the same set the
        // viewer is actually allowed to see.
        return PurchaseOrder::query()
            ->where('status', 'open')
            ->viewableBy(auth()->user());
    }

    /** @param  Builder<PurchaseOrder>  $query */
    private function closingSoon(Builder $query): Builder
    {
        return $query->whereNotNull('order_deadline')
            ->whereBetween('order_deadline', [now(), now()->addDays(self::CLOSING_SOON_DAYS)]);
    }

    /**
     * The open set with the sidebar's filters applied.
     *
     * @return Builder<PurchaseOrder>
     */
    private function filtered(Request $request): Builder
    {
        return $this->openScope()
            ->when($request->filled('category'), fn ($q) => $q->whereHas(
                'category',
                fn ($c) => $c->where('name', $request->category),
            ))
            // The keyword box searches what a buyer would type: the request
            // itself, and the business asking for it.
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%' . $request->search . '%';

                $q->where(function ($sub) use ($term): void {
                    $sub->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('delivery_location', 'like', $term)
                        ->orWhereHas('sbo', fn ($s) => $s->where('name', 'like', $term))
                        ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $term));
                });
            })
            ->when('closing' === $request->query('status'), fn ($q) => $this->closingSoon($q));
    }

    /**
     * The two document lists the Documents tab draws.
     *
     * A request used to hold exactly one file, in `purchase_orders.document_path`.
     * That one is still listed — nothing already uploaded disappears — as the
     * first project document, with the rest coming from the documents table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documents(PurchaseOrder $order): array
    {
        $sections = [
            PurchaseOrderDocument::SECTION_PROJECT => 'Project Documents:',
            PurchaseOrderDocument::SECTION_ATTACHMENT => 'Attachments:',
        ];

        $rows = PurchaseOrderDocument::query()
            ->where('purchase_order_id', $order->id)
            ->oldest()
            ->get()
            ->map(fn (PurchaseOrderDocument $d): array => [
                'id' => $d->id,
                'section' => $d->section,
                'name' => $d->name,
                'date' => Timezones::format($d->created_at, 'M j, Y g:i A'),
                'size' => $d->humanSize(),
                'url' => $d->url(),
            ]);

        // The legacy single upload, presented as a document like any other so
        // the list is not two mechanisms in one panel. It has no row in the
        // documents table, so it is given the sentinel id 0 — selectable like
        // the rest, and the download endpoint reads it from document_path.
        if (filled($order->document_path)) {
            $rows->prepend([
                'id' => 0,
                'section' => PurchaseOrderDocument::SECTION_PROJECT,
                'name' => basename((string) $order->document_path),
                'date' => Timezones::format($order->created_at, 'M j, Y g:i A'),
                'size' => null,
                'url' => Storage::disk('public')->url($order->document_path),
            ]);
        }

        return collect($sections)
            ->map(fn (string $title, string $key): array => [
                'key' => $key,
                'title' => $title,
                'items' => $rows->where('section', $key)->values()->all(),
            ])
            ->values()
            ->filter(fn (array $section): bool => [] !== $section['items'])
            ->values()
            ->all();
    }

    /**
     * The RFP schedule, in the order it happens.
     *
     * Only the dates actually set are returned, so a simple request for quotes
     * shows a two-line timeline rather than six rows of "—".
     *
     * @return array<int, array<string, mixed>>
     */
    private function timeline(PurchaseOrder $order): array
    {
        $briefingNote = null;

        if ($order->briefing_at) {
            $briefingNote = filled($order->briefing_location)
                ? __('procurement.timeline.held_at', ['place' => $order->briefing_location])
                : (filled($order->briefing_link) ? __('procurement.timeline.held_online') : null);
        }

        $rows = [
            [
                'label' => __('procurement.timeline.advertised'),
                'value' => $order->advertised_at?->format('F j, Y'),
            ],
            [
                'label' => $order->briefing_mandatory
                    ? __('procurement.timeline.briefing_mandatory')
                    : __('procurement.timeline.briefing_optional'),
                'value' => Timezones::format($order->briefing_at, 'F j, Y, g:ia'),
                'note' => $briefingNote,
                'link' => $order->briefing_link,
            ],
            [
                'label' => __('procurement.timeline.inquiry_period'),
                // A duration reads as a range; one date alone reads as a
                // deadline, which is a different thing.
                'value' => match (true) {
                    $order->inquiry_opens_at && $order->inquiry_closes_at => $order->inquiry_opens_at->format('F j, Y')
                        . ' – ' . $order->inquiry_closes_at->format('F j, Y'),
                    (bool) $order->inquiry_closes_at => __('procurement.timeline.closes_on', ['date' => $order->inquiry_closes_at->format('F j, Y')]),
                    (bool) $order->inquiry_opens_at => __('procurement.timeline.opens_on', ['date' => $order->inquiry_opens_at->format('F j, Y')]),
                    default => null,
                },
            ],
            [
                'label' => __('procurement.timeline.responses_sent'),
                'value' => $order->inquiry_responses_at?->format('F j, Y'),
            ],
            [
                'label' => __('procurement.timeline.proposals_due'),
                'value' => $order->order_deadline?->format('F j, Y'),
            ],
            [
                'label' => __('procurement.timeline.final_selection'),
                'value' => $order->final_selection_at?->format('F j, Y'),
            ],
        ];

        return collect($rows)
            ->filter(fn (array $row): bool => filled($row['value']))
            ->map(fn (array $row): array => [
                'label' => $row['label'],
                'value' => $row['value'],
                'note' => $row['note'] ?? null,
                'link' => $row['link'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * The Q&A thread.
     *
     * @return array<int, array<string, mixed>>
     */
    private function questions(PurchaseOrder $order, bool $isOwner, Request $request): array
    {
        return PurchaseOrderQuestion::query()
            ->with(['user', 'sbo'])
            ->where('purchase_order_id', $order->id)
            ->oldest()
            ->get()
            ->map(function (PurchaseOrderQuestion $q, int $i) use ($isOwner, $request): array {
                // The buyer sees who asked; everyone else sees the question
                // only. The asker still sees their own name on their own row.
                $mine = $q->user_id === $request->user()?->id;

                return [
                    'id' => (string) $q->id,
                    'n' => (string) ($i + 1),
                    'title' => $q->title ?: 'Question',
                    'body' => $q->body,
                    'at' => Timezones::format($q->created_at, 'M j Y \a\t g:i A'),
                    'author' => $isOwner || $mine
                        ? ($q->sbo?->name ?? mb_trim(($q->user?->firstname ?? '') . ' ' . ($q->user?->lastname ?? '')) ?: 'Member')
                        : 'Anonymous',
                    'mine' => $mine,
                    'answer' => $q->answer,
                    'answeredAt' => Timezones::format($q->answered_at, 'M j Y \a\t g:i A'),
                    'answered' => $q->isAnswered(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The Interest tab: who has bid, and — for the buyer or the bidder
     * themselves — what they submitted.
     *
     * @return array<int, array<string, mixed>>
     */
    private function interest(PurchaseOrder $order, bool $isOwner, Request $request): array
    {
        $mySboIds = $request->user()->sbos()->pluck('id');

        return PurchaseOrderBid::query()
            ->with(['bidderSbo', 'user'])
            ->where('purchase_order_id', $order->id)
            ->latest('submitted_at')
            ->get()
            ->map(function (PurchaseOrderBid $bid) use ($isOwner, $mySboIds, $order): array {
                $mine = $mySboIds->contains($bid->bidder_sbo_id);
                // A rival's submission is not yours to read.
                $visible = $isOwner || $mine;

                return [
                    'id' => (string) $bid->id,
                    'vendor' => $bid->bidderSbo?->name ?? 'SBO',
                    'slug' => $bid->bidderSbo?->slug,
                    'contact' => mb_trim(($bid->user?->firstname ?? '') . ' ' . ($bid->user?->lastname ?? '')) ?: null,
                    'email' => $visible ? $bid->user?->email : null,
                    'address' => $bid->bidderSbo
                        ? mb_trim(implode(', ', array_filter([
                            $bid->bidderSbo->address, $bid->bidderSbo->city, $bid->bidderSbo->country,
                        ])))
                        : null,
                    'status' => $bid->status,
                    'submittedAt' => $bid->submitted_at?->format('F j, Y'),
                    'awarded' => $order->awarded_bid_id === $bid->id,
                    'canView' => $visible,
                    // The wizard's answers, for the detail panel.
                    'details' => $visible ? $bid->details : null,
                    'proposal' => $visible ? $bid->proposal : null,
                    'attachments' => $visible ? $bid->attachmentLinks() : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The bids on an RFQ, for its owner's award panel.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bids(PurchaseOrder $order): array
    {
        return PurchaseOrderBid::query()
            ->with(['bidderSbo', 'user'])
            ->where('purchase_order_id', $order->id)
            ->latest('submitted_at')
            ->get()
            ->map(fn (PurchaseOrderBid $bid): array => [
                'id' => (string) $bid->id,
                'bidder' => $bid->bidderSbo?->name
                    ?? mb_trim(($bid->user?->firstname ?? '') . ' ' . ($bid->user?->lastname ?? ''))
                    ?: 'Unknown bidder',
                'slug' => $bid->bidderSbo?->slug,
                'proposal' => $bid->proposal,
                'status' => $bid->status,
                'submittedAt' => $bid->submitted_at?->format('F j, Y'),
                'attachments' => $bid->attachmentLinks(),
            ])
            ->values()
            ->all();
    }

    /** One shape for the card, the detail header and the bid wizard header. */
    private function shape(PurchaseOrder $order): array
    {
        return [
            'id' => (string) $order->id,
            'title' => $order->title,
            // The card photo, when the buyer attached one. Null draws no cover.
            'featuredImageUrl' => $order->getFirstMediaUrl(PurchaseOrder::FEATURED_COLLECTION) ?: null,
            /*
             * Two shapes of the same text. `body` is plain, for the card and
             * any header that prints it as a line — rendering markup there
             * would show the tags. `bodyHtml` is the sanitised markup, for the
             * one place that lays the description out in full.
             */
            'body' => RichText::toPlainText($order->description),
            'bodyHtml' => RichText::clean($order->description),
            'category' => DataLabels::get(DataLabels::CATEGORY, $order->category?->name),
            'buyer' => $order->sbo?->name,
            // The currency the buyer chose, or nothing for a request posted
            // before there was a column to record one. See the migration.
            'budget' => filled($order->budget)
                ? __('procurement.estimated_budget', [
                    'amount' => mb_trim(($order->budget_currency ?? '') . ' ' . $order->budget),
                ])
                : null,
            'status' => $this->status($order),
            'bids' => trans_choice('procurement.bids_received_count', $order->bids_count ?? 0, ['count' => $order->bids_count ?? 0]),
            'posted' => $order->created_at?->format('F j, Y'),
            'deadline' => $order->order_deadline?->format('F j, Y'),
            /*
             * The detail hero printed "Posted Wednesday, July 1, 2026 10:00am",
             * "Last updated by Addendum #1 on Jul 28, 2026 2:09 PM" and
             * "Time Remaining: 13 days, 23 hours, 49 minutes" on every request
             * in the registry, whatever its actual dates. These are the real
             * ones.
             */
            'postedAt' => Timezones::format($order->created_at, 'l, F j, Y g:ia'),
            /*
             * Null unless the buyer has genuinely edited the request since
             * posting it. There is no addendum table, so the line reports an
             * edit rather than naming an amendment that does not exist.
             */
            'updatedAt' => $order->updated_at && $order->created_at
                && $order->updated_at->gt($order->created_at->addMinute())
                    ? Timezones::format($order->updated_at, 'M j, Y g:i A')
                    : null,
            // ISO, for the countdown the screen ticks. Null closes it.
            'deadlineAt' => $order->order_deadline?->toIso8601String(),
            // Named on the hero, so "all dates & times in …" is true.
            'timezone' => Timezones::current(),
        ];
    }

    /** "Closing Soon" is derived from the deadline; the column only has open/closed. */
    private function status(PurchaseOrder $order): string
    {
        if ('open' !== $order->status) {
            return (string) DataLabels::get(DataLabels::STATUS, ucfirst((string) $order->status));
        }

        return $order->order_deadline && $order->order_deadline->diffInDays(now()) <= 14
            ? __('procurement.facet.closing_soon')
            : (string) DataLabels::get(DataLabels::STATUS, 'Open');
    }
}
