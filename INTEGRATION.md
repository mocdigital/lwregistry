# Connecting the Vue frontend to the Laravel backend

Written against the repo as it stands. Every claim here was checked against
`routes/`, `app/Http/Controllers/`, `app/Livewire/` and `app/Models/`.

## Status

Converted and verified against real rows: **Home (`/`)**, **Directory
(`/businesses`)**, **Categories (`/categories`)** and **Business profile
(`/business/{slug}`)** — the whole public browsing surface, so a card on the
home page now leads to a real profile. Directory carries search, category and
country filters, pagination and an empty state, and its four card buttons are
live: View Profile, Visit Website (external, URL normalised), Directions
(Google Maps from the postal address) and Connect, which reads
Connect / Pending / Message from the actual `sbo_connections` row.

Also reading and writing real rows: **Notifications (`/notifications`)** — the
feed, its seven filter tabs, mark-as-read, mark-all and delete — and the **SBO
Network (`/sbo/network/:tab`)**, including the messages inbox, its
Unread / Connections / Groups categories, the composer and live delivery over
Reverb.

The remaining screens render from real data where the models support it; the
panels that do not are listed under "Reads — panels with no source" below.

### Dead links

The Vue shell was written against its own route table, so its links do not
automatically correspond to Laravel routes. Auditing every static `href`
against `route:list` found 11 destinations returning 404. Three are fixed
(`/categories` had no route, `/directory` is `/businesses`, `/terms` does not
exist and is now unlinked). **Eight remain**, each needing a real conversion
rather than just a route:

| Links | Path | Blocked on |
|---|---|---|
| 8 | `/procurement` | Tier C — no backend at all |
| 7 | `/procurement/new` | Tier C — no backend at all |
| 6 | `/account` | Tier B — logic lives in Livewire |
| 2 | `/payment-required` | exists at `sbo/complete-payment-required` |
| 2 | `/settings/security` | only profile/appearance/password are routed |
| 2 | `/settings/notifications` | as above |
| 2 | `/application/fees` | exists at `sbo/applications/{sbo}/progress` |
| 1 | `/application/documents` | as above |

Re-run the audit after any conversion; it is quick and catches this class of
breakage, which is otherwise invisible until someone clicks.

Screens with no rows behind them render an empty state rather than a blank
panel — the directory, the network's connections and requests grids, the
messages inbox and the notification feed each say what would appear there and,
where there is one, offer the action that fills it.

### Design placeholders vs. real data

Several screens show fields the `sbos` table does not have: client ratings,
review counts, project counts, and the card photography/logo tiles/flag stacks.
These are kept so the screens still match the Figma frames, but they are
**identical for every business** and must not be read as real. Each is
commented at its call site. Backing any of them means a schema change first.

**The business profile no longer belongs on that list.** Its media gallery,
services, opening hours and highlight cards were the worst case of this — four
`const` arrays in `BusinessProfileView.vue`, so all 900+ listings advertised
the same three services and the same "4.5 Score", and the highlights openly
contradicted the real Company Size and Founded rows on the same page. They are
per-SBO records now:

| Section | Backing | Owner edits it at |
|---|---|---|
| Services | `sbo_services` | `PUT sbo/listings/{sbo}/services` |
| Opening hours | `sbo_opening_hours` | `PUT sbo/listings/{sbo}/hours` |
| Media gallery | MediaLibrary on `Sbo`, four collections | `POST/DELETE sbo/listings/{sbo}/gallery` |
| Highlights | Derived — `Sbo::highlights()` | not editable; reads the columns below |
| Description, website, email, phone, staff count | `sbos` | `PATCH sbo/listings/{sbo}/profile` |

Highlights are **derived, never typed**: staff count, service count and years
since `established_at` all read the same columns the details list above them
reads, which is what stops the two halves of the page disagreeing again. A card
with no data behind it is omitted rather than shown as zero. The rating card is
the one still missing, because `reviews` remains keyed to `Business` — see the
gap table below.

`SboListingProfileController` writes **only** those fields. Name, registration
number, legal structure, category, address and every verification/payment
column stay as the MoC approved them; they are not merely hidden from the form,
nothing in the controller writes them, so a hand-crafted request cannot reach
them either. Covered by `tests/Feature/SboListingProfileTest.php`.

The same editor is mounted twice — the standalone `/sbo/listings/{id}/edit`
screen and the **About** tab of `/settings`. Both render
`components/sbo/ListingProfileForm.vue` and both are fed by
`SboListingProfilePayload`, so the two cannot drift.

### Settings → Security is no longer decorative

The Security Overview panel showed three fixed strings on every account:
"Two-Factor Auth: **ACTIVE**", "Password Age: 42 days (Good)", "Last Login
Device: Chrome, Nairobi". The first was the dangerous one — it told every
member they were protected by a second factor that did not exist anywhere in
the codebase.

| Row | Now reads |
|---|---|
| Two-Factor Auth | Whether TOTP is actually confirmed on the account |
| Password Age | `users.password_changed_at`, stamped by a `User::saving` hook so no call site can forget |
| Last Login Device | Browser + when, from the request that signed in. No city — that would need geo-IP the app does not have |

**Two-factor auth is real TOTP** (`pragmarx/google2fa`), so any authenticator
works — Google Authenticator, Authy, 1Password, Microsoft Authenticator. The QR
is rendered inline as SVG; the secret never goes to a third-party service.

Two things about the design are load-bearing:

1. **Enrolment is two stages.** `two_factor_secret` is written when setup
   starts, but login gates on `two_factor_confirmed_at`, which is only set once
   the member types a code their app produced. Gating on the secret would lock
   out anyone whose QR scan failed halfway.
2. **The password stage leaves the caller logged out.** It parks the pending
   user id in the session and redirects; `TwoFactorChallengeController` is what
   authenticates. Staying signed in "pending a code" would make the factor
   cosmetic, since navigating away from the prompt would leave a live session.

The Livewire `AuthModal` enforces the same gate — without it the modal would be
a way straight past 2FA for every Blade page.

**Deactivate Account** now works, and deactivates rather than deletes:
`sbos.created_by` is `nullOnDelete`, so removing the user row would orphan
their approved listings — publicly visible and permanently uneditable, since
every write path authorizes on `created_by`. It sets `suspended_at` instead,
which login, `EnsureUserIsNotSuspended`, and network/procurement visibility all
already honour. Requires typing `DELETE` **and** the current password.

Covered by `tests/Feature/TwoFactorAuthTest.php` and
`tests/Feature/AccountDeactivationTest.php`.

**Sign-in works over HTTP** — `GET /login` serves the Vue screen and `POST
/login` exists for the first time (`AuthenticatedSessionController`). Verified
for bad credentials, correct credentials, missing CSRF and suspended accounts.
`AuthModal` is untouched and still serves the Blade pages; the two coexist.
**Registration has a working endpoint** — `POST /register`
(`RegisteredUserController`) creates the User and Sbo in one transaction,
resolves `country_id` and `zone_id`, honours a referral code held in the
session, logs the owner in and hands off to `post-login`. Verified over HTTP
for validation failures, a successful create and a duplicate email.

**But the Vue wizard cannot yet drive it**, and this needs a design decision:

| | Wizard shows | Endpoint requires |
|---|---|---|
| Step 1 | Trading Name, Registration Number, Business Description | legal structure, address, state, phone, email, country, employee count |
| Step 3 | Code of Conduct only | password + confirmation |

Step 1 is marked `designed: false` in the view — there is no Figma frame for
it, so its fields are provisional and were never reconciled with the schema.
Step 3 has no password input at all, yet a password is required to create the
login. Neither can be resolved by writing code: either the frames need drawing,
or the endpoint needs to stop requiring those fields. Until then the wizard's
selects are populated from real categories, countries, zones and legal
structures, and the endpoint is reachable but only from a client that sends the
full payload.

Also not mirrored from `AuthModal`: **Turnstile**. It validates a token minted
by its own widget; the Vue screen has no widget, so enforcing it would fail
every submission. Add the widget before enabling the rule.

### Dev workflow

Vite serves assets only; **Laravel serves the pages**. Run `npm run dev`
alongside Herd or `php artisan serve`, and browse the **Laravel** URL. Opening
the Vite port directly returns 404 — the old `frontend/index.html` it used to
serve no longer exists. The port is pinned in `vite.config.js` because 5173 is
commonly already taken.

**Version pinning — do not "upgrade" past this.** `@inertiajs/vue3` is held at
`^2` to match `inertiajs/inertia-laravel` **v2**. npm's default pulled the v3
client, which the v2 server adapter does not speak: the page mounts with a null
`initialPage` and fails silently with `Cannot read properties of null (reading
'component')` — a blank `<div id="app">` and nothing in the server log. Move
both majors together or neither.

Two known stopgaps carried deliberately, both commented at their call sites:
`resources/js/spa/composables/useRoute.ts` (URL-derived params for screens
still written against vue-router) and the `crypto.hash` shim in
`vite.config.js` (Node 20.10 predates it).

**`node_modules` is no longer tracked.** 6,230 files were in the index despite
the `.gitignore` entry, having been committed before it was added;
`git rm -r --cached node_modules` removed them. `vendor/` remains tracked
deliberately — it is not in `.gitignore` and the deployment relies on it.

## The starting position

The backend is a complete server-rendered Livewire/Volt application, not an API:

- **No `routes/api.php`. Laravel Sanctum is not installed.** There are exactly
  two `response()->json()` calls in the codebase (`ReviewController`,
  `BusinessReportController`). Everything else returns Blade.
- **Sign-in has no HTTP endpoint.** `routes/auth.php` disables the pages —
  `Route::get('login', fn () => redirect()->route('home'))`. Auth runs through a
  Livewire modal (`AuthModal`, `JoinRegistry`) over `/livewire/update`. Only
  `POST /logout` is a real route.
- **The domain is already built**: 43 models, 19 controllers, 18 Livewire
  components, Reverb for websockets, `SESSION_DRIVER=database`.
- **The two builds are unrelated.** `frontend/` appears nowhere in Laravel's
  `vite.config.js`, `composer.json` or `package.json`.

### Correction to a prior assumption

`frontend/vite.config.ts` proxies `POST /login`, `POST /register` and
`/sanctum`. None of those exist. That proxy was written against the brief
"talk to the backend directly" before the backend had been read, and it has
never connected to anything. It needs replacing under either option below.

## The data contract, screen by screen

Three tiers, by how much backend work each screen needs.

### Tier A — controller already returns the data

Cheapest. The view payload *is* the contract; it needs serialising, not writing.

| SPA route | Laravel route | Source | Already passes |
|---|---|---|---|
| `/directory` | `GET /businesses` | `BusinessController@index` | `businesses` (paginated), `categories`, `cities`, `selectedCategory` |
| `/categories` | `GET /businesses/categories/{category:slug}` | `BusinessController` | same as above |
| `/business/:slug` | `GET /business/{business:slug}` | `BusinessController@show` | `business` |
| `/business/:slug/verification` | `GET /verify/sbo/{mocRegistrationNumber}` | `SboVerificationController` | seal + verification state |
| `/dashboard` | `GET /dashboard` | `DashboardController` | `verifiedBusinesses`, `unverifiedBusinesses`, `favorites`, `recentlyViewed`, `availableRfqs`, `bidsSent`, `bidsAccepted`, `sboNetworkReady` |
| `/profile` | `GET /profile` | `DashboardController@profile` | `user`, businesses with `seal` |
| `/account` | `GET /dashboard` | `DashboardController` | the same listing collections the tabs need |
| `/payment-required` | `GET /sbo/complete-payment-required` | `SboPaymentRequiredController` | payment gate state |
| `/sbo/progress` | `GET /sbo/applications/{sbo}/progress` | `SboVerificationController` | application progress |
| `/` | `GET /` | `HomeController` | `categories` only — **featured SBOs, partners and countries are not passed yet** |
| `/about`, `/advertise`, `/feedback`, `/support` | `Route::view(...)` | static | copy only; `/advertise` and `/feedback` already have working POST endpoints |

### Tier B — logic lives in a Livewire component

The data exists but is bound to Livewire's lifecycle. Under Inertia these can
often stay as-is; under a JSON API the query logic has to be lifted into a
controller or service.

| SPA route | Component |
|---|---|
| `/sbo/dashboard` | Volt `sbo.dashboard` |
| `/procurement` | Volt `sbo.rfqs` — model `PurchaseOrder` |
| `/procurement/:id/bid/:step` | `SubmitBidModal` — model `PurchaseOrderBid` |
| `/support` | `Support\Index` |
| `/settings/:tab` | Volt `settings/profile`, `settings/password`, `settings/appearance` |

`/notifications` and `/sbo/network/:tab` have both been lifted out of Livewire:
`NotificationController` and `SboWorkspaceController@network` now own them.
The Volt components stay on disk — `sbo.network.index` is still routed nowhere
but remains the reference for reporting and invites, which the Vue screen does
not cover yet.

Note: my settings tabs are profile / appearance / **security** / **notifications**.
Security maps to Volt `settings/password`; the **notifications settings tab**
still has no backend equivalent (the notification *feed* does — that is
`/notifications`, which is wired).

### Tier C — no backend exists

Real new work regardless of approach.

| SPA route | Gap |
|---|---|
| `/login` | No endpoint. Auth is a Livewire modal. **Gating dependency for every authed screen.** |
| `/register/:step` | Same — `JoinRegistry` is a modal, not a 3-step page flow |
| `/procurement/new` | No create endpoint for `PurchaseOrder` |
| `/procurement/:id/:tab` | No detail route; needs `PurchaseOrder` + `PurchaseOrderBid` + `PurchaseOrderInvite` |
| `/application/:step` | Partially covered by `SboPaymentRequiredController`; the 6-screen flow has no single owner |

## Cross-cutting work, independent of approach

- **Auth first.** Nothing authed can be wired until sign-in is a real endpoint.
- **CSRF** on every mutation.
- **Validation errors** — no screen currently renders field errors.
- **Loading / empty / error states** — no screen has any. Every screen holds
  hardcoded arrays; this is the bulk of the effort, not the transport layer.
- **Pagination** — `BusinessController` already returns `LengthAwarePaginator`;
  the directory and procurement grids render fixed arrays.
- **File uploads** — `spatie/laravel-medialibrary` is installed and used by
  `LogoUploader`, `GalleryUploader`, `SboPaymentProofUpload`. The bid wizard and
  documents screens have upload UI with no wiring.
- **Reverb** for `/sbo/network/messages`.

## Recommended sequence

1. Decide the transport (see below).
2. Build real auth endpoints.
3. Prove one vertical slice end to end — **Directory** is the best candidate: it
   is Tier A, paginated, filtered, and public, so it exercises data, query
   state and pagination without needing auth.
4. Repeat outward: public → auth → application/payment → SBO workspace →
   procurement.
5. Cross-cutting concerns above, folded in as each screen lands.

## The writes, and what is now bound to them

The endpoints below all exist and are routed. Until recently none of the
designed forms posted to them — the screens collected fields and dropped them.
Every row here is now wired, with a feature test that posts the payload the
screen actually sends (`tests/Feature/BoundFormsTest.php`,
`tests/Feature/Sbo/SboNetworkMessagingTest.php`,
`tests/Feature/DirectorySboCardTest.php`,
`tests/Feature/NotificationsPageTest.php`).

| Endpoint | Screen | Bound in |
|---|---|---|
| `POST /procurement` | Post Procurement Request | `ProcurementCreateView` |
| `POST /procurement/{id}/bid` | Submit Bid (5 steps) | `BidWizardView` |
| `POST /procurement/{id}/award` | RFQ detail, "Review & Award" | `ProcurementDetailView` |
| `POST /sbo/connections` | Directory card + Network "Connect" | `DirectoryView`, `SboNetworkView` |
| `PATCH /sbo/connections/{id}` | Network Accept / Decline; notification card | `SboNetworkView`, `NotificationsView` |
| `POST /sbo/conversations` | Network → Messages, compose | `SboNetworkView`, `DirectoryView` |
| `POST /sbo/conversations/{id}/messages` | Network → Messages | `SboNetworkView` |
| `POST /support/enquiries` | Support contact form | `SupportView` |
| `POST /sbo/applications/{sbo}/payments/proof` | Manual payment | `PaymentMethods` |
| `POST /sbo/documents` | Application step 3 | `ApplicationView` |
| `POST /notifications/{id}/read`, `/read-all`, `DELETE /notifications/{id}` | Notifications | `NotificationsView` |
| `POST /forgot-password` | Forgot password | `ForgotPasswordView` |
| `POST /reset-password` | Reset password | `ResetPasswordView` |
| `POST /email/verification-notification` | Verify email | `VerifyEmailView` |

Still unbound: `POST /register` **step 1 fields** — step 1 has no desktop frame
and its provisional fields have never been reconciled with what the endpoint
requires. Unchanged from the note above.

### Four things that were broken, not merely unwired

Wiring the forms surfaced defects that no controller test could have caught,
because nothing was posting to them:

- **`SboConversation::connection()`** shadowed `Model::$connection`, the
  database connection name. `$conversation->connection` read `null` before
  hydration (so every membership check silently 403'd) and the string
  `"sqlite"` after (so it fataled on `->requester_sbo_id`). Renamed
  `sboConnection()`; the column is still `connection_id`.
- **`SboWorkspaceController@network`** eager-loaded `requesterSbo` /
  `recipientSbo`, which are named `requester` / `recipient`. The whole SBO
  Network screen 500'd.
- **`ProcurementWriteController@store`** passed the validated array straight to
  `new PurchaseOrder(...)`, including `category` and `document`, which are
  inputs rather than columns — `MassAssignmentException` on every post.
- **`support_enquiries.user_id`** was `NOT NULL` while the controller wrote
  `$request->user()?->id` and the page is reachable signed out. Made nullable
  (`2026_08_05_000000_make_support_enquiry_user_nullable`).

Also tightened while passing through: `POST /sbo/conversations/{id}/read` had
no authorisation at all (any signed-in user could clear anyone's unread count),
and `POST .../messages` attributed the message to `$mine->first()` rather than
to the SBO that is actually party to the conversation.

### Live messaging

`SboMessageSent` broadcasts on `private-sbo.conversation.{id}`; the channel
callback in `routes/channels.php` admits exactly the parties the write
endpoints admit. The client half is `resources/js/spa/composables/useEcho.ts`,
which builds a connection lazily from the `reverb` prop shared by
`HandleInertiaRequests` — **null when no `REVERB_APP_KEY` is set**, which is the
normal local state. The thread then simply does not update itself, and the
"Live" pill in the chat header is absent; the conversation is still correct on
the next load. Broadcasting is never the source of truth: the row is committed
and the redirect has already happened before the event is dispatched.

The Livewire half of the app has its own Echo bootstrap in
`resources/views/partials/echo.blade.php`; the two do not share a connection.

### Reads — panels with no source

| Panel | Screen | Needs |
|---|---|---|
| Recent Activity feed | My Account | An activity/audit table (`ActivityLog` exists but is unused here) |
| Reviews and star ratings | Directory, Categories, Business profile | `Review` exists for `Business`, not `Sbo` |
| Card photography, logo tiles, flag stacks | All listing cards | Media columns on `sbos` |
| Documents | RFQ detail | No table (Q&A and followers are built — see below) |
| ~~Business hours, services, gallery~~ | ~~Business profile~~ | **Built** — `sbo_opening_hours`, `sbo_services`, MediaLibrary on `Sbo` |
| Active Sessions list | Settings → Security | Session records per device; the list is still the designed placeholder |
| Login location ("Nairobi") | Settings → Security | A geo-IP service. The panel reports browser + when instead, which are known |
| Knowledge-base articles, FAQs | Support | No table |
| "High Est. Value" facet | Procurement | `budget` is free text with no currency column |
| Mutual connections | Network → Messages | No query; the card states the participant count instead |

### Designed fields with no column behind them

Drawn, and deliberately not posted, because there is nowhere to keep them.
Each is commented at its call site:

| Field | Screen | Why |
|---|---|---|
| Payment reference | Manual payment | `sbo_payment_proofs` has no reference column |
| Category | Support request | Folded into the subject line — no category column |
| Email address | Support request | No column; replies go to the linked user, and the field is meaningless signed out |
| Unit / Specification | Post Procurement | No column; belongs in the description |
| Required documents from bidders | Post Procurement | No checklist table; belongs in Terms |
| Mailing address | Bid wizard step 2 | The bid is one proposal document, not two addresses |
| Certificate of Insurance upload | Bid wizard step 4 | `purchase_order_bids` has a single `attachment_path`, used by step 3's technical proposal |
| Date filter | Notifications | Only `created_at` exists, and the design names no ranges |
| Archived chip | Network → Messages | No archived flag on `sbo_conversations` |
| Formatting toolbar, attachments | Network → Messages | `sbo_messages.body` is plain text with no attachment column |
| Export to CSV | Bid wizard | Nothing to export until the bid exists |

### Notifications

The `notifications` table and ~40 `App\Notifications` classes already existed;
nothing read them on this screen. `App\Models\Notification` extends
`DatabaseNotification` and derives the category, icon key, tint and
"Action Required" flag from the `type` slug every payload already carries, so
that classification lives in one place. `User::notifications()` is overridden to
return it (`readNotifications()` / `unreadNotifications()` defer to that method,
so they pick up the subclass too).

Filtering and paging happen in PHP rather than SQL, because category and
action-required are derived rather than stored. That is fine at "one user's own
notifications" scale; if it stops being fine, the fix is a stored `category`
column written at send time, not a second copy of the mapping in a query.

The **delete control on a notification card is an addition** — the Figma card
draws no dismiss affordance, and `DELETE /notifications/{id}` would otherwise
have no way in. It uses the same glyph as the network screen's remove button.
The compose panel in the messages inbox and the Review & Award panel on the RFQ
detail are additions for the same reason: the frames draw the button (or the
column) but never what it opens.

### Industry is the sector above category

The directory design has an "All Categories" select and an "All Industries"
select side by side, and only the first had anything behind it, so the second
shipped disabled. `sbos.industry` is a real column now, indexed, with the
existing rows backfilled from the categories they already held.

The two are a taxonomy, not a duplicate. `categories` holds 30-odd specific
trades ("Bookstores & Publishers", "Graphics & Printing") — the right
granularity for a listing and far too fine for a filter. `App\Support\
SboIndustries` maps each of them to one of fourteen sectors, and that map is the
only place the classification lives: the industry list the filter offers is
derived from it, so a category cannot be classified into a sector the filter
does not show. A test asserts every seeded category is mapped, because the
seeders drifting from the map is how the filter would quietly start dropping
listings.

**Registration does not ask for both.** An owner picks their primary category
and the industry follows, because a form that asks the same question at two
zoom levels gets two inconsistent answers. `Sbo::saving()` derives it, so the
register wizard, the signed-in "New Listing" flow, the legacy Livewire modal,
seeders and admin edits all stay consistent. An industry set explicitly is left
alone, for a business that does not sit where its category suggests.

Both levels are searchable, so "Technology" in the search box finds the software
houses filed under Software Development. Filtering by both composes rather than
conflicts.

### Explore the Global Directory — five facets, five pages

The home page's five "Browse by …" rows all linked to `/categories` whatever the
label said, so "By Business Size" and "By Industry" opened the same page. Each
is a real facet now, with an index at `GET /browse/{facet}` listing every value
that has a listing behind it, its count, and a link into the directory filtered
by it.

Each reads a column the registry already holds — nothing invents a
classification:

| Row | Reads | Values today |
|---|---|---|
| By Industry | `sbos.industry` | 14 |
| By Country | `sbos.country` | 7 |
| By Service | `sbos.category` — the specific trade | 40 |
| By Certification | Fully verified vs. still registering | 2 |
| By Business Size | `sbos.staff_count`, banded | 4 |

`App\Support\SboBrowseFacets` owns all five: their labels, how each counts, and
how the directory filters by one. The home page reads its menu from there rather
than keeping a second copy, so a row cannot be offered and then 404 on arrival —
a test walks every URL the home page advertises. Size bands live there for the
same reason: the browse page counts by them and the directory filters by them,
and a count that the filtered page then contradicts is worse than no count.
`SboVerificationScopes` came out of `BusinessController` for the same reason —
"fully verified" now has one definition that both the browse count and the
directory's Verified filter read.

Two notes on the frames. Three of the five sub-labels are copy-paste slips —
"By Country", "By Service" and "By Certification" each repeat a neighbouring
row's text — so each describes its own facet, as the By Country row already did.
And **there is no frame for the browse page itself**, because the rows never
opened one: `BrowseView.vue` is assembled from the categories screen they used
to land on (same hero band, gutters, rail and benefits strip) and the home
page's own directory-row treatment. It is marked as an addition at the top of
the file and should give way to a real frame if one is drawn.

Behind the paywall, like the directory: a browse page is an index of the
membership.

### Public registration did not work

The wizard's business step was drawn as labels with **no `name` bindings**, so
nothing typed on it reached the form. A fully completed registration failed on
eleven fields — eight the user had filled in, two the wizard never asked for
(`ownerLocalAssembly` was optional in the UI and required on the server), and a
phone number that 500'd because `User::$phone` is an E.164 cast with no default
region. Nobody could sign up.

All of it is bound now, laid out two-up on desktop, and the dial-code prefix
follows the country selected rather than reading `+234` for a business in Accra.
Three drawn fields — trading name, registration number, business description —
had no columns and were silently discarded; the first two are new columns, the
third was already `description`.

A self-registered SBO was also created `verification_status = 'approved'` with
`date_approved = now()` and `approved_by = null` — a self-issued approval nobody
granted, which kept new registrations out of the admin queue and showed Step 4
Verification complete on the day of signing up. It starts `pending`, matching
what the same owner's *second* listing already did.

### Explore the Global Directory — five facets, five pages

`SboWorkspaceAccess::requiresPayment()` is the single definition of membership:
**a user must own at least one SBO whose registration fee is confirmed.**

It used to return false for a user who owned no SBO at all — "nothing to pay
for, so nothing owed" — which let anyone without a listing browse the whole
platform for free. Owning nothing is now the state *before* the gate, not past
it, so such a user is sent to `sbo.listings.create` (register a business)
rather than to a payment screen showing no fee.

Admins are exempt: gating them would lock the people who approve payments out
of the approval screens.

The gate is applied as `sbo_payment_approved` **per route group**, not globally.
That is deliberate — the screens that let someone stop being gated (the fee
step, both payment methods, registering a listing, support, settings,
notifications, logout) have to stay reachable, and an allowlist of those is far
easier to get wrong than an explicit list of what sits behind the wall. Behind
it: the directory, categories, business profiles, procurement, My Account and
the dashboard.

`CreateBusinessModal` shares the same predicate, so legacy Business CRUD is
gated too. That is intended under "paid platform", and it is why
`BusinessCrudTest` and `BusinessAuthorizationTest` give their actor a paid SBO.

Two related fixes landed with it:

- **`BusinessController::index()` was a hard 500** for every gated owner, not a
  wall: the gated branch returned a Blade `View` from a method typed
  `: InertiaResponse`. It redirects to the payment screen now, the same as the
  dashboard gate.
- **Logout never worked.** The header posted a native `<form>` with no CSRF
  token, so every attempt was a 419 and the session survived. The
  `TokenMismatchException` handler in `bootstrap/app.php` was meant to cover
  this but is dead code — Laravel converts that exception to a generic
  `HttpException` before render callbacks are matched. It posts through Inertia
  now, which sends `X-XSRF-TOKEN` from the cookie. Note this class of bug is
  invisible to feature tests: `VerifyCsrfToken` short-circuits under
  `runningUnitTests()`.

### Espees checkout needs credentials, not code

The gateway is **not** env config — it lives in `sbo_fee_settings`, scoped by
country, normally edited from the admin Fees page. On a fresh database nothing
is set, `espeesGateway()['configured']` is false, and checkout refuses to
start. The screens say so now instead of offering a button that posts and
silently redirects back.

    php artisan sbo:espees --show
    php artisan sbo:espees --token=… --wallet=… --test-mode

The token and wallet are real Espees merchant credentials; there is no default
and nothing is guessed. The built-in one-Espee test account does not route
around this — it also requires a configured gateway. **Manual payment works
without any of it.**

`SboFeeScheduleSeeder` had never been run, so `flat_fee` was 0 and even a
confirmed fee would have failed the `amount < 1` check. It is seeded now.

### Demo data

    php artisan demo:chat            # two connected accounts + an open thread
    php artisan demo:chat --fresh    # reset the thread
    php artisan demo:chat --unpaid   # leave the second one behind the pay wall

`chat.ada@example.test` / `chat.ben@example.test`, password `password`.

**A plain re-run does not touch payment state.** It used to set it every time,
which silently marked both SBOs paid and switched the pay wall off; only
`--paid` / `--unpaid` change it now, and a new listing defaults to paid so the
chat works out of the box.

### Procurement Q&A and the Interest tab

Both tabs were fully drawn with nothing behind them: Q&A had a guidelines
panel, an "Ask Question" button and two invented entries; Interest listed six
invented vendors with a dead "View Details".

`purchase_order_questions` is new. Disclosure follows the rule the screen
itself states — "Your name and contact information will only be viewable by the
agency. It will be displayed anonymously to all other users" — so the asker is
stored and *who may see them* is decided when the thread is shaped, not by
leaving the association off. The asker still sees their own name on their own
row. Only the RFQ's owner may answer; the owner may not ask on their own
request.

The Interest tab needed a schema change of a different kind. The bid wizard
composed its five steps into one free-text `proposal`, so a submitted bid had
no fields to render back — "View Details" had nothing to show even in
principle. `purchase_order_bids.details` is a JSON column holding the wizard's
answers grouped by step (contact / address / company / declaration), written
alongside the readable `proposal`, which is kept because it is what a buyer
reads end to end.

JSON rather than thirty columns: the wizard's shape is a form definition that
will keep moving, none of it is queried, and it is displayed whole against one
bid. The columns that *are* queried — status, bidder, submitted_at — already
existed.

Visibility on that tab: everyone can see **that** a business bid, because that
is what the tab is for; only the buyer and the bidder themselves can open the
submission or see the contact address. Bids submitted before `details` existed
still render — the panel falls back to the composed proposal rather than
showing an empty card.

### The Digital SBO Seal and the certificate

Issuance is keyed to **payment**, not to full verification:

- Confirming the registration fee issues the seal immediately, marked
  `provisional`, with a PROVISIONAL band drawn across the badge so it cannot be
  mistaken for a completed verification.
- Approval + document compliance upgrade it to `verified`, re-rendering the
  badge. `issued_at` does not move — that is when it was paid for — and
  `verified_at` records the upgrade separately.

`SboSealIssuer::issueFor()` previously threw unless `isFullyVerified()`, so a
paid member had nothing at all while compliance reviewed their documents.
Status is part of the image filename, so upgrading cannot serve a cached
provisional badge, and the superseded file is deleted rather than orphaned.

**The seal is not the certificate.** The PNG stamps only the registration
number onto the template plaque — it says nothing about whose business it is.
`GET /sbo/{sbo}/certificate` renders a PDF (dompdf) around the badge carrying
the name, legal structure, category, registered address, contacts, website and
the dates the registration reached each stage. Generated per request rather
than stored: every field is a live column on `sbos`, so a cached PDF could only
be a stale copy. Owner or compliance admin only, and 404 without a settled fee.

The badge artwork itself is unchanged — `resources/images/sbo-seal-template.png`,
the old design's plaque.

### Typechecking

`npm run typecheck` runs `vue-tsc` over `resources/js` and `npm run build`
gates on it. `tsconfig.json` mirrors the `@` alias from `vite.config.js`;
`resources/js/spa/types/inertia.d.ts` types the props
`HandleInertiaRequests::share()` sends, so `page.props.auth` no longer has to be
cast at each call site.

`config/inertia.php` is published for one reason: the package resolves pages
from `resources/js/Pages`, this app resolves them from `resources/js/spa/views`,
and until that was corrected `assertInertia(...)->component(...)` failed with
"page component file does not exist" for every screen.

### Unbuilt screen

`SboVerifyView` is a 13-line `SCAFFOLD ONLY` placeholder. The route works; the
screen was never built from Figma `223:4647`.
