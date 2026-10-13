<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\Sbo;
use App\Support\AdminPages;
use App\Support\SboNetworkAccess;
use App\Support\SboSealPanel;
use App\Support\SboWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class DashboardController extends Controller
{
    public function index(): InertiaResponse|RedirectResponse
    {
        $user = auth()->user();

        if ($user->role->isAdmin() && ! app(SboWorkspaceAccess::class)->hasOwnerWorkspace($user)) {
            return redirect()->route(AdminPages::firstAccessibleRouteName($user) ?? 'home');
        }

        /**
         * ----------------------------------------
         * Businesses
         * ----------------------------------------
         */
        $verifiedBusinesses = $user->businesses()
            ->where('is_verified', true)
            ->with(['category', 'country'])
            ->get();

        $unverifiedBusinesses = $user->businesses()
            ->where('is_verified', false)
            ->with(['category', 'country'])
            ->get();

        /**
         * ----------------------------------------
         * Favorites
         * ----------------------------------------
         */
        $favorites = $user->favorites()
            ->with(['category', 'country'])
            ->get();

        /**
         * ----------------------------------------
         * Recently viewed businesses
         * ----------------------------------------
         */
        $recentlyViewed = $user->businessViews()
            ->with(['business.category', 'business.country'])
            ->latest()
            ->take(10)
            ->get()
            ->pluck('business')
            ->unique('id')
            ->filter();

        $verifiedNetworkSbos = app(SboNetworkAccess::class)->verifiedSbosFor($user);
        $sboNetworkReady = $verifiedNetworkSbos->isNotEmpty();
        $verifiedNetworkSboIds = $verifiedNetworkSbos->pluck('id')->all();

        $availableRfqs = PurchaseOrder::query()
            ->where('status', 'open')
            ->visibleToAnySbo($verifiedNetworkSboIds)
            ->where(function ($query): void {
                $query->whereNull('order_deadline')
                    ->orWhere('order_deadline', '>=', Carbon::now());
            })
            ->whereDoesntHave('bids', function ($query): void {
                $query->where('user_id', auth()->id());
            })
            ->whereDoesntHave('bids', function ($query) use ($verifiedNetworkSboIds): void {
                $query->whereIn('bidder_sbo_id', $verifiedNetworkSboIds);
            })
            ->with(['category', 'sbo', 'invites'])
            ->latest()
            ->get()
            ->filter(fn (PurchaseOrder $rfq): bool => $verifiedNetworkSbos
                ->contains(fn (Sbo $sbo): bool => $rfq->canSboBid($sbo)))
            ->values();

        /**
         * ----------------------------------------
         * Bids sent by this user
         * ----------------------------------------
         */
        $bidsSent = PurchaseOrderBid::with([
            'purchaseOrder.sbo',
            'purchaseOrder.category',
            'business',
            'bidderSbo',
        ])
            ->where('user_id', $user->id)
            ->latest('submitted_at')
            ->get()
            ->map(function (PurchaseOrderBid $bid): PurchaseOrderBid {
                $rfq = $bid->purchaseOrder;

                if ($rfq instanceof PurchaseOrder) {
                    // Attach vendor-specific context
                    $rfq->setAttribute('user_bid', [
                        'proposal' => $bid->proposal,
                        'attachment_path' => $bid->attachment_path,
                        'status' => $bid->status,
                        'submitted_at' => $bid->submitted_at?->toDateTimeString(),
                        'business_name' => $bid->bidderSbo->name ?? $bid->business->name ?? 'SBO',
                    ]);
                }

                return $bid;
            });

        /**
         * ----------------------------------------
         * Bids accepted (awarded contracts)
         * ----------------------------------------
         */
        $bidsAccepted = PurchaseOrderBid::with([
            'purchaseOrder.sbo',
            'purchaseOrder.category',
            'business',
            'bidderSbo',
        ])
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->latest('updated_at')
            ->get()
            ->map(function (PurchaseOrderBid $bid): PurchaseOrderBid {
                $rfq = $bid->purchaseOrder;

                if ($rfq instanceof PurchaseOrder) {
                    $rfq->setAttribute('user_bid', [
                        'proposal' => $bid->proposal,
                        'attachment_path' => $bid->attachment_path,
                        'status' => $bid->status,
                        'submitted_at' => $bid->submitted_at?->toDateTimeString(),
                        'accepted_at' => $bid->updated_at?->toDateTimeString(),
                        'business_name' => $bid->bidderSbo->name ?? $bid->business->name ?? 'SBO',
                    ]);
                }

                return $bid;
            });

        /*
         * The Vue design has no separate "user dashboard" frame — the signed-in
         * landing screens are the profile dashboard and the SBO workspace. This
         * renders the profile dashboard so the last Blade page in the signed-in
         * flow is gone; `guest` bounces here when an authenticated visitor
         * clicks Sign in, which is how the old design kept reappearing.
         */
        $sbos = $user->sbos()->orderBy('name')->get();

        return Inertia::render('account/ProfileDashboardView', [
            'user' => [
                'name' => mb_trim($user->firstname . ' ' . $user->lastname),
                'email' => $user->email,
                'phone' => $user->phone,
                'emailVerified' => $user->hasVerifiedEmail(),
                /*
                 * The screen drew the Figma stock avatar for everybody, so a
                 * member who had uploaded a photo saw someone else's face on
                 * their own profile. Null falls back to that stock asset, which
                 * is the right default for a member with no photo.
                 */
                'photoUrl' => $user->profilePhotoUrl(),
                // The KingsChat handle shown under the name.
                'handle' => $user->getAttributes()['kc_handle'] ?? null,
            ],
            'stats' => [
                'listings' => $sbos->count(),
                /*
                 * Fully verified: approved, documents compliant, fee settled.
                 * Counting `verification_status === approved` alone disagreed
                 * with the badge the cards draw, which uses isFullyVerified().
                 */
                'verified' => $sbos->filter(fn (Sbo $s): bool => $s->isFullyVerified())->count(),
                'pending' => $sbos->reject(fn (Sbo $s): bool => $s->isFullyVerified())->count(),
                'views' => (int) $verifiedBusinesses->sum('views_count')
                    + (int) $unverifiedBusinesses->sum('views_count'),
            ],
            /*
             * The section rendered its empty state unconditionally — this
             * payload was sent and never read, so an owner with two listings
             * saw "All (2)" above the words "No listings found".
             *
             * `status` is the filter key, not a label: the tabs match on it and
             * the pill is translated at the call site, so switching language
             * cannot break the filtering.
             */
            'listings' => $sbos->map(fn (Sbo $sbo): array => [
                'name' => $sbo->name,
                'slug' => $sbo->slug,
                'category' => $sbo->category,
                'verified' => $sbo->isFullyVerified(),
                'status' => $sbo->verification_status,
                'address' => mb_trim(implode(', ', array_filter([$sbo->address, $sbo->city]))),
                'logoUrl' => $sbo->logoUrl(),
                // Drives the logo ring colour: SBO = blue, COB = gold.
                'entityType' => $sbo->entity_type,
                'href' => route('businesses.show', $sbo->slug),
                // Where the owner acts on it: the application progress screen.
                'manageUrl' => route('sbo.applications.progress', $sbo),
            ])->values(),
            /*
             * SBO_Seal_Container 34:136. The card was hardcoded to
             * "No seal issued yet" and a validation checklist with three static
             * ticks, so it said the same thing to a fully verified business as
             * to a brand new one, and there was no way to download anything.
             */
            'seal' => $this->sealPanel($sbos),
        ]);
    }

    /**
     * Profile dashboard, served from Vue. Was a Blade page, which is why
     * signing in and opening Profile still showed the old design.
     */
    public function profile(): InertiaResponse
    {
        $user = auth()->user();
        $sbos = $user->sbos()
            ->with('seal')
            ->orderBy('name')
            ->get();

        return Inertia::render('account/ProfileDashboardView', [
            'user' => [
                'name' => mb_trim($user->firstname . ' ' . $user->lastname),
                'email' => $user->email,
                'phone' => $user->phone,
                'emailVerified' => $user->hasVerifiedEmail(),
                /*
                 * The screen drew the Figma stock avatar for everybody, so a
                 * member who had uploaded a photo saw someone else's face on
                 * their own profile. Null falls back to that stock asset, which
                 * is the right default for a member with no photo.
                 */
                'photoUrl' => $user->profilePhotoUrl(),
                // The KingsChat handle shown under the name.
                'handle' => $user->getAttributes()['kc_handle'] ?? null,
            ],
            // The screen's four stat tiles and its listing tabs, counted rather
            // than the zeros they were hardcoded to.
            'stats' => [
                'listings' => $sbos->count(),
                /*
                 * Fully verified: approved, documents compliant, fee settled.
                 * Counting `verification_status === approved` alone disagreed
                 * with the badge the cards draw, which uses isFullyVerified().
                 */
                'verified' => $sbos->filter(fn (Sbo $s): bool => $s->isFullyVerified())->count(),
                'pending' => $sbos->reject(fn (Sbo $s): bool => $s->isFullyVerified())->count(),
                'views' => (int) $user->businesses()->sum('views_count'),
            ],
            /*
             * The section rendered its empty state unconditionally — this
             * payload was sent and never read, so an owner with two listings
             * saw "All (2)" above the words "No listings found".
             *
             * `status` is the filter key, not a label: the tabs match on it and
             * the pill is translated at the call site, so switching language
             * cannot break the filtering.
             */
            'listings' => $sbos->map(fn (Sbo $sbo): array => [
                'name' => $sbo->name,
                'slug' => $sbo->slug,
                'category' => $sbo->category,
                'verified' => $sbo->isFullyVerified(),
                'status' => $sbo->verification_status,
                'address' => mb_trim(implode(', ', array_filter([$sbo->address, $sbo->city]))),
                'logoUrl' => $sbo->logoUrl(),
                // Drives the logo ring colour: SBO = blue, COB = gold.
                'entityType' => $sbo->entity_type,
                'href' => route('businesses.show', $sbo->slug),
                // Where the owner acts on it: the application progress screen.
                'manageUrl' => route('sbo.applications.progress', $sbo),
            ])->values(),
            // Both `index()` and `profile()` render ProfileDashboardView, so
            // both must send the card its state or one of them mounts a
            // component with a required prop missing.
            'seal' => $this->sealPanel($sbos),
        ]);
    }

    public function clearFavorites(): RedirectResponse
    {
        auth()->user()->favorites()->detach();

        return to_route('dashboard')
            ->with('success', 'All favorites have been cleared.');
    }

    /**
     * The state of the profile's Digital SBO Seal card.
     *
     * A seal is issued the moment the registration fee is settled — marked
     * provisional — and upgraded to verified once approval and document
     * compliance land. So the card has three states, not two: nothing yet,
     * provisional, verified.
     *
     * @param  Collection<int, Sbo>  $sbos
     * @return array<string, mixed>
     */
    /**
     * Delegates to SboSealPanel, which the application-progress screen shares —
     * that screen used to draw its own static "LBN Certified Member 2026" card.
     */
    private function sealPanel(Collection $sbos): array
    {
        return SboSealPanel::for($sbos);
    }
}
