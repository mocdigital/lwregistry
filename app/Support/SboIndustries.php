<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The industry an SBO trades in — the broad sector above `category`.
 *
 * The directory design has both an "All Categories" and an "All Industries"
 * filter, and only the first had anything behind it: `sbos` carried `category`
 * alone, so the industry select shipped disabled.
 *
 * The two are a taxonomy, not a duplicate. `categories` holds 30-odd specific
 * trades ("Bookstores & Publishers", "Graphics & Printing"), which is the right
 * granularity for a listing and far too fine for a filter — nobody browses a
 * directory one trade at a time. Industries are the dozen-or-so sectors those
 * trades group into.
 *
 * Registration does not ask for both. An owner picks their primary category and
 * the industry follows from it, because a form that asks the same question at
 * two zoom levels gets two inconsistent answers. `sbos.industry` is still a
 * real column rather than a join at read time, so it can be indexed, filtered
 * and counted like any other facet — Sbo keeps it in step on save.
 */
final class SboIndustries
{
    /**
     * Category name => industry.
     *
     * Every category seeded into `categories` appears here. A category with no
     * entry classifies as null rather than guessing, which is visible in the
     * directory (it drops out of industry filtering) instead of silently wrong.
     *
     * @var array<string, string>
     */
    private const MAP = [
        // Faith & Community
        'Churches & Ministries' => 'Faith & Community',
        'Faith-Based NGOs' => 'Faith & Community',
        'Non-Profits & NGOs' => 'Faith & Community',

        // Education
        'Faith-Based Schools' => 'Education',
        'Education & Training' => 'Education',
        'Education' => 'Education',
        'Childcare & Family Services' => 'Education',

        // Hospitality & Events
        'Restaurants & Cafés' => 'Hospitality & Events',
        'Hotels & Hospitality' => 'Hospitality & Events',
        'Travel & Tourism' => 'Hospitality & Events',
        'Events & Conferences' => 'Hospitality & Events',

        // Construction & Real Estate
        'Construction & Real Estate' => 'Construction & Real Estate',
        'Interior Design' => 'Construction & Real Estate',

        // Technology
        'Technology Services' => 'Technology',
        'Software Development' => 'Technology',

        // Healthcare
        'Health & Medical Services' => 'Healthcare',

        // Professional Services
        'Legal Consultants' => 'Professional Services',
        'Consulting & Advisory' => 'Professional Services',
        'Marketing & Advertising' => 'Professional Services',

        // Financial Services
        'Finance & Banking' => 'Financial Services',

        // Media & Creative
        'Bookstores & Publishers' => 'Media & Creative',
        'Media & Communications' => 'Media & Creative',
        'Music & Entertainment' => 'Media & Creative',
        'Photography & Media' => 'Media & Creative',
        'Graphics & Printing' => 'Media & Creative',
        'Entertainment' => 'Media & Creative',
        'Arts' => 'Media & Creative',
        'Media' => 'Media & Creative',

        // Transport & Logistics
        'Transportation & Logistics' => 'Transport & Logistics',
        'Transport & Logistics' => 'Transport & Logistics',
        'Aviation' => 'Transport & Logistics',

        // Manufacturing
        'Engineering & Manufacturing' => 'Manufacturing',
        'Manufacturing' => 'Manufacturing',

        // Retail & Consumer Goods
        'Fashion & Apparel' => 'Retail & Consumer Goods',
        'Retail & Wholesale' => 'Retail & Consumer Goods',
        'FMCG' => 'Retail & Consumer Goods',

        // Agriculture
        'Agriculture & Agro Trade' => 'Agriculture',

        // Consumer Services
        'Cleaning & Maintenance' => 'Consumer Services',
        'Beauty & Personal Care' => 'Consumer Services',
        'Sports & Recreation' => 'Consumer Services',
    ];

    /**
     * Every industry, in the order the filter should list them.
     *
     * Derived from the map rather than kept as a second list, so a category
     * cannot be classified into an industry the filter does not offer.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return collect(self::MAP)->values()->unique()->sort()->values()->all();
    }

    /** The industry a category belongs to, or null if it is unclassified. */
    public static function forCategory(?string $category): ?string
    {
        if (null === $category || '' === mb_trim($category)) {
            return null;
        }

        return self::MAP[mb_trim($category)] ?? null;
    }

    /**
     * The categories that make up an industry — the second level of the
     * taxonomy, for a screen that wants to drill down.
     *
     * @return Collection<int, string>
     */
    public static function categoriesIn(string $industry): Collection
    {
        return collect(self::MAP)
            ->filter(fn (string $value): bool => $value === $industry)
            ->keys()
            ->values();
    }
}
