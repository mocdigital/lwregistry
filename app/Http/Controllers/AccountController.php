<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Models\SboConnection;
use App\Models\SboSeal;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * `/account` — the Vue My Account screen.
 *
 * The shell linked here six times and the route did not exist. Laravel's
 * nearest equivalent is `GET /profile` (DashboardController@profile), which
 * renders a Blade page and answers a different question: it counts legacy
 * `businesses` and issues SBO seals. This screen is about the four listing
 * tabs, so it queries what those tabs actually show.
 */
final class AccountController extends Controller
{
    public function index(): InertiaResponse
    {
        $user = auth()->user();

        $sbos = $user->sbos()->with('media')->orderBy('name')->get();

        return Inertia::render('account/MyAccountView', [
            'user' => [
                'name' => mb_trim($user->firstname . ' ' . $user->lastname),
                'email' => $user->email,
            ],
            /*
             * The tab bar is fixed at four; only their contents vary. Sent as
             * one keyed set so the screen can label each tab with its own count
             * instead of the hardcoded "Unverified Listings (1)" it shipped
             * with.
             */
            /*
             * Recent Activity — derived from the records that actually exist,
             * not from `activity_logs`. That table has one admin-oriented row
             * ("user_registered") and nothing user-scoped, so backfilling it
             * would mean inventing history. Registrations, payments, document
             * reviews, connections and seal issuance are all already stamped
             * with the time they happened, and they ARE the activity.
             */
            'activity' => $this->activity($user, $sbos),
            'listings' => [
                'verified' => $this->shape($sbos->where('verification_status', 'approved')),
                'unverified' => $this->shape($sbos->where('verification_status', '!=', 'approved')),
                'favorites' => $this->shapeBusinesses($user->favorites()->get()),
                'recent' => $this->shapeBusinesses(
                    $user->businessViews()->with('business')->latest()->take(10)->get()
                        ->pluck('business')->filter()->unique('id')->values()
                ),
            ],
        ]);
    }

    /**
     * The signed-in owner's ten most recent events, newest first.
     *
     * @param  \Illuminate\Support\Collection<int, Sbo>  $sbos
     * @return array<int, array<string, mixed>>
     */
    private function activity($user, $sbos): array
    {
        $events = collect();

        foreach ($sbos as $sbo) {
            $events->push([
                'title' => __('activity.listing_registered', ['name' => $sbo->name]),
                'at' => $sbo->created_at,
                'icon' => 'file',
            ]);

            if ($sbo->payment_submitted_at) {
                $events->push([
                    'title' => __('activity.payment_submitted', ['name' => $sbo->name]),
                    'at' => $sbo->payment_submitted_at,
                    'icon' => 'file',
                ]);
            }

            if ($sbo->payment_confirmed_at) {
                $events->push([
                    'title' => __('activity.payment_confirmed', ['name' => $sbo->name]),
                    'at' => $sbo->payment_confirmed_at,
                    'icon' => 'check',
                ]);
            }

            if ($sbo->documents_compliant_at) {
                $events->push([
                    'title' => __('activity.documents_accepted', ['name' => $sbo->name]),
                    'at' => $sbo->documents_compliant_at,
                    'icon' => 'check',
                ]);
            }

            if ($sbo->date_approved) {
                $events->push([
                    'title' => __('activity.listing_approved', ['name' => $sbo->name]),
                    'at' => $sbo->date_approved,
                    'icon' => 'check',
                ]);
            }
        }

        $sboIds = collect($sbos)->pluck('id');

        // Each document review is its own event — that is what an applicant
        // is actually waiting on.
        SboComplianceDocument::with('type')
            ->whereIn('sbo_id', $sboIds)
            ->get()
            ->each(function (SboComplianceDocument $doc) use ($events): void {
                $events->push([
                    'title' => __('activity.document_uploaded', ['name' => $doc->type?->name ?? __('activity.a_compliance_document')]),
                    'at' => $doc->created_at,
                    'icon' => 'file',
                ]);

                if ($doc->reviewed_at) {
                    $events->push([
                        'title' => __(
                            'approved' === $doc->status ? 'activity.document_approved' : 'activity.document_rejected',
                            ['name' => $doc->type?->name ?? __('activity.a_compliance_document')],
                        ),
                        'at' => $doc->reviewed_at,
                        'icon' => 'approved' === $doc->status ? 'check' : 'star',
                    ]);
                }
            });

        SboSeal::whereIn('sbo_id', $sboIds)->get()->each(function (SboSeal $seal) use ($events): void {
            $events->push([
                'title' => __('activity.seal_issued', ['number' => $seal->issued_number]),
                'at' => $seal->issued_at,
                'icon' => 'star',
            ]);
        });

        SboConnection::with(['requester', 'recipient'])
            ->where('status', SboConnection::STATUS_ACCEPTED)
            ->where(fn ($q) => $q->whereIn('requester_sbo_id', $sboIds)->orWhereIn('recipient_sbo_id', $sboIds))
            ->get()
            ->each(function (SboConnection $c) use ($events, $sboIds): void {
                $other = $sboIds->contains($c->requester_sbo_id) ? $c->recipient : $c->requester;

                $events->push([
                    'title' => __('activity.connected_with', ['name' => $other?->name ?? __('activity.another_business')]),
                    'at' => $c->responded_at ?? $c->updated_at,
                    'icon' => 'star',
                ]);
            });

        return $events
            ->filter(fn (array $e): bool => null !== $e['at'])
            ->sortByDesc(fn (array $e) => $e['at'])
            ->take(10)
            ->map(fn (array $e): array => [
                'title' => $e['title'],
                'date' => $e['at']->format('F j, Y'),
                'icon' => $e['icon'],
            ])
            ->values()
            ->all();
    }

    /** @param iterable<Sbo> $sbos */
    private function shape(iterable $sbos): array
    {
        return collect($sbos)->map(fn (Sbo $sbo): array => [
            'id' => $sbo->id,
            'kind' => 'sbo',
            'name' => $sbo->name,
            'slug' => $sbo->slug,
            'address' => mb_trim(implode(', ', array_filter([$sbo->address, $sbo->city]))),
            'verified' => 'approved' === $sbo->verification_status,
            // The card thumbnail was one shared stock photo for every listing.
            'coverUrl' => $sbo->coverUrl(),
            'logoUrl' => $sbo->logoUrl(),
            // Drives the logo ring colour: SBO = blue, COB = gold.
            'entityType' => $sbo->entity_type,
            // Sbo has no view counter; the legacy Business model does.
            'views' => null,
            /*
             * Non-null only for the viewer's own listing, which is what gates
             * the edit pencil on the card — the same cards render in Favorites
             * and Recently Viewed for businesses the viewer does not own.
             *
             * The pencil goes to the profile editor (SboListingProfileController);
             * this URL is the "View application" link beside it, since tracking
             * verification and editing a profile are separate jobs.
             */
            'manageUrl' => route('sbo.applications.progress', $sbo),
            'publicUrl' => route('businesses.show', $sbo->slug),
            /*
             * An approved registration is a live entry on a paid platform —
             * deleting it would silently revoke the owner's own membership, so
             * it is withdrawn through support rather than a bin icon.
             */
            'canDelete' => 'approved' !== $sbo->verification_status,
        ])->values()->all();
    }

    /**
     * Favourites and recently-viewed are still the legacy Business model, not
     * Sbo, so they are shaped separately rather than forced through one map.
     */
    private function shapeBusinesses(Collection|\Illuminate\Support\Collection $businesses): array
    {
        return collect($businesses)->map(fn ($b): array => [
            'id' => $b->id,
            // Favourites and recently-viewed are someone else's listings, so
            // they get the share link and nothing that would modify them.
            'kind' => 'business',
            'name' => $b->name,
            'slug' => $b->slug,
            'address' => mb_trim(implode(', ', array_filter([$b->address ?? null, $b->city ?? null]))),
            'verified' => (bool) ($b->is_verified ?? false),
            'views' => $b->views_count ?? null,
            'manageUrl' => null,
            // The legacy Business model carries no branding collections.
            'coverUrl' => null,
            'logoUrl' => null,
            'entityType' => null,
            'publicUrl' => route('businesses.show', $b->slug),
            'canDelete' => false,
        ])->values()->all();
    }
}
