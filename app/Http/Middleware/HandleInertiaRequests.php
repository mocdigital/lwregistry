<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Sbo;
use App\Support\AdminPages;
use App\Support\DataLabels;
use App\Support\LiveOpportunities;
use App\Support\Locales;
use App\Support\SboWorkspaceAccess;
use App\Support\UserPreferences;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            /*
             * The app may be mounted below the domain root in local MAMP
             * (`/lwregistry/public`). Inertia links written as `/login` would
             * otherwise ask Apache for `http://localhost:8888/login`, bypassing
             * Laravel entirely and rendering Apache's 404 page.
             */
            'app' => [
                'basePath' => mb_rtrim($request->getBaseUrl(), '/'),
            ],

            /*
             * Shared so the header can tell signed-in from signed-out on every
             * page. Without it PublicLayout always drew the guest bar, so a
             * signed-in visitor on Home or the directory saw "Sign in".
             */
            'auth' => [
                'user' => $user ? [
                    // For the private notification channel the header subscribes
                    // to (App.Models.User.{id}) — the source of the live unread
                    // badge and its chime.
                    'id' => $user->id,
                    'name' => mb_trim($user->firstname . ' ' . $user->lastname),
                    'email' => $user->email,
                    'initials' => mb_strtoupper(
                        mb_substr((string) $user->firstname, 0, 1) . mb_substr((string) $user->lastname, 0, 1)
                    ),
                    /*
                     * Shared, so an uploaded photo reaches every avatar in the
                     * shell rather than only the two screens that happened to
                     * ask their own controller for it. Null falls back to
                     * initials, which is what the frames draw for a member with
                     * no photo.
                     */
                    'photoUrl' => $user->profilePhotoUrl(),
                    /*
                     * The workspace switcher (components/workspace-switcher.blade.php)
                     * only ever existed on the Blade shell, so an admin who also
                     * owns an SBO lost the way back to the admin panel the moment
                     * they landed on a Vue page — the panel is Blade/Livewire and
                     * unreachable from the SPA otherwise.
                     *
                     * Null for everyone else, which is also what hides the item.
                     * The target is their first accessible admin page, not a fixed
                     * /admin, because admins hold per-page permissions.
                     */
                    'adminUrl' => $user->isAdmin()
                        && ($adminRoute = AdminPages::firstAccessibleRouteName($user))
                            ? route($adminRoute)
                            : null,
                    /*
                     * The second half of the switcher. Blade drew both buttons
                     * or neither; here the way back to /admin is offered to any
                     * admin, while "SBO Owner Workspace" needs an SBO to switch
                     * to — an admin who owns nothing has no owner workspace.
                     * Only queried for admins, so it costs nothing for members.
                     */
                    'ownerWorkspace' => $user->isAdmin()
                        && app(SboWorkspaceAccess::class)->hasOwnerWorkspace($user),

                    /*
                     * How many notifications are unread, for the count on the
                     * header bell. Shared so the badge is right on every page
                     * without each one counting for itself.
                     */
                    'unreadNotifications' => $user->unreadNotifications()->count(),
                    /*
                     * The word this owner's workspace uses for their business —
                     * 'SBO' or 'COB'. Drives the entity-aware chrome (nav,
                     * header, badges) on any page that is not about one specific
                     * listing; a business page overrides it with its own
                     * `entityTerm` prop. Taken from the owner's first listing,
                     * defaulting to SBO for a member who has not registered yet.
                     */
                    'entityTerm' => $user->sbos()->oldest()->value('entity_type') ?: Sbo::ENTITY_SBO,
                    /*
                     * Whether this member owns a business at all. The signed-in
                     * nav offered Dashboard and Network to everyone, and both
                     * are behind `sbo_manager` — so a member who had not added
                     * a business yet was shown two links that refused them.
                     * Their destination is the add-business form instead.
                     */
                    'hasBusiness' => $user->sbos()->exists(),
                ] : null,
            ],

            /*
             * The "Live Opportunities" rail, which drew the same three invented
             * rows on every screen carrying it. Shared rather than added to the
             * ten controllers whose screens include the rail — a panel present
             * on a page whose controller forgot its data is worse than one
             * nobody wired. Cached; see LiveOpportunities.
             */
            'liveOpportunities' => LiveOpportunities::forRail(),

            /*
             * The live ad for every placement slot, shared so any screen can
             * drop an <AdSlot> without its own controller fetching one. A slot
             * with no live ad is null, which is what makes the component render
             * nothing — no house banner, no empty box — until an ad is added.
             */
            'ads' => \App\Support\AdService::sharedSlots(),

            /*
             * Interface language.
             *
             * The whole dictionary rides along on every response rather than
             * being fetched separately: it is a few KB, it must be present
             * before the first paint or the page flashes English at a French
             * reader, and a second request for it would be one more thing to
             * fail. `locales` is the selector's list; `translations` is the
             * active language only, not all three.
             */
            'i18n' => [
                'locale' => app()->getLocale(),
                'fallback' => Locales::fallback(),
                'locales' => Locales::forSelector(),
                'translations' => self::translations(app()->getLocale()),
            ],

            /*
             * Appearance preferences, shared so every screen honours them —
             * persisting a preference and applying it are different things,
             * and the settings screen is not the only page a member sees.
             * Null for a guest, who gets the designed defaults.
             */
            'appearance' => $user
                ? UserPreferences::for($user)['appearance']
                : null,

            /*
             * Reverb, for the screens that subscribe to a private channel (the
             * SBO Network inbox). Null when no key is configured — the normal
             * state in local development — and the client then simply does not
             * connect, which is why `useEcho` treats this as optional rather
             * than assuming a socket exists.
             *
             * Mirrors resources/views/partials/echo.blade.php, which does the
             * same job for the Livewire side.
             */
            'reverb' => ($key = config('reverb.apps.apps.0.key')) ? [
                'key' => $key,
                'wsHost' => config('broadcasting.connections.reverb.options.host') ?: $request->getHost(),
                'wsPort' => (int) config('broadcasting.connections.reverb.options.port', 8080),
                'wssPort' => (int) config('broadcasting.connections.reverb.options.port', 443),
                'forceTLS' => 'https' === config('broadcasting.connections.reverb.options.scheme', 'http'),
            ] : null,

            /*
             * Redirect-back messages so a screen can confirm a write landed
             * without inventing its own channel. `fees_success` / `fees_error`
             * are the keys SboEspeesPaymentController and SboFeeController
             * already redirect with — the Vue screens rendered neither, so an
             * unconfigured gateway or a rejected payment looked like a button
             * that did nothing.
             */
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'feesSuccess' => fn () => $request->session()->get('fees_success'),
                'feesError' => fn () => $request->session()->get('fees_error'),
                /*
                 * Two-factor recovery codes, flashed on the response that
                 * generates them. Shown once and never persisted to the client
                 * beyond that render — the stored copies are encrypted and
                 * cannot be read back.
                 */
                'recoveryCodes' => fn () => $request->session()->get('recoveryCodes'),
                /*
                 * The advertising inquiry's own confirmation. It was flashed
                 * under `advertising_inquiry_success` and shared under none of
                 * these, so the row landed in the table, the admin was
                 * notified, and the visitor saw nothing at all — a form that
                 * had, as far as they could tell, done nothing.
                 */
                'advertisingInquiry' => fn () => $request->session()->get('advertising_inquiry_success'),
                'advertisingAuthMessage' => fn () => $request->session()->get('advertising_auth_message'),
                /*
                 * The Espees checkout URL for a submitted campaign. The builder
                 * posts over Inertia and the proxy strips the X-Inertia-Location
                 * header a 409 would carry, so the URL is flashed here and the
                 * SPA navigates to it directly. See AdCampaignController::submit.
                 */
                'espeesRedirect' => fn () => $request->session()->get('espees_redirect'),
                // "Subscribe Now" on the home rail.
                'subscribed' => fn () => $request->session()->get('subscribed'),
            ],
        ];
    }

    /**
     * The JSON dictionary for one locale, merged over English.
     *
     * Merged rather than returned alone so a key that has not been translated
     * yet falls back to English instead of rendering as its own key — a French
     * reader seeing "directory.title" is worse than one seeing "Directory".
     *
     * @return array<string, string>
     */
    private static function translations(string $locale): array
    {
        $read = static function (string $code): array {
            $path = lang_path($code . '.json');

            if ( ! is_file($path)) {
                return [];
            }

            return json_decode((string) file_get_contents($path), true) ?: [];
        };

        $english = $read(Locales::fallback());

        $dictionary = $locale === Locales::fallback()
            ? $english
            : array_merge($english, $read($locale));

        /*
         * Country names are generated, not typed. ICU already holds every one
         * of them in every language and `countries.iso2` is the key into it, so
         * the alternative would be 250 hand-written lines per locale that go
         * stale. Only the countries some listing actually trades in are shipped
         * — the client can never render one that no listing holds.
         */
        return array_merge($dictionary, DataLabels::countryLabels());
    }
}
