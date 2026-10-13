<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Sbo;
use App\Models\Testimonial;
use App\Support\DataLabels;
use App\Support\SboBrowseFacets;
use App\Support\SboConnectionStates;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class HomeController extends Controller
{
    /**
     * Industry categories that should always be surfaced on the home page,
     * regardless of their current SBO count.
     *
     * @var array<int, string>
     */
    private array $pinnedSlugs = [
        'manufacturing', 'education', 'fmcg', 'aviation',
        'entertainment', 'arts', 'media',
    ];

    public function index(): InertiaResponse
    {
        /*
         * Who is looking, for the Connect button on each featured card. A guest
         * has no SBO, and an owner cannot connect to their own listing — the
         * card renders those as states rather than posting a request the
         * endpoint would reject.
         */
        $viewer = auth()->user();
        $mySboIds = $viewer ? $viewer->sbos()->pluck('id') : collect();

        // Most popular categories by count…
        $top = Category::orderBy('business_count', 'desc')->take(12)->get();

        // …plus any pinned categories not already in that list.
        $pinned = Category::whereIn('slug', $this->pinnedSlugs)
            ->whereNotIn('id', $top->pluck('id'))
            ->get();

        $categories = $top->concat($pinned);

        /*
         * Featured SBOs — approved only. The home page is the shop window, so
         * it is stricter than the directory, which merely excludes declined
         * registrations.
         *
         * Businesses that have actually uploaded a cover lead the row: a card
         * drawing the owner's own photography earns its place over one falling
         * back to shared stock artwork, and it makes finishing a profile
         * visibly worth doing. Newest-first still orders within each group, so
         * this is a tiebreak rather than a different ranking.
         *
         * Resolved before the render array because the connection states are
         * looked up for the whole set at once — a per-card lookup would be
         * twelve queries to draw one row of buttons.
         */
        $featured = Sbo::query()
            ->where('verification_status', 'approved')
            ->with(['media', 'addresses'])
            ->withExists(['media as has_cover_image' => fn ($query) => $query
                ->where('collection_name', Sbo::COVER_COLLECTION)])
            ->orderByDesc('has_cover_image')
            ->latest()
            ->take(12)
            ->get();

        $connections = SboConnectionStates::between($mySboIds, $featured->pluck('id'));

        return Inertia::render('public/HomeView', [
            /*
             * "Explore the Global Directory". All five rows linked to
             * /categories whatever the label said, so By Business Size and By
             * Industry landed on the same page. The labels and destinations
             * come from SboBrowseFacets rather than a second list in the view,
             * so a facet cannot be offered here and missing on arrival.
             */
            'browseFacets' => SboBrowseFacets::menu(),
            'categories' => $categories->map(fn (Category $c): array => [
                'name' => $c->name,
                'slug' => $c->slug,
            ])->values(),

            // The hero search hands off to the directory, so it offers the
            // same countries that screen filters on.
            'countries' => Sbo::query()
                ->where('verification_status', 'approved')
                ->whereNotNull('country')->where('country', '!=', '')
                ->distinct()->orderBy('country')->pluck('country')->values(),

            'featured' => $featured
                ->map(fn (Sbo $sbo): array => [
                    // The connect endpoint addresses businesses by id, not slug.
                    'id' => $sbo->id,
                    'name' => $sbo->name,
                    'slug' => $sbo->slug,
                    /*
                     * The card's own cover and logo, uploaded by the owner.
                     * Null until they do, and the screen then falls back to
                     * the stock artwork rather than showing a gap — but the
                     * fallback is no longer the only option, which it was.
                     */
                    'coverUrl' => $sbo->coverUrl(),
                    'logoUrl' => $sbo->logoUrl(),
                    // Drives the logo ring colour: SBO = blue, COB = gold.
                    'entityType' => $sbo->entity_type,
                    'countryCodes' => $sbo->countryCodes(),
                    'meta' => $this->featuredLocation($sbo),
                    'tags' => array_values(array_filter([DataLabels::get(DataLabels::CATEGORY, $sbo->category)])),
                    'connection' => SboConnectionStates::forSbo($mySboIds, $connections, $sbo->id),
                ])->values(),

            /*
             * The SBO the viewer acts as. Null for a guest and for a member who
             * owns no listing; the card offers sign-in or an explanation rather
             * than a button that cannot work.
             */
            'mySboId' => $mySboIds->first(),

            /*
             * Testimonials from businesses that won bids and agreed to have
             * their words shown. The carousel below the listings; only public
             * ones, and only from approved businesses so a delisted one drops
             * out of the showcase on its own.
             */
            'testimonials' => Testimonial::query()
                ->public()
                ->whereHas('sbo', fn ($q) => $q->where('verification_status', 'approved'))
                ->with('sbo')
                ->latest()
                ->take(12)
                ->get()
                ->map(fn (Testimonial $t): array => [
                    'id' => (string) $t->id,
                    'rating' => (int) $t->rating,
                    'headline' => $t->headline,
                    'body' => $t->body,
                    'company' => $t->sbo?->name,
                    'slug' => $t->sbo && ! $t->sbo->is_registry ? $t->sbo->slug : null,
                    'logoUrl' => $t->sbo?->logoUrl(),
                ])->values(),
        ]);
    }

    private function featuredLocation(Sbo $sbo): string
    {
        /*
         * The card already prints category as a tag, and full street/city
         * addresses make the compact home grid noisy. Keep only state + country.
         */
        return mb_trim(implode(', ', array_filter([
            $sbo->state,
            DataLabels::country($sbo->country),
        ])));
    }
}
