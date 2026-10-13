<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Category;
use App\Models\Review;
use App\Models\Sbo;
use App\Models\SboAddress;
use App\Models\SboService;
use App\Models\SboView;
use App\Models\User;
use App\Support\DataLabels;
use App\Support\Geocoder;
use App\Support\Privacy;
use App\Support\SboBrowseFacets;
use App\Support\SboConnectionStates;
use App\Support\SboNetworkAccess;
use App\Support\SboVerificationScopes;
use App\Support\SboWorkspaceAccess;
use App\Support\Timezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class BusinessController extends Controller
{
    /** Directory sort options, in the order the dropdown lists them. */
    private const SORTS = ['relevance', 'newest', 'oldest', 'name_asc', 'name_desc'];

    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        $categories = Category::orderBy('name')->get();
        $fromHome = $request->boolean('from_home');

        // The "Sort by Relevance" control was drawn but inert. Whitelisted so
        // an arbitrary ?sort= cannot reach orderBy().
        $sort = in_array($request->query('sort'), self::SORTS, true)
            ? (string) $request->query('sort')
            : 'relevance';

        /*
         * An owner who has not paid cannot browse the registry.
         *
         * This used to call `lockedDirectoryView()`, which returns a Blade
         * View — while this method is typed `: InertiaResponse`. So the branch
         * did not render a wall, it threw a TypeError: `/businesses` was a hard
         * 500 for every unpaid owner, which is half of the seeded demo
         * accounts.
         *
         * Redirecting to the payment screen is what `EnsureSboPaymentApproved`
         * already does for `/dashboard`, so the gate now behaves the same way
         * wherever it is hit, and there is exactly one screen that explains it.
         */
        if ($this->shouldGateDirectory()) {
            return redirect()->route('sbo.payment-required');
        }

        // Public directory now lists SBOs. "Verified" = fully verified
        // (approved + documents compliant + fees paid); declined SBOs are hidden.
        $cities = Sbo::query()
            ->where('verification_status', '!=', 'declined')
            ->when($fromHome, fn ($q) => $q->where('verification_status', 'approved'))
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        $countries = Sbo::query()
            ->where('verification_status', '!=', 'declined')
            ->when($fromHome, fn ($q) => $q->where('verification_status', 'approved'))
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->distinct()
            ->orderBy('country')
            ->pluck('country');

        $industries = Sbo::query()
            ->where('verification_status', '!=', 'declined')
            ->when($fromHome, fn ($q) => $q->where('verification_status', 'approved'))
            ->whereNotNull('industry')
            ->distinct()
            ->orderBy('industry')
            ->pluck('industry');

        $businesses = Privacy::scopeListed(Sbo::query())
            ->where('verification_status', '!=', 'declined')
            // Home page searches default to approved/active SBOs.
            ->when($fromHome, fn ($q) => $q->where('verification_status', 'approved'))
            // Directory-only facets. `category` and `country` are plain columns
            // on sbos, so these are exact matches against the values the
            // dropdowns were populated from.
            ->when( ! $fromHome && $request->filled('category'), fn ($q) => $q->where('category', $request->category))
            /*
             * Industry is the sector above category — see App\Support\
             * SboIndustries. The two compose: picking "Technology" and then
             * "Software Development" narrows twice rather than conflicting.
             */
            ->when( ! $fromHome && $request->filled('industry'), fn ($q) => $q->where('industry', $request->industry))
            ->when( ! $fromHome && $request->filled('country'), fn ($q) => $q->where('country', $request->country))
            /*
             * Employee band, from the browse-by-size page. The boundaries live
             * in SboBrowseFacets so the count shown there and the result set
             * here cannot drift apart.
             */
            ->when( ! $fromHome && $request->filled('size'), fn ($q) => SboBrowseFacets::applySize($q, (string) $request->size))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%' . $request->search . '%';
                // Both levels of the taxonomy are searchable, so "Technology"
                // finds the software houses filed under Software Development
                // and not only whoever happens to have it in their description.
                $q->where(function ($subQuery) use ($term): void {
                    $subQuery->where('name', 'like', $term)
                        ->orWhere('category', 'like', $term)
                        ->orWhere('industry', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('address', 'like', $term);
                });
            })
            // Location: partial match for home page, exact match for businesses page.
            ->when($request->filled('location') && $fromHome, fn ($q) => $q->where('city', 'like', "%{$request->location}%"))
            ->when($request->filled('location') && ! $fromHome, fn ($q) => $q->where('city', $request->location))
            // Verified/unverified filters (only on the businesses page).
            ->when( ! $fromHome && 'verified' === $request->status, fn ($q) => SboVerificationScopes::fullyVerified($q))
            ->when( ! $fromHome && 'unverified' === $request->status, fn ($q) => SboVerificationScopes::notFullyVerified($q))
            ->when('newest' === $sort, fn ($q) => $q->latest())
            ->when('oldest' === $sort, fn ($q) => $q->oldest())
            ->when('name_asc' === $sort, fn ($q) => $q->orderBy('name'))
            ->when('name_desc' === $sort, fn ($q) => $q->orderByDesc('name'))
            // "Relevance" is the default: newest first, then verified floated
            // to the top below.
            ->when('relevance' === $sort, fn ($q) => $q->latest())
            // Card branding, without a media query per row.
            ->with(['media', 'addresses'])
            ->paginate(10)
            ->withQueryString();

        /*
         * Verified SBOs first — but only under Relevance. Under an explicit
         * sort the chosen order IS the answer, and re-sorting on top of it
         * made "Name A–Z" produce something that was not alphabetical.
         */
        if ('relevance' === $sort) {
            $businesses->setCollection(
                $businesses->getCollection()->sortByDesc('is_verified')->values()
            );
        }

        /*
         * Connection state per card, so "Connect" can say what it actually
         * means: Connect, Pending, or Message. One query for the whole page
         * rather than one per row.
         */
        $viewer = auth()->user();
        $mySboIds = $viewer ? $viewer->sbos()->pluck('id') : collect();
        $connections = SboConnectionStates::between($mySboIds, $businesses->getCollection()->pluck('id'));

        return Inertia::render('public/DirectoryView', [
            // Shape the payload for the screen rather than leaking the model:
            // the Vue side is typed against this, not against Sbo.
            'listings' => [
                'data' => $businesses->getCollection()->map(fn (Sbo $sbo): array => [
                    'id' => $sbo->id,
                    'name' => $sbo->name,
                    'slug' => $sbo->slug,
                    'category' => $sbo->category,
                    'address' => mb_trim(implode(', ', array_filter([$sbo->address, $sbo->city]))),
                    // The full postal address, for the Directions link. The
                    // card's own line is deliberately shorter.
                    'mapQuery' => mb_trim(implode(', ', array_filter([
                        $sbo->name, $sbo->address, $sbo->city, $sbo->state, $sbo->country,
                    ]))),
                    // Stored bare ("example.com") as often as with a scheme, so
                    // the screen normalises before linking.
                    'website' => $sbo->website,
                    'verified' => 'approved' === $sbo->verification_status,
                    // The owner's own card artwork; null until uploaded, and
                    // the screen falls back to its stock image.
                    'coverUrl' => $sbo->coverUrl(),
                    'logoUrl' => $sbo->logoUrl(),
                    // Drives the logo ring colour: SBO = blue, COB = gold.
                    'entityType' => $sbo->entity_type,
                    // One per country the business holds an address in. The
                    // card used to draw flags picked by grid position.
                    'countryCodes' => $sbo->countryCodes(),
                    'connection' => SboConnectionStates::forSbo($mySboIds, $connections, $sbo->id),
                ])->values(),
                'links' => $businesses->linkCollection(),
                'meta' => [
                    'current_page' => $businesses->currentPage(),
                    'last_page' => $businesses->lastPage(),
                    'total' => $businesses->total(),
                    'from' => $businesses->firstItem(),
                    'to' => $businesses->lastItem(),
                ],
            ],
            'categories' => $categories->map(fn (Category $c): array => [
                'name' => $c->name,
                'slug' => $c->slug,
            ])->values(),
            'cities' => $cities->values(),
            'countries' => $countries->values(),
            /*
             * The "All Industries" select, which shipped disabled because there
             * was no column behind it. Only industries that actually have a
             * listing are offered, so the filter never returns an empty page.
             */
            'industries' => $industries->values(),
            // Cast: an empty `only()` is `[]`, which serialises to a JSON array
            // and breaks the object the Vue prop is typed against.
            'filters' => (object) $request->only([
                'search', 'location', 'status', 'category', 'industry', 'country', 'size',
            ]),
            'sort' => $sort,
            'sorts' => [
                ['value' => 'relevance', 'label' => __('directory.sort.relevance')],
                ['value' => 'newest', 'label' => __('directory.sort.newest')],
                ['value' => 'oldest', 'label' => __('directory.sort.oldest')],
                ['value' => 'name_asc', 'label' => __('directory.sort.name_asc')],
                ['value' => 'name_desc', 'label' => __('directory.sort.name_desc')],
            ],
            /*
             * Which of the viewer's SBOs the Connect / Message buttons act as.
             * Null for a visitor with no listing of their own, which disables
             * them rather than posting a request that would be rejected.
             */
            'mySboId' => $mySboIds->first(),
        ]);
    }

    /**
     * The verified badge on a business profile links here (sbo-verify
     * 223:4647). Public, like the profile it hangs off.
     */
    public function verification(Sbo $business): InertiaResponse
    {
        abort_if('declined' === $business->verification_status, 404);

        return Inertia::render('public/SboVerifyView', [
            'business' => [
                'name' => $business->name,
                'slug' => $business->slug,
                'category' => $business->category,
                'verified' => 'approved' === $business->verification_status,
                'registrationNumber' => $business->moc_registration_number,
                'approvedOn' => $business->date_approved?->format('F j, Y'),
                'address' => mb_trim(implode(', ', array_filter([
                    $business->address, $business->city, $business->country,
                ]))),
            ],
        ]);
    }

    public function indexByCategory(Request $request, Category $category): View
    {
        $categories = Category::orderBy('name')->get();
        $selectedCategory = $category;

        if ($this->shouldGateDirectory()) {
            return $this->lockedDirectoryView($request, $categories, $selectedCategory);
        }

        // SBOs store their category as a name string, not a category_id.
        $cities = Sbo::query()
            ->where('verification_status', '!=', 'declined')
            ->where('category', $category->name)
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        $businesses = Sbo::query()
            ->where('verification_status', '!=', 'declined')
            ->where('category', $category->name)
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->location, fn ($q) => $q->where('city', $request->location))
            ->when('verified' === $request->status, fn ($q) => SboVerificationScopes::fullyVerified($q))
            ->when('unverified' === $request->status, fn ($q) => SboVerificationScopes::notFullyVerified($q))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        // Verified SBOs first (is_verified is a derived accessor).
        $businesses->setCollection(
            $businesses->getCollection()->sortByDesc('is_verified')->values()
        );

        return view('businesses.index', ['businesses' => $businesses, 'categories' => $categories, 'selectedCategory' => $selectedCategory, 'cities' => $cities]);
    }

    /**
     * Category browse — the SPA's `/categories`, which had no Laravel route at
     * all, so every link to it 404'd.
     */
    public function categories(Request $request): InertiaResponse
    {
        $businesses = Sbo::query()
            ->where('verification_status', 'approved')
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            // Free-text search across the fields the box names: SBO name,
            // category, description and location.
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%' . $request->search . '%';
                $q->where(function ($sub) use ($term): void {
                    $sub->where('name', 'like', $term)
                        ->orWhere('category', 'like', $term)
                        ->orWhere('industry', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('city', 'like', $term)
                        ->orWhere('country', 'like', $term)
                        ->orWhere('address', 'like', $term);
                });
            })
            ->latest()
            ->with('media')
            ->paginate(4)
            ->withQueryString();

        /*
         * The band behind the title. Null unless the page is narrowed to one
         * category that has a cover — the screen then falls back to the shared
         * hero video rather than showing a gap.
         */
        $categoryCover = filled($request->query('category'))
            ? Category::query()->where('name', $request->query('category'))->first()?->coverUrl()
            : null;

        return Inertia::render('public/CategoriesView', [
            'categoryCover' => $categoryCover,
            'listings' => [
                'data' => $businesses->getCollection()->map(fn (Sbo $sbo): array => [
                    'name' => $sbo->name,
                    'slug' => $sbo->slug,
                    'body' => $sbo->description,
                    'address' => mb_trim(implode(', ', array_filter([$sbo->address, $sbo->city]))),
                    'size' => filled($sbo->staff_count)
                        ? trans_choice('directory.employee_count', (int) $sbo->staff_count, ['count' => $sbo->staff_count])
                        : null,
                    'tag' => DataLabels::get(DataLabels::CATEGORY, $sbo->category),
                    // The owner's own card artwork; null until uploaded, and
                    // the screen falls back to its stock image.
                    'coverUrl' => $sbo->coverUrl(),
                    'logoUrl' => $sbo->logoUrl(),
                    // Drives the logo ring colour: SBO = blue, COB = gold.
                    'entityType' => $sbo->entity_type,
                ])->values(),
                'links' => $businesses->linkCollection(),
                'meta' => [
                    'current_page' => $businesses->currentPage(),
                    'last_page' => $businesses->lastPage(),
                    'total' => $businesses->total(),
                    'from' => $businesses->firstItem(),
                    'to' => $businesses->lastItem(),
                ],
            ],
            // The sidebar's quick filters, with real counts rather than the
            // four hardcoded ones the screen shipped with.
            'quickFilters' => Category::query()
                ->where('business_count', '>', 0)
                ->orderByDesc('business_count')
                ->take(8)
                ->get()
                ->map(fn (Category $c): array => [
                    // The name is the value the filter uses; the label is that
                    // name in the reader's language. See DataLabels. Both are
                    // sent: filtering on the label would stop working the
                    // moment the reader is not reading English.
                    'value' => $c->name,
                    'label' => DataLabels::get(DataLabels::CATEGORY, $c->name),
                    'count' => (int) $c->business_count,
                ])->values(),
            'filters' => (object) $request->only(['category', 'search']),
        ]);
    }

    public function show(Sbo $business): InertiaResponse
    {
        // Declined registrations are not publicly viewable.
        abort_if('declined' === $business->verification_status, 404);

        /*
         * Services, the media gallery, opening hours and the highlight cards
         * used to be `const` arrays in BusinessProfileView.vue — the same
         * three services and the same "4.5 Score" on all 900+ listings. They
         * are per-SBO records now and are sent from here; the screen renders
         * nothing where a business has supplied nothing.
         *
         * Client ratings remain the one exception: `reviews` is still keyed to
         * the legacy `businesses` model, so Sbo::highlights() omits that card
         * rather than reprint the old placeholder. See INTEGRATION.md.
         */
        $business->loadMissing(['services', 'openingHours', 'addresses', 'media'])
            ->loadCount('services');

        /*
         * Resolve the address to a point so the profile can draw a map. Looked
         * up once per business and cached on the row; see Geocoder for why a
         * failure is silent.
         */
        $business = Geocoder::ensure($business);

        /*
         * The hero's three buttons were all inert: Connect and Disconnect were
         * bare <button>s and Visit Website was href="#". They need to know
         * where the viewer stands with this business, and as which of their own
         * SBOs they would act.
         */
        $viewer = auth()->user();
        $mySboIds = $viewer ? $viewer->sbos()->pluck('id') : collect();
        $rows = SboConnectionStates::rowsBetween($mySboIds, collect([$business->id]));

        $this->recordView($business, $viewer, $mySboIds);

        // Settings › Privacy, read from the owner of the listing rather than
        // the person reading it.
        $showContact = Privacy::allowsFor($business, Privacy::CONTACT_DETAILS);
        $acceptsConnections = Privacy::allowsFor($business, Privacy::CONNECTIONS);

        return Inertia::render('public/BusinessProfileView', [
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                // Stored bare ("example.com") as often as with a scheme, so the
                // screen normalises before linking.
                'website' => $business->website,
                'category' => $business->category,
                'description' => $business->description,
                'verified' => 'approved' === $business->verification_status,
                // The hero photo and the round logo over it. Both were fixed
                // Figma exports shared by every profile in the registry.
                'coverUrl' => $business->coverUrl(),
                'logoUrl' => $business->logoUrl(),
                // Drives the logo ring colour: SBO = blue, COB = gold.
                'entityType' => $business->entity_type,
                'address' => mb_trim(implode(', ', array_filter([
                    $business->address, $business->city, $business->state, $business->country,
                ]))),
                // Rendered as a definition list, so order matters and empty
                // rows are dropped rather than shown as blanks.
                'details' => array_values(array_filter([
                    ['label' => __('profile.details.founded'), 'value' => $business->established_at?->format('Y')],
                    ['label' => __('profile.details.business_type'), 'value' => $business->business_type],
                    ['label' => __('profile.details.legal_structure'), 'value' => $business->legal_structure],
                    ['label' => __('profile.details.registration_no'), 'value' => $business->rc_number],
                    // Cast: staff_count is an integer, but the row is typed as
                    // a string on the Vue side and rendered as free text.
                    ['label' => __('profile.details.company_size'), 'value' => filled($business->staff_count)
                        ? (string) $business->staff_count : null],
                    ['label' => __('profile.details.headquarters'), 'value' => mb_trim(implode(', ', array_filter([
                        $business->city, $business->country,
                    ]))) ?: null],
                    ['label' => __('profile.details.website'), 'value' => $business->website],
                    // Withheld when the owner has turned contact details off
                    // in Settings › Privacy. Filtered out below as blank, so
                    // the row disappears rather than printing an empty value.
                    ['label' => __('profile.details.email'), 'value' => $showContact ? $business->primary_contact_email : null],
                    ['label' => __('profile.details.phone'), 'value' => $showContact ? $business->phone : null],
                ], fn (array $row): bool => ! blank($row['value']))),
            ],
            /*
             * Derived from the same columns the details list above reads, so
             * "Core Staff Size" can no longer contradict "Company Size".
             * Empty when the business has filled in none of them.
             */
            'highlights' => $business->highlights(),
            // Ordered by the owner; body is optional.
            'services' => $business->services->map(fn (SboService $s): array => [
                'title' => $s->title,
                'body' => $s->body,
            ])->all(),
            /*
             * One entry per gallery tab that actually has photos, so a
             * business with only office shots gets one tab, not four.
             */
            'gallery' => collect(Sbo::GALLERY_COLLECTIONS)
                ->map(fn (string $label, string $collection): array => [
                    'label' => __($label),
                    'images' => $business->getMedia($collection)
                        ->map(fn (Media $m): array => [
                            'thumb' => $m->getUrl('thumb'),
                            'full' => $m->getUrl(),
                            'alt' => $m->name,
                        ])->all(),
                ])
                ->filter(fn (array $tab): bool => [] !== $tab['images'])
                ->values()
                ->all(),
            // Monday-first, days the owner never set are omitted.
            'hours' => $business->openingHoursForDisplay(),
            /*
             * The map. Was a grey box reading "Map — pending embed" because
             * nothing on the model could place a pin.
             *
             * OpenStreetMap rather than Google: it needs no API key and no
             * account, and it does not hand a visitor's IP to an advertising
             * network for the privilege of showing a street.
             *
             * `embedUrl` is null when the address could not be resolved, and
             * the screen then shows the address and a search link instead of an
             * empty frame.
             */
            'map' => $this->mapFor($business),
            /*
             * Every place the business trades from, not only the head office.
             * The profile listed one address while the owner could record
             * twelve, so a business with branches in three countries showed
             * three flags on its card and one address on its page.
             */
            'addresses' => $this->addressesFor($business),
            /*
             * Real reviews. The section rendered three invented ones reading
             * "Awaiting backend data" and a form that posted nowhere, with a
             * fixed "4.5/5.0 (12 reviews)" beside the heading.
             */
            'reviews' => $this->reviewsFor($business),
            /*
             * Similar businesses, for the sidebar.
             *
             * Was three invented companies with invented slugs — "cloudtrac",
             * "dma-shelvings", "strategic-workforce" — so every link in the
             * widget led to a 404. These are real listings, nearest first:
             * same category, then same country, then anything approved.
             */
            'similar' => $this->similarTo($business),
            // self / none / pending / accepted — which button the hero offers.
            'connection' => SboConnectionStates::forSbo($mySboIds, array_map(
                fn (array $row): string => $row['status'],
                $rows,
            ), $business->id),
            /*
             * Whether this business takes connection requests at all — see
             * Settings › Privacy. The button is disabled and says why, rather
             * than posting a request the endpoint will refuse.
             */
            'acceptsConnections' => $acceptsConnections,
            // The row Disconnect acts on; null unless actually connected.
            'connectionId' => $rows[$business->id]['id'] ?? null,
            /*
             * Which of the viewer's SBOs Connect acts as. Null for a visitor
             * with no listing, which disables the button rather than posting a
             * request the endpoint would reject.
             */
            'mySboId' => $mySboIds->first(),
            /*
             * Reporting. A verified business only — a report puts a listing in
             * front of a reviewer and can end in suspension, so the power to
             * raise one belongs to members who have been verified themselves.
             */
            'report' => [
                'canReport' => null !== $viewer
                    && (int) $business->created_by !== (int) $viewer->id
                    && app(SboNetworkAccess::class)->verifiedSbosFor($viewer)->isNotEmpty(),
                'isOwn' => null !== $viewer && (int) $business->created_by === (int) $viewer->id,
                'url' => route('sbo.reports.store', $business->slug),
                'reasons' => collect(SboReportController::reasons())
                    ->map(fn (string $label, string $value): array => [
                        'value' => $value,
                        'label' => $label,
                    ])->values(),
            ],
        ]);
    }

    /**
     * Record a profile view, for the owner's analytics.
     *
     * Once per viewer per day, so a refresh is not a second view; the owner's
     * own visits are not counted, because a listing you keep checking on is not
     * a listing anyone found. The viewer is identified by id where possible and
     * otherwise by a hash of address and agent — never the raw values.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $mySboIds
     */
    private function recordView(Sbo $business, ?User $viewer, $mySboIds): void
    {
        if ($mySboIds->contains($business->id)) {
            return;
        }

        $hash = hash('sha256', implode('|', [
            $viewer?->id ?? request()->ip(),
            request()->userAgent() ?? '',
            // Day-scoped, which is what makes the uniqueness check a
            // once-a-day rule rather than a once-ever one.
            now()->toDateString(),
        ]));

        if (SboView::where('sbo_id', $business->id)->where('viewer_hash', $hash)->exists()) {
            return;
        }

        SboView::create([
            'sbo_id' => $business->id,
            // Counted either way — the owner's analytics are theirs — but
            // without a name attached when the viewer has opted out.
            'viewer_id' => Privacy::allows($viewer, Privacy::VIEW_IDENTITY) ? $viewer?->id : null,
            'viewer_hash' => $hash,
            'viewed_at' => now(),
        ]);
    }

    private function shouldGateDirectory(): bool
    {
        $user = auth()->user();

        return $user
            && app(SboWorkspaceAccess::class)->requiresVerification($user);
    }

    private function lockedDirectoryView(Request $request, $categories, ?Category $selectedCategory, bool $fromHome = false): View
    {
        $access = app(SboWorkspaceAccess::class);
        $targetSbo = $access->paymentTarget(auth()->user());

        return view('businesses.index', [
            'businesses' => new LengthAwarePaginator(
                collect(),
                0,
                10,
                LengthAwarePaginator::resolveCurrentPage(),
                ['path' => $request->url(), 'query' => $request->query()]
            ),
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'cities' => collect(),
            'fromHome' => $fromHome,
            'paymentAccessRequired' => true,
            'paymentProgressUrl' => $targetSbo
                ? route('sbo.applications.progress', $targetSbo)
                : route('sbo.applications.index'),
        ]);
    }

    /**
     * The OpenStreetMap embed and link for a business.
     *
     * The embed needs a bounding box, not a centre — roughly 500m around the
     * point, which frames a street without losing the neighbourhood.
     *
     * @return array{address: string, latitude: float|null, longitude: float|null, embedUrl: string|null, linkUrl: string}
     */
    /**
     * Every trading address, primary first, each with a way to find it.
     *
     * Geocoded lazily and cached on the row — a profile with twelve branches
     * must not make twelve Nominatim calls per view, which is both slow and
     * against their usage policy. `ensureAddress` returns immediately once a
     * row has a point for its current address.
     *
     * @return list<array<string, mixed>>
     */
    private function addressesFor(Sbo $business): array
    {
        return $business->addresses->map(function (SboAddress $address) use ($business): array {
            $address = Geocoder::ensureAddress($address);
            $line = $address->line();

            return [
                'id' => $address->id,
                'label' => $address->label,
                'line' => $line,
                'isPrimary' => $address->is_primary,
                // One flag per address here, not per country: this is a list of
                // places, and two offices in Nigeria are two places.
                'countryCode' => $address->country_code,
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
                /*
                 * A search link rather than a pin URL: it finds the place even
                 * when the point could not be resolved, which is the case for
                 * a new development or a rural address.
                 */
                'mapUrl' => '' === $line
                    ? null
                    : 'https://www.openstreetmap.org/search?query=' . urlencode($line),
                'directionsUrl' => '' === $line
                    ? null
                    : 'https://www.google.com/maps/search/?api=1&query=' . urlencode($business->name . ', ' . $line),
            ];
        })->values()->all();
    }

    /**
     * The reviews on a listing, and what the viewer may do about them.
     *
     * @return array<string, mixed>
     */
    private function reviewsFor(Sbo $business): array
    {
        $viewer = auth()->user();

        $reviews = Review::query()
            ->with('user')
            ->where('sbo_id', $business->id)
            ->latest()
            ->take(10)
            ->get();

        $all = Review::query()->where('sbo_id', $business->id);
        $count = (clone $all)->count();

        return [
            'items' => $reviews->map(fn (Review $review): array => [
                'id' => $review->id,
                'author' => $review->user?->name ?? __('reviews.a_member'),
                'initials' => mb_strtoupper(mb_substr((string) ($review->user?->name ?? '?'), 0, 1)),
                'rating' => (int) $review->rating,
                'comment' => $review->comment,
                'at' => Timezones::format($review->created_at, 'M j, Y'),
                'mine' => null !== $viewer && $review->user_id === $viewer->id,
            ])->all(),
            'count' => $count,
            // One decimal, or null when there is nothing to average — a
            // listing with no reviews is not a listing rated 0.
            'average' => $count > 0 ? round((float) (clone $all)->avg('rating'), 1) : null,
            /*
             * Why the form is or is not offered, so the screen can say which
             * rather than showing a control that will be refused.
             */
            'canReview' => null !== $viewer
                && $viewer->hasVerifiedEmail()
                && $business->created_by !== $viewer->id,
            'isOwnListing' => null !== $viewer && $business->created_by === $viewer->id,
            'mine' => $viewer
                ? $reviews->firstWhere('user_id', $viewer->id)?->only(['rating', 'comment'])
                : null,
            'postUrl' => route('businesses.reviews.store', $business->slug),
        ];
    }

    private function mapFor(Sbo $business): array
    {
        $address = Geocoder::addressFor($business);
        $attributes = $business->getAttributes();
        $lat = array_key_exists('latitude', $attributes) ? $business->latitude : null;
        $lon = array_key_exists('longitude', $attributes) ? $business->longitude : null;

        if (null === $lat || null === $lon) {
            return [
                'address' => $address,
                'latitude' => null,
                'longitude' => null,
                'embedUrl' => null,
                // Still useful: a search link finds the place even when we
                // could not resolve it to a pin.
                'linkUrl' => 'https://www.openstreetmap.org/search?query=' . urlencode($address),
            ];
        }

        $delta = 0.005; // ~500m

        $bbox = implode(',', [
            $lon - $delta,
            $lat - $delta,
            $lon + $delta,
            $lat + $delta,
        ]);

        return [
            'address' => $address,
            'latitude' => (float) $lat,
            'longitude' => (float) $lon,
            'embedUrl' => 'https://www.openstreetmap.org/export/embed.html?bbox='
                . $bbox . '&layer=mapnik&marker=' . $lat . ',' . $lon,
            'linkUrl' => 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '#map=17/' . $lat . '/' . $lon,
        ];
    }

    /**
     * Up to three real listings related to `$business`.
     *
     * Widened in steps rather than filtered in one query, because a category
     * with only one member should still fill the widget — an empty sidebar
     * teaches visitors the registry is empty.
     *
     * @return list<array<string, mixed>>
     */
    private function similarTo(Sbo $business): array
    {
        $base = fn (): \Illuminate\Database\Eloquent\Builder => Sbo::query()
            ->where('verification_status', 'approved')
            ->whereKeyNot($business->id)
            ->with('media');

        $picked = collect();

        // Same category first — that is what "similar" means to a visitor.
        if (filled($business->category)) {
            $picked = $base()->where('category', $business->category)->take(3)->get();
        }

        // Then the same country, so a Lagos visitor sees Lagos businesses.
        if ($picked->count() < 3 && filled($business->country)) {
            $picked = $picked->concat(
                $base()->whereKeyNot($picked->pluck('id')->all())
                    ->where('country', $business->country)
                    ->take(3 - $picked->count())
                    ->get()
            );
        }

        // Then anything, rather than leaving the widget short.
        if ($picked->count() < 3) {
            $picked = $picked->concat(
                $base()->whereKeyNot($picked->pluck('id')->all())
                    ->latest()
                    ->take(3 - $picked->count())
                    ->get()
            );
        }

        return $picked->map(fn (Sbo $s): array => [
            'name' => $s->name,
            'slug' => $s->slug,
            'tagline' => mb_trim(implode(', ', array_filter([$s->category, $s->city]))) ?: null,
            // Null falls back to the stock tile on the screen.
            'logoUrl' => $s->logoUrl(),
            'coverUrl' => $s->coverUrl(),
            // Drives the logo ring colour: SBO = blue, COB = gold.
            'entityType' => $s->entity_type,
        ])->values()->all();
    }
}
