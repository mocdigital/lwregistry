<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SboBrowseFacets;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * "Explore the Global Directory" — the home page's five browse-by rows.
 *
 * All five linked to `/categories` whatever the label said, so "By Business
 * Size" and "By Industry" landed on the same page. Each row is a facet now, and
 * this renders the index of one: every value that has a listing behind it, with
 * a count, linking into the directory filtered by that value.
 *
 * The facets themselves — what they read, how they count — live in
 * SboBrowseFacets, because the directory has to filter by the same definitions
 * this page counts by.
 */
final class BrowseController extends Controller
{
    public function show(string $facet): InertiaResponse
    {
        abort_unless(SboBrowseFacets::exists($facet), 404);

        $definition = SboBrowseFacets::definition($facet);

        return Inertia::render('public/BrowseView', [
            'facet' => $facet,
            'title' => $definition['title'],
            'blurb' => $definition['blurb'],
            'options' => SboBrowseFacets::options($facet)->values(),
            // The other four, so a visitor can cross from one facet to another
            // without going back to the home page for the menu.
            'facets' => SboBrowseFacets::menu(),
        ]);
    }
}
