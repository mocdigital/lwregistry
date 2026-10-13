<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\SboMessageAttachment;
use App\Support\DataLabels;
use App\Support\EspeesMerchantGateway;
use App\Support\IndustryGroupChat;
use App\Support\Locales;
use App\Support\SboConnectionStates;
use App\Support\SboFeePayload;
use App\Support\SboFeeSelection;
use App\Support\SboNetworkAccess;
use App\Support\SboWorkspaceAccess;
use App\Support\Timezones;
use App\Support\Translation\Translator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The signed-in SBO workspace, served from Vue.
 *
 * These routes were `Route::view(...)` pointing at Blade wrappers around
 * Livewire components, which is why signing in still landed on the old design
 * even after the public site had moved over.
 *
 * The Livewire components (`sbo.rfqs.index`, `sbo.categories.index`, the
 * network and dashboard partials) are left in place — nothing else routes to
 * them yet, but they are the reference for the behaviour these screens still
 * need. Each screen below carries real identity and real lists where the
 * models support it; the panels that have no backing are noted in the views.
 */
final class SboWorkspaceController extends Controller
{
    /** Rows per page on the dashboard table; the design's pagination reads 1-8. */
    private const DASHBOARD_PER_PAGE = 8;

    public function dashboard(Request $request, SboWorkspaceAccess $access): InertiaResponse
    {
        $user = auth()->user();
        $sbos = $user->sbos()->orderBy('name')->get();
        $sbo = $access->paymentTarget($user) ?? $sbos->first();

        $sboIds = $sbos->pluck('id');

        // "My Procurement" — RFQs this owner posted.
        $mine = PurchaseOrder::query()
            ->with('category')->withCount('bids')
            ->whereIn('sbo_id', $sboIds)
            ->latest();

        // "My Bids" — RFQs this owner has bid on.
        $bidOn = PurchaseOrder::query()
            ->with('category')->withCount('bids')
            ->whereHas('bids', fn ($q) => $q->where('user_id', $user->id))
            ->latest();

        /*
         * The tab is a query parameter rather than local state, because the
         * table under it is paginated: "Showing 1-8 of 114 businesses" and the
         * page numbers 1, 2 … 15 were fixed strings, on a table that could
         * hold any number of rows and named "businesses" in a list of RFQs.
         */
        $tab = 'bids' === $request->query('tab') ? 'bids' : 'procurement';

        $rows = ('bids' === $tab ? $bidOn : $mine)
            ->paginate(self::DASHBOARD_PER_PAGE)
            ->withQueryString();

        return Inertia::render('sbo/SboDashboardView', [
            // 'SBO' or 'COB', for the workspace's business. See useEntityTerm.
            'entityTerm' => $sbo?->entity_type ?: Sbo::ENTITY_SBO,
            'user' => ['name' => mb_trim($user->firstname . ' ' . $user->lastname)],
            'sbo' => $sbo ? $this->identity($sbo) : null,
            'tab' => $tab,
            'rows' => [
                'data' => $rows->getCollection()->map(fn (PurchaseOrder $o): array => $this->row($o))->values(),
                'links' => $rows->linkCollection(),
                'meta' => [
                    'current_page' => $rows->currentPage(),
                    'last_page' => $rows->lastPage(),
                    'total' => $rows->total(),
                    'from' => $rows->firstItem(),
                    'to' => $rows->lastItem(),
                ],
            ],
            // Both counts, so each tab can carry its own without loading it.
            'counts' => [
                'procurement' => (clone $mine)->count(),
                'bids' => (clone $bidOn)->count(),
            ],
        ]);
    }

    /**
     * "New Listing" — add another SBO to an existing account.
     *
     * The button pointed at /register, which is guest-only, so a signed-in
     * owner was bounced to the dashboard and could never add a second listing.
     * Registration creates a User *and* an Sbo; this creates only the Sbo,
     * owned by whoever is signed in.
     */
    /**
     * The add-business page. One view: the business-details form, and — once a
     * business has just been saved (?pay={sbo}) — the fee + payment revealed on
     * the SAME page. Payment can only render for a saved business the current
     * user owns.
     */
    public function createListing(Request $request): InertiaResponse
    {
        $user = $request->user();

        $paySbo = $request->filled('pay')
            ? Sbo::query()
                ->whereKey($request->integer('pay'))
                ->where('created_by', $user->id)
                ->first()
            : null;

        return Inertia::render('auth/RegisterWizardView', [
            'categories' => Category::orderBy('name')->pluck('name')->values(),
            'countries' => \App\Models\Country::orderBy('name')->pluck('name')->values(),
            'zones' => \App\Models\Zone::where('is_active', true)->orderBy('name')->pluck('name')->values(),
            'ministries' => \App\Models\Ministry::activeNames(),
            'legalStructures' => [
                'Sole Proprietorship/Partnership',
                'Private Limited Company (Ltd)',
                'Public Limited Company (Plc)',
                'Company Limited by Guarantee (Ltd/Gtd)',
                'Incorporated Trustees (NGO/Association)',
            ],
            'dialCodes' => \App\Support\PhoneNumbers::dialCodesByCountry(),
            'countryIsoCodes' => \App\Support\PhoneNumbers::isoCodesByCountry(),
            'countriesByDialCode' => \App\Support\PhoneNumbers::countriesByDialCode(),
            // Tells the wizard to skip the owner-account step: this account
            // already exists, so there is no password to set.
            'mode' => 'listing',
            // Once the business is saved, the same page reveals its payment.
            'payStep' => $paySbo instanceof Sbo,
            'sbo' => $paySbo instanceof Sbo ? [
                'id' => $paySbo->id,
                'name' => $paySbo->name,
                'entityType' => $paySbo->entity_type,
            ] : null,
            'payFees' => $paySbo instanceof Sbo ? SboFeePayload::for($paySbo, $user) : null,
        ]);
    }

    /**
     * Save the business AND take its payment in one go.
     *
     * The add-business screen shows the payment methods inline, so nothing is
     * saved by merely filling the form — pressing a payment button is what
     * creates the business, prices it, and then runs that payment.
     */
    public function storeListing(Request $request): \Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $user = $request->user();

        $data = $request->validate([
            'bizName' => ['required', 'string', 'max:255'],
            // Same field as registration; see Sbo::logoUploadRules().
            'bizLogo' => Sbo::logoUploadRules(),
            // Same business step as the register wizard, so the same fields.
            'bizTradingName' => ['nullable', 'string', 'max:255'],
            'bizRegistrationNumber' => ['required', 'string', 'max:255'],
            'bizDescription' => ['required', 'string', 'max:5000'],
            'bizEntityType' => ['required', 'in:SBO,COB'],
            'bizMinistry' => ['nullable', 'required_if:bizEntityType,SBO', 'string', 'exists:ministries,name'],
            'bizLegalStructure' => ['required', 'string', 'max:255'],
            'bizPremiseType' => ['nullable', 'in:physical,virtual,both'],
            'bizAddress' => ['required', 'string', 'max:255'],
            'bizState' => ['required', 'string', 'max:255'],
            'bizPhone' => ['required', 'string', 'max:30'],
            'bizEmail' => ['required', 'email', 'max:255'],
            'bizWebsite' => ['nullable', 'string', 'max:255'],
            'bizNature' => ['required', 'string', 'exists:categories,name'],
            'bizYearEstablished' => ['nullable', 'integer', 'min:1800', 'max:' . date('Y')],
            'bizEmployees' => ['nullable', 'integer', 'min:0'],
            'bizCountry' => ['required', 'string', 'exists:countries,name'],
            // The Code of Conduct, agreed here rather than at sign-up — it is
            // about operating a registered business, which is what this creates.
            'agreeCode' => ['accepted'],
            /*
             * The registration-fee selection from the calculator on this same
             * form. Optional: an applicant who skips it simply picks the
             * structure on the payment step instead. The fee itself is never
             * taken from the client — SboFeeSelection recomputes it.
             */
            'bizShareStructure' => ['nullable', 'string'],
            'bizShareCapitalType' => ['nullable', 'required_if:bizShareStructure,private,public', 'string'],
            'bizFeeBandId' => ['nullable', 'required_if:bizShareStructure,private,public', 'integer'],
            'bizPerShareValue' => ['nullable', 'required_if:bizShareStructure,private,public', 'numeric', 'min:0.01'],
            /*
             * Which payment button was pressed — required, because pressing one
             * is the only thing that saves a business. The payment section sits
             * on this same form un-gated, so the intent travels with the
             * business details rather than being a second request against a
             * record that does not exist yet. Fill the form and walk away and
             * nothing is stored.
             */
            'payWith' => ['required', 'in:espees,manual'],
            // mimetypes: as well as mimes:, matching exactly what the
            // payment_proof collection accepts — see storePaymentProof.
            'proof' => ['nullable', 'required_if:payWith,manual', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'agreeCode.accepted' => 'You must read and agree to the Kingdom Business Code of Conduct.',
            'payWith.required' => 'Choose how you want to pay your registration fee.',
        ]);

        $base = \Illuminate\Support\Str::slug($data['bizName']);
        $slug = $base;
        $i = 1;

        while (Sbo::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        $sbo = Sbo::create([
            'name' => $data['bizName'],
            'trading_name' => $data['bizTradingName'] ?? null,
            'registration_number' => $data['bizRegistrationNumber'],
            'description' => $data['bizDescription'],
            'slug' => $slug,
            'entity_type' => $data['bizEntityType'],
            'ministry' => $data['bizMinistry'] ?? null,
            'legal_structure' => $data['bizLegalStructure'],
            'business_type' => $data['bizNature'],
            'category' => $data['bizNature'],
            'premise_type' => $data['bizPremiseType'] ?? null,
            'address' => $data['bizAddress'],
            'state' => $data['bizState'],
            'country' => $data['bizCountry'],
            'country_id' => \App\Models\Country::where('name', $data['bizCountry'])->value('id'),
            'established_at' => filled($data['bizYearEstablished'] ?? null) ? $data['bizYearEstablished'] . '-01-01' : null,
            'website' => $data['bizWebsite'] ?? null,
            'staff_count' => $data['bizEmployees'] ?? null,
            'primary_contact_name' => mb_trim($user->firstname . ' ' . $user->lastname),
            'primary_contact_email' => $data['bizEmail'],
            'phone' => $data['bizPhone'],
            // The member's own details, carried from their account so they are
            // entered once at registration and inherited by every business.
            'owner_surname' => $user->lastname,
            'owner_first_name' => $user->firstname,
            'owner_country' => $user->country,
            'owner_residential_address' => $user->residential_address,
            'church_zone' => $user->church_zone,
            'zone_id' => $user->zone_id,
            'referred_via_code' => $user->referred_via_code,
            'local_assembly' => $user->local_assembly,
            'years_as_member' => $user->years_as_member,
            'zonal_pastor' => $user->zonal_pastor,
            'local_pastor' => $user->local_pastor,
            'kingschat_handle' => $user->kc_handle,
            'code_of_conduct_agreed_at' => now(),
            // A new listing starts unverified and unpaid.
            'is_active' => true,
            'created_by' => $user->id,
            'status' => 'active',
            'verification_status' => 'pending',
            'documents_status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        if ($request->hasFile('bizLogo')) {
            $sbo->addMediaFromRequest('bizLogo')->toMediaCollection(Sbo::LOGO_COLLECTION);
        }

        /*
         * Carry the fee chosen on this form through to the business, so the
         * payment revealed below is already priced and the applicant is not
         * asked to pick the same share structure a second time.
         */
        if (filled($data['bizShareStructure'] ?? null)) {
            SboFeeSelection::apply($sbo, [
                'share_structure' => $data['bizShareStructure'],
                'share_capital_type' => $data['bizShareCapitalType'] ?? null,
                'fee_band_id' => $data['bizFeeBandId'] ?? null,
                'per_share_value' => $data['bizPerShareValue'] ?? null,
            ]);
        }

        /*
         * The business is saved; now run the payment the applicant pressed.
         *
         * Espees hands off to the gateway and comes back to this same view via
         * ?pay=. If they abandon the checkout, the business is already here as
         * an unpaid draft they can return to and pay — which is the whole
         * reason saving happens at this moment rather than at a separate step.
         */
        if ('espees' === ($data['payWith'] ?? null)) {
            return app(SboEspeesPaymentController::class)
                ->start($request, $sbo, app(EspeesMerchantGateway::class));
        }

        if ('manual' === ($data['payWith'] ?? null)) {
            $this->attachPaymentProof($request, $sbo);
        }

        // Same page, payment shown in place: reload the add-business view with
        // the just-saved business so its fee and payment state show there.
        return redirect()->route('sbo.listings.create', ['pay' => $sbo->id])
            ->with('status', 'listing-created');
    }

    /**
     * File the manual proof uploaded alongside the business details.
     *
     * Mirrors SboNetworkWriteController@storePaymentProof — the fee is flagged
     * as awaiting an admin decision, not paid, so the documents step stays shut
     * until someone approves it.
     */
    private function attachPaymentProof(Request $request, Sbo $sbo): void
    {
        $proof = \App\Models\SboPaymentProof::firstOrNew(['sbo_id' => $sbo->id]);

        $proof->forceFill([
            'status' => 'pending',
            'rejection_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();

        /*
         * SboPaymentProof::COLLECTION, not the literal 'proofs'. Only the
         * registered collection is routed to the private `compliance` disk and
         * read back by SboPaymentProof::file() — which is what every reviewer
         * screen asks. A file filed anywhere else is invisible to them and sits
         * on the PUBLIC disk.
         */
        $proof->addMediaFromRequest('proof')->toMediaCollection(\App\Models\SboPaymentProof::COLLECTION);

        $sbo->forceFill(['payment_submitted_at' => now()])->save();
    }

    /**
     * Delete one of the owner's own listings — the bin icon on /account.
     *
     * Refused once the registration is approved: that is a live entry other
     * members can see and connect to, and on a paid platform it is also the
     * owner's own membership. Withdrawing one is a support conversation, not a
     * one-click action.
     */
    public function destroyListing(Request $request, Sbo $sbo): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $sbo->created_by === (int) $request->user()->id, 403);

        if ('approved' === $sbo->verification_status) {
            return back()->with('status', 'listing-delete-refused');
        }

        $sbo->delete();

        return redirect()->route('account.index')->with('status', 'listing-deleted');
    }

    /** `my-rfqs` — the owner's own requests, not the global list. */
    public function rfqs(): InertiaResponse
    {
        $sboIds = auth()->user()->sbos()->pluck('id');

        $orders = PurchaseOrder::query()
            ->with(['category', 'sbo'])
            ->withCount('bids')
            ->whereIn('sbo_id', $sboIds)
            ->latest()
            ->paginate(10);

        return Inertia::render('procurement/ProcurementListView', [
            'requests' => [
                'data' => $orders->getCollection()->map(fn (PurchaseOrder $o): array => $this->rfq($o))->values(),
                'links' => $orders->linkCollection(),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
            ],
            'facets' => [],
            'statusFacets' => [],
            'filters' => (object) [],
            'sort' => 'recent',
            'sorts' => [],
            // Same screen, different scope: this one is "mine", not "all open".
            'scope' => 'mine',
        ]);
    }

    /**
     * The SBO Network — connections, requests and the messages inbox.
     *
     * `$request->query('conversation')` selects the open thread; without it the
     * most recently active one opens, which is what the design draws.
     */
    public function network(Request $request, string $tab = 'connections'): InertiaResponse
    {
        $user = $request->user();
        $sboIds = $user->sbos()->pluck('id');
        /*
         * The SBO every write on this screen is made as. This was the owner's
         * first SBO alphabetically, which could easily be one that cannot use
         * the network at all — an owner with an unpaid "Acme" and a verified
         * "Zion" would send connection requests and messages as Acme. Network
         * -eligible ones first, falling back to any so the screen still names
         * an SBO in states the gate lets through.
         */
        $mySbo = app(SboNetworkAccess::class)->verifiedSbosFor($user)->first()
            ?? $user->sbos()->orderBy('name')->first();
        $search = mb_trim((string) $request->query('q', ''));

        /*
         * `requesterSbo` / `recipientSbo` were eager-loaded and read here, but
         * SboConnection names those relations `requester` and `recipient`. Every
         * one of those calls threw, so the whole network screen 500'd.
         */
        $accepted = SboConnection::query()
            ->with(['requester', 'recipient'])
            ->where('status', SboConnection::STATUS_ACCEPTED)
            ->where(fn ($q) => $q->whereIn('requester_sbo_id', $sboIds)->orWhereIn('recipient_sbo_id', $sboIds))
            ->get()
            ->map(fn (SboConnection $c): ?array => $this->counterpart($c, $sboIds))
            ->filter()->values();

        // Incoming, still pending.
        $pending = SboConnection::query()
            ->with('requester')
            ->where('status', SboConnection::STATUS_PENDING)
            ->whereIn('recipient_sbo_id', $sboIds)
            ->get()
            ->map(fn (SboConnection $c): ?array => $c->requester
                ? $this->card($c->requester, $c->id, $c->requester_sbo_id)
                : null)
            ->filter()->values();

        $conversations = SboConversation::query()
            ->visibleTo($sboIds)
            ->with([
                'sboConnection.requester',
                'sboConnection.recipient',
                // .creator for the avatar stack's member photos.
                'participants.creator',
                'messages' => fn ($q) => $q->latest()->limit(1),
                'messages.sbo',
            ])
            // The header's star, per viewer.
            ->withExists(['starredBy as starred' => fn ($q) => $q->whereKey($user->id)])
            ->withCount([
                'messages as unread_count' => fn ($q) => $q
                    ->whereNull('read_at')->where('user_id', '!=', $user->id),
                /*
                 * Businesses only. The registry sits in every room as its
                 * admin, and this number is telling a member how many peers
                 * are in there with them — counting staff would inflate every
                 * room by one and mean something slightly untrue.
                 */
                'participants as participant_count' => fn ($q) => $q->where('sbos.is_registry', false),
            ])
            /*
             * The industry room is pinned above the rest. It is the one thread
             * nobody opened deliberately — everyone is put in it on
             * verification — so left in date order it would sink out of sight
             * the first time two people had a quiet week.
             */
            ->orderByRaw('case when type = ? then 0 else 1 end', [SboConversation::TYPE_INDUSTRY])
            ->orderByDesc('last_message_at')
            ->take(50)
            ->get();

        $tab = in_array($tab, ['connections', 'requests', 'messages'], true) ? $tab : 'connections';

        $active = $this->activeConversation($conversations, $request->query('conversation'));

        /*
         * Opening a thread reads it — but only on the messages tab. The other
         * two tabs still carry the inbox in their payload (the tab bar shows
         * the unread count), and marking read there would clear the badge for
         * a thread the owner never looked at.
         */
        if ($active && 'messages' === $tab) {
            SboMessage::where('conversation_id', $active->id)
                ->where('user_id', '!=', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $active->unread_count = 0;
        }

        $shaped = $conversations
            ->map(fn (SboConversation $c): array => $this->conversation($c, $sboIds, $active?->id))
            ->values();

        return Inertia::render('sbo/SboNetworkView', [
            'tab' => $tab,
            'counts' => [
                'connections' => $accepted->count(),
                'requests' => $pending->count(),
                // The tab's subtitle counts unread *messages*; the inbox chip
                // counts conversations. Both are real now (it was hardcoded 5).
                'unread' => (int) $shaped->sum('unread'),
            ],
            'connections' => $accepted,
            'requests' => $pending,
            /*
             * The signed-in owner's SBO — every write on this screen is "as"
             * one of my SBOs, and until now the screen had no idea which.
             */
            'mySbo' => $mySbo ? ['id' => $mySbo->id, 'name' => $mySbo->name] : null,
            'userId' => (string) $user->id,
            'conversations' => $shaped,
            'activeConversationId' => $active ? (string) $active->id : null,
            /*
             * Whether the reader ASKED for this conversation, as opposed to it
             * being the newest one opened for them.
             *
             * On desktop that distinction does not matter — both panes are on
             * screen and an empty right-hand pane is worse than a sensible
             * default. On a phone only one pane shows, so an auto-selected
             * conversation would mean the inbox never appeared at all: the app
             * would open inside somebody's last chat.
             */
            'conversationRequested' => filled($request->query('conversation')),
            'thread' => $active ? $this->thread($active, $user->id) : [],
            /*
             * Who else is in the open conversation, so a member can take one of
             * them aside. Only sent for the conversation actually open — the
             * roster of every room in the list would be a query per row to
             * draw a menu nobody has clicked.
             *
             * The viewer is excluded: you cannot take yourself aside, and
             * offering it would only produce a validation error.
             */
            'roster' => $active ? $this->roster($active, $mySbo?->id) : [],
            /*
             * What the Group Info panel shows. Only for the conversation
             * actually open, and only what is already in the thread — the
             * media, files and links are the ones that have been sent here,
             * not a second store of anything.
             */
            'groupInfo' => $active ? $this->groupInfo($active, $mySbo?->id) : null,
            /*
             * Who compose can start a conversation with: accepted connections
             * that do not already have one open.
             */
            'composeTargets' => $this->composeTargets($accepted, $shaped),
            /*
             * Reporting a message. Verified businesses only — the same bar as
             * reporting a listing, because a report puts the sender in front of
             * a reviewer.
             */
            'canReport' => app(SboNetworkAccess::class)->verifiedSbosFor($user)->isNotEmpty(),
            /*
             * Whether to offer "See translation" on incoming messages. False
             * until a provider is configured, and the chat then hides the
             * control rather than showing one that always fails.
             */
            'canTranslate' => app(Translator::class)->isConfigured(),
            'reportReasons' => collect(SboReportController::reasons())
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values(),
            /*
             * The inbox search searches the registry, not just the threads
             * already open. `q` is the term; results carry the connection
             * state so each row can offer the action that is actually
             * possible — Message an accepted connection, or Connect first.
             */
            'q' => $search,
            'people' => $this->people($search, $sboIds, $conversations),
            'categories' => Category::orderBy('name')->pluck('name')->values(),
            'countries' => Sbo::whereNotNull('country')->where('country', '!=', '')
                ->distinct()->orderBy('country')->pluck('country')->values(),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SboConversation>  $conversations
     */
    private function activeConversation(
        \Illuminate\Support\Collection $conversations,
        mixed $requested,
    ): ?SboConversation {
        if (is_string($requested) || is_int($requested)) {
            $match = $conversations->firstWhere('id', (int) $requested);

            if ($match) {
                return $match;
            }
        }

        /*
         * Most recently active, not first in the list.
         *
         * The industry room is pinned to the top of the rail, and taking the
         * first row would have made it the thread that opens by default too —
         * so a member arriving at /messages would land in a room of hundreds
         * instead of the conversation somebody just sent them. Pinned position
         * and open-by-default are different things.
         */
        return $conversations
            ->sortByDesc(fn (SboConversation $c) => $c->last_message_at?->getTimestamp() ?? 0)
            ->first();
    }

    /**
     * One row in the inbox rail.
     *
     * `category` is what the Unread / Connections / Groups chips filter on:
     * `direct` conversations are Connections, anything else (bid threads, and
     * any future group) is Groups. Unread is orthogonal — it is a count, not a
     * type — so it is sent separately and the chip reads both.
     *
     * @param  iterable<int>  $sboIds
     * @return array<string, mixed>
     */
    private function conversation(SboConversation $c, iterable $sboIds, ?int $activeId): array
    {
        $latest = $c->messages->first();
        $counterpart = $this->conversationCounterpart($c, $sboIds);

        $isIndustry = SboConversation::TYPE_INDUSTRY === $c->type;
        $isGroup = SboConversation::TYPE_GROUP === $c->type;
        // Both the category rooms and member-created groups are "rooms": named,
        // multi-party, and shown with a roster rather than a single counterpart.
        $isRoom = $isIndustry || $isGroup;
        $ids = collect($sboIds);

        $members = (int) ($c->participant_count
            ?? $c->participants->reject(fn (Sbo $p): bool => (bool) $p->is_registry)->count());

        return [
            'id' => (string) $c->id,
            'name' => match (true) {
                $isIndustry && (bool) $c->industry => IndustryGroupChat::name($c->industry),
                $isGroup => $c->name ?: 'Group',
                default => $counterpart?->name ?? ($latest?->sbo?->name ?? 'Conversation'),
            },
            'org' => match (true) {
                // The design's second line is "industry, location" for a direct
                // thread. A room has no one location, so it carries the thing a
                // member actually wants to know: how many businesses are in it.
                $isRoom => mb_trim(implode(' · ', array_filter([
                    $isIndustry ? $c->industry : null,
                    trans_choice('network.member_count', $members, ['count' => $members]),
                ]))),
                null !== $counterpart => mb_trim(implode(', ', array_filter([
                    DataLabels::get(DataLabels::CATEGORY, $counterpart->category),
                    $counterpart->city,
                    DataLabels::country($counterpart->country),
                ]))),
                default => null,
            },
            'slug' => $counterpart?->slug,
            'preview' => str($latest?->body ?? 'No messages yet')->limit(60)->toString(),
            'time' => $c->last_message_at?->diffForHumans(short: true),
            'unread' => (int) ($c->unread_count ?? 0),
            'category' => SboConversation::TYPE_DIRECT === $c->type ? 'connections' : 'groups',
            // The "+N" on the avatar stack (509:52716) is the real roster.
            'participants' => $members,
            /*
             * Who else is in the room, so the row's menu can offer each by
             * name. Private messaging used to be reachable only from inside an
             * open group, which is not somewhere anyone thinks to look.
             *
             * Rooms only, and capped: a room of three hundred is a menu nobody
             * can use, and the roster inside the open conversation is the place
             * to find someone in a crowd.
             */
            'members' => $isRoom
                ? $c->participants
                    ->reject(fn (Sbo $p): bool => $ids->contains($p->id))
                    ->sortByDesc(fn (Sbo $p): bool => (bool) $p->is_registry)
                    ->take(12)
                    ->map(fn (Sbo $p): array => [
                        'sboId' => $p->id,
                        'name' => $p->name,
                        'isAdmin' => (bool) $p->is_registry,
                    ])
                    ->values()
                    ->all()
                : [],
            'group' => $isRoom,
            // Which kind of room, so the group panel knows whether the viewer
            // can manage the roster (their own group) or not (a category room).
            'kind' => match (true) {
                $isIndustry => 'industry',
                $isGroup => 'group',
                default => 'direct',
            },
            'isOwner' => $isGroup && $ids->contains($c->created_by_sbo_id),
            // The category room is pinned above the rest; a member's own group
            // sits in date order like a normal conversation.
            'pinned' => $isIndustry,
            'starred' => (bool) ($c->starred ?? false),
            /*
             * Up to three member photos for the stack (509:52701). Only the
             * owners who have uploaded one; the rest of the discs fall back to
             * the design's own user glyph.
             */
            'memberPhotos' => $isRoom ? $this->memberPhotos($c) : [],
            'active' => $activeId === $c->id,
        ];
    }

    /**
     * A few members' photos for the group avatar stack.
     *
     * @return array<int, string>
     */
    private function memberPhotos(SboConversation $c): array
    {
        return $c->participants
            ->take(3)
            ->map(fn (Sbo $sbo): ?string => $sbo->creator?->profilePhotoUrl())
            ->filter()
            ->values()
            ->all();
    }

    /** The other SBO in a direct conversation; null for a group thread. */
    private function conversationCounterpart(SboConversation $c, iterable $sboIds): ?Sbo
    {
        // Group rooms have no single counterpart.
        if (SboConversation::TYPE_DIRECT !== $c->type) {
            return null;
        }

        $ids = collect($sboIds);
        $connection = $c->sboConnection;

        if (null !== $connection) {
            return $ids->contains($connection->requester_sbo_id)
                ? $connection->recipient
                : $connection->requester;
        }

        /*
         * A direct thread with no connection behind it — the registry ("message
         * admin"), or a thread opened from a shared room. The counterpart is
         * simply the other participant. Without this the name fell back to the
         * last message's sender, so a member's own first message to the admin
         * made the thread show the member's name instead of the registry's.
         */
        return $c->participants->first(fn (Sbo $p): bool => ! $ids->contains($p->id));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * The other businesses in a conversation.
     *
     * @return array<int, array{sboId: int, name: string, slug: string|null, isAdmin: bool}>
     */
    private function roster(SboConversation $conversation, ?int $mySboId): array
    {
        return $conversation->participants()
            ->when(null !== $mySboId, fn ($q) => $q->whereKeyNot($mySboId))
            ->orderBy('name')
            ->get(['sbos.id', 'sbos.name', 'sbos.slug', 'sbos.is_registry'])
            // The registry owns every room, so it leads the roster rather than
            // sitting wherever the alphabet puts it.
            ->sortByDesc(fn (Sbo $sbo): bool => (bool) $sbo->is_registry)
            ->map(fn (Sbo $sbo): array => [
                'sboId' => $sbo->id,
                'name' => $sbo->name,
                // Nothing to visit: the registry's listing is deliberately not
                // approved, so it has no public profile.
                'slug' => $sbo->is_registry ? null : $sbo->slug,
                'isAdmin' => (bool) $sbo->is_registry,
            ])
            ->values()
            ->all();
    }

    /**
     * The Group Info panel: who is in the room, and what has been shared in it.
     *
     * Media, files and links are read back out of the conversation rather than
     * tracked separately — a second index of the same thing is a second thing
     * to keep correct, and it would be wrong the first time somebody deleted a
     * message.
     *
     * There is no "13 online". Nothing in the app tracks presence, and a
     * number that is always wrong is worse than no number.
     *
     * @return array<string, mixed>
     */
    private function groupInfo(SboConversation $conversation, ?int $mySboId): array
    {
        $messages = SboMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('deleted_for_all_at')
            ->with(['attachments', 'sbo'])
            ->latest()
            ->take(300)
            ->get();

        $attachments = $messages->flatMap(fn (SboMessage $m) => $m->attachments);

        $isIndustry = SboConversation::TYPE_INDUSTRY === $conversation->type;
        $isGroup = SboConversation::TYPE_GROUP === $conversation->type;
        $isOwner = $isGroup && null !== $mySboId
            && (int) $conversation->created_by_sbo_id === $mySboId;
        $muted = null !== $conversation->participants()
            ->when(null !== $mySboId, fn ($q) => $q->whereKey($mySboId))
            ->first()?->pivot?->muted_at;

        return [
            'name' => match (true) {
                $isIndustry && (bool) $conversation->industry => IndustryGroupChat::name($conversation->industry),
                $isGroup => $conversation->name ?: 'Group',
                default => $conversation->participants->firstWhere('id', '!=', $mySboId)?->name ?? '',
            },
            'group' => $isIndustry || $isGroup,
            // Which kind of room, and whether the viewer owns it, so the panel
            // can offer the roster controls (add/remove) or the way out (leave).
            'kind' => $isIndustry ? 'industry' : ($isGroup ? 'group' : 'direct'),
            'isOwner' => $isOwner,
            'members' => $conversation->participants()
                ->where('sbos.is_registry', false)
                ->count(),
            'muted' => $muted,
            'media' => $attachments
                ->whereIn('kind', [SboMessageAttachment::KIND_IMAGE, SboMessageAttachment::KIND_VIDEO])
                ->take(60)
                ->map(fn (SboMessageAttachment $a): array => [
                    'id' => (string) $a->id,
                    'kind' => $a->kind,
                    'name' => $a->original_name,
                    'url' => route('sbo.message-attachments.show', $a),
                ])
                ->values()
                ->all(),
            'files' => $attachments
                ->where('kind', SboMessageAttachment::KIND_DOCUMENT)
                ->take(60)
                ->map(fn (SboMessageAttachment $a): array => [
                    'id' => (string) $a->id,
                    'name' => $a->original_name,
                    'size' => $a->size,
                    'url' => route('sbo.message-attachments.show', $a),
                ])
                ->values()
                ->all(),
            'links' => $this->linksIn($messages),
        ];
    }

    /**
     * Every distinct link somebody has posted, newest first.
     *
     * Pulled from the message bodies because that is where they are — there is
     * no link table, and adding one would mean a link that was edited or
     * deleted lived on in it.
     *
     * @param  Collection<int, SboMessage>  $messages
     * @return array<int, array{url: string, author: string|null}>
     */
    private function linksIn($messages): array
    {
        $seen = [];
        $links = [];

        foreach ($messages as $message) {
            preg_match_all('#https?://[^\s<>"\']+#i', (string) $message->body, $matches);

            foreach ($matches[0] as $url) {
                // Trailing punctuation is almost always the sentence's, not
                // the URL's.
                $url = mb_rtrim($url, '.,);:!?');

                if (isset($seen[$url]) || '' === $url) {
                    continue;
                }

                $seen[$url] = true;
                $links[] = ['url' => $url, 'author' => $message->sbo?->name];

                if (count($links) >= 60) {
                    return $links;
                }
            }
        }

        return $links;
    }

    private function thread(SboConversation $conversation, int|string $userId): array
    {
        $messages = SboMessage::where('conversation_id', $conversation->id)
            ->with(['sbo', 'attachments', 'replyTo.sbo', 'reactions'])
            ->oldest()
            ->take(100)
            ->get();

        /*
         * Work out what language each incoming message is in, so the chat can
         * offer "See translation" ONLY where it would actually change
         * something. Detected once and stored — see the migration.
         */
        $readerLocale = Locales::resolve(request(), request()->user());
        $this->detectLanguages($messages, $userId);

        return $messages
            ->map(fn (SboMessage $m): array => [
                'id' => (string) $m->id,
                'body' => $m->body,
                'time' => Timezones::format($m->created_at, 'M j, g:ia'),
                'author' => $m->sbo?->name,
                // Cast both: user keys are UUID strings in production and ints
                // under the test factory, so `===` alone is not reliable.
                'mine' => (string) $m->user_id === (string) $userId,
                /*
                 * Offered only when the message is in a language the reader is
                 * not already reading. An undetectable message returns null and
                 * IS offered — better to show a link nobody needs than to hide
                 * one somebody does.
                 */
                'translatable' => (string) $m->user_id !== (string) $userId
                    && $m->detected_locale !== $readerLocale,
                'detectedLocale' => $m->detected_locale,
                /*
                 * Files sent with the message. The URL is a gated route, never
                 * a disk path — the bytes are on the private `chat` disk and
                 * only a participant can read them.
                 *
                 * `kind` decides how the thread draws each one, and is stored
                 * at upload rather than derived here so the display cannot
                 * drift from what the upload rules accepted.
                 */
                /*
                 * The message this one answers, flattened to what the quote
                 * needs. A quote, not a tree: reply to a reply and you quote
                 * the reply, so one level is always enough to render.
                 */
                'replyTo' => null === $m->replyTo ? null : [
                    'id' => (string) $m->replyTo->id,
                    'author' => $m->replyTo->sbo?->name,
                    'body' => $m->replyTo->isDeleted()
                        ? __('chat.message_deleted')
                        : str($m->replyTo->body)->limit(120)->toString(),
                ],
                'deleted' => $m->isDeleted(),
                'pinned' => null !== $m->pinned_at,
                /*
                 * Grouped by emoji with a count, and whether the reader is one
                 * of them — which is what lets the same control add and remove.
                 */
                'reactions' => $m->reactions
                    ->groupBy('emoji')
                    ->map(fn ($group, $emoji): array => [
                        'emoji' => $emoji,
                        'count' => $group->count(),
                        'mine' => $group->contains(fn ($r): bool => (string) $r->user_id === (string) $userId),
                    ])
                    ->values()
                    ->all(),
                'attachments' => $m->attachments
                    ->map(fn (SboMessageAttachment $a): array => [
                        'id' => (string) $a->id,
                        'kind' => $a->kind,
                        'name' => $a->original_name,
                        'size' => $a->size,
                        'url' => route('sbo.message-attachments.show', $a),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Fill in `detected_locale` for incoming messages that have never been
     * looked at.
     *
     * Capped: opening a long thread for the first time should not fire fifty
     * detection requests before the page renders. The rest are detected the
     * next time the thread is opened, and until then they simply offer the
     * control.
     *
     * @param  \Illuminate\Support\Collection<int, SboMessage>  $messages
     */
    private function detectLanguages($messages, int|string $userId): void
    {
        $translator = app(Translator::class);

        if ( ! $translator->isConfigured()) {
            return;
        }

        $pending = $messages
            ->filter(fn (SboMessage $m): bool => (string) $m->user_id !== (string) $userId)
            ->filter(fn (SboMessage $m): bool => null === $m->detected_at)
            ->take(20);

        foreach ($pending as $message) {
            $message->forceFill([
                'detected_locale' => $translator->detect((string) $message->body),
                'detected_at' => now(),
            ])->saveQuietly();
        }
    }

    /**
     * Accepted connections with no conversation open yet — the composer's
     * recipient list. Once a thread exists the rail is the way back to it, so
     * offering it again in compose would just fork the user's attention.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $accepted
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $conversations
     * @return array<int, array<string, mixed>>
     */
    private function composeTargets(
        \Illuminate\Support\Collection $accepted,
        \Illuminate\Support\Collection $conversations,
    ): array {
        $open = $conversations->pluck('slug')->filter()->all();

        return $accepted
            ->reject(fn (array $c): bool => in_array($c['slug'], $open, true))
            ->map(fn (array $c): array => [
                'sboId' => $c['sboId'],
                'name' => $c['name'],
            ])
            ->values()
            ->all();
    }

    /**
     * Registry search from the messages inbox.
     *
     * The search box filtered the conversations already loaded, which meant
     * you could only find someone you had already talked to — no use for
     * starting a conversation, which is what a search in an inbox is for.
     *
     * Scoped to SBOs that are actually reachable: declined registrations are
     * hidden, as they are in the directory, and the owner's own listings are
     * excluded because you cannot connect to yourself.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $sboIds
     * @param  \Illuminate\Support\Collection<int, SboConversation>  $conversations
     * @return array<int, array<string, mixed>>
     */
    private function people(string $search, $sboIds, $conversations): array
    {
        // Two characters is the shortest term worth a LIKE across the table;
        // one would match most of the registry and mean nothing.
        if (mb_strlen($search) < 2) {
            return [];
        }

        $term = '%' . $search . '%';

        $matches = Sbo::query()
            ->where('verification_status', '!=', 'declined')
            ->whereNotIn('id', $sboIds->all())
            ->where(fn ($q) => $q
                ->where('name', 'like', $term)
                ->orWhere('category', 'like', $term)
                ->orWhere('city', 'like', $term)
                ->orWhere('country', 'like', $term))
            ->orderBy('name')
            ->take(10)
            ->get();

        if ($matches->isEmpty()) {
            return [];
        }

        $states = SboConnectionStates::between($sboIds, $matches->pluck('id'));

        /*
         * Where a conversation already exists, the row links straight into it
         * rather than posting a compose that would only find the same thread.
         */
        $openThreads = $conversations
            ->mapWithKeys(function (SboConversation $c) use ($sboIds): array {
                $other = $this->conversationCounterpart($c, $sboIds);

                return $other ? [$other->id => (string) $c->id] : [];
            })
            ->all();

        return $matches->map(fn (Sbo $sbo): array => [
            'sboId' => $sbo->id,
            'name' => $sbo->name,
            'slug' => $sbo->slug,
            /*
             * A search hit is a single line with no tag under it, so the
             * category stays: it is how you tell two similarly named
             * businesses apart. The network CARD drops it — see card(), which
             * prints the category as a chip directly beneath the meta line.
             */
            'meta' => mb_trim(implode(', ', array_filter([
                DataLabels::get(DataLabels::CATEGORY, $sbo->category),
                $sbo->city,
                DataLabels::country($sbo->country),
            ]))),
            'verified' => $sbo->isFullyVerified(),
            'connection' => SboConnectionStates::forSbo($sboIds, $states, $sbo->id),
            'conversationId' => $openThreads[$sbo->id] ?? null,
        ])->values()->all();
    }

    /** The other SBO in a connection, from this owner's point of view. */
    private function counterpart(SboConnection $c, $mine): ?array
    {
        $other = $mine->contains($c->requester_sbo_id) ? $c->recipient : $c->requester;

        return $other ? $this->card($other, $c->id, $other->id) : null;
    }

    /**
     * A network card. `id` is the connection row (what Accept/Decline PATCH
     * against); `sboId` is the business itself (what Connect and compose post).
     *
     * @return array<string, mixed>
     */
    private function card(Sbo $sbo, int $key, int $sboId): array
    {
        return [
            'id' => (string) $key,
            'sboId' => $sboId,
            'name' => $sbo->name,
            'slug' => $sbo->slug,
            // Drives the logo ring colour: SBO = blue, COB = gold.
            'entityType' => $sbo->entity_type,
            /*
             * Where it is, not what it does. The card already prints the
             * category as a tag directly underneath, so leading with it here
             * read as "Transport & Logistics, Abuja, Nigeria" above a chip
             * saying "Transport & Logistics" — the same words twice, pushing
             * the location onto a second line.
             */
            'meta' => mb_trim(implode(', ', array_filter([
                $sbo->city,
                DataLabels::country($sbo->country),
            ]))),
            'tags' => array_values(array_filter([$sbo->category])),
        ];
    }

    /** @return array<string, mixed> */
    private function identity(Sbo $sbo): array
    {
        return [
            'name' => $sbo->name,
            'slug' => $sbo->slug,
            'category' => DataLabels::get(DataLabels::CATEGORY, $sbo->category),
            'verified' => 'approved' === $sbo->verification_status,
            'feesPaid' => $sbo->feesPaid(),
            'applicationId' => $sbo->application_id ?? $sbo->moc_registration_number,
        ];
    }

    /**
     * A dashboard table row. `status` maps to the three designed pills
     * (Active / Under Review / Closed) rather than echoing the raw column.
     *
     * @return array<string, mixed>
     */
    private function row(PurchaseOrder $order): array
    {
        $state = match (true) {
            'open' !== $order->status => 'closed',
            null !== $order->awarded_bid_id => 'under_review',
            default => 'active',
        };

        return [
            'id' => (string) $order->id,
            'title' => $order->title,
            'type' => 'service' === $order->request_type ? __('rfq.type.service_contract') : __('rfq.type.procurement'),
            'category' => DataLabels::get(DataLabels::CATEGORY, $order->category?->name),
            'deadline' => $order->order_deadline?->format('M d, Y'),
            'visibility' => PurchaseOrder::VISIBILITY_PUBLIC === $order->visibility
                ? __('rfq.visibility.public')
                : __('rfq.visibility.network_only'),
            // The key drives the pill's colour; the label is what is read.
            'statusKey' => $state,
            'status' => __('data.status.' . $state),
            'bids' => trans_choice('rfq.bids_count', $order->bids_count ?? 0, ['count' => $order->bids_count ?? 0]),
        ];
    }

    /** @return array<string, mixed> */
    private function rfq(PurchaseOrder $order): array
    {
        return [
            'id' => (string) $order->id,
            'title' => $order->title,
            'body' => $order->description,
            'category' => DataLabels::get(DataLabels::CATEGORY, $order->category?->name),
            'buyer' => $order->sbo?->name,
            'budget' => filled($order->budget) ? __('procurement.estimated_budget', ['amount' => $order->budget]) : null,
            'status' => DataLabels::get(DataLabels::STATUS, ucfirst((string) $order->status)),
            'bids' => trans_choice('procurement.bids_received_count', $order->bids_count ?? 0, ['count' => $order->bids_count ?? 0]),
            'posted' => $order->created_at?->format('F j, Y'),
            'deadline' => $order->order_deadline?->format('F j, Y'),
        ];
    }
}
