<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Cache;

/**
 * The "Live Opportunities" rail.
 *
 * Two components drew the same three invented rows — a government IT tender in
 * Nairobi with a deadline in May 2025 — on every screen that carries the rail,
 * so the panel advertised opportunities that did not exist while real open
 * requests sat unseen on /procurement.
 *
 * Shared through HandleInertiaRequests rather than added to ten controllers:
 * the rail appears on the profile, notifications, categories, browse, business
 * profile and several others, and a panel that is present on a screen whose
 * controller forgot to send its data is worse than one nobody wired.
 *
 * Cached briefly. It is the same handful of rows for every viewer, and it would
 * otherwise be a query on every Inertia response on the site.
 */
final class LiveOpportunities
{
    private const CACHE_KEY = 'live_opportunities_rail';

    private const CACHE_MINUTES = 5;

    /** Rows the rail shows before "View all". */
    private const LIMIT = 3;

    /** A deadline inside this many days is drawn as urgent. */
    private const CLOSING_SOON_DAYS = 14;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forRail(): array
    {
        return Cache::remember(
            self::cacheKey(app()->getLocale()),
            now()->addMinutes(self::CACHE_MINUTES),
            fn (): array => self::query(),
        );
    }

    /** Called when an RFQ is written, so the rail is not stale for 5 minutes. */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);

        foreach (array_unique([...Locales::codes(), Locales::fallback(), app()->getLocale()]) as $locale) {
            Cache::forget(self::cacheKey($locale));
        }
    }

    private static function cacheKey(string $locale): string
    {
        return self::CACHE_KEY . ':' . $locale;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function query(): array
    {
        return PurchaseOrder::query()
            ->with(['sbo', 'category', 'media'])
            ->where('status', 'open')
            ->where('visibility', PurchaseOrder::VISIBILITY_PUBLIC)
            /*
             * Soonest deadline first, then newest — an opportunity closing this
             * week is more use in a three-row panel than one closing in a year.
             * A request with no deadline sorts last rather than leading on a
             * NULL.
             */
            ->orderByRaw('order_deadline is null')
            ->orderBy('order_deadline')
            ->latest()
            ->take(self::LIMIT)
            ->get()
            ->map(function (PurchaseOrder $order): array {
                $deadline = $order->order_deadline;
                $urgent = $deadline && $deadline->diffInDays(now()) <= self::CLOSING_SOON_DAYS;

                return [
                    'id' => (string) $order->id,
                    // The design's own two kinds. Everything here is a
                    // procurement request; there is no partnerships or projects
                    // table to draw the others from.
                    'kind' => mb_strtoupper(__('rfq.type.procurement')),
                    'title' => $order->title,
                    /*
                     * The buyer's own photo for this request. Null until they
                     * attach one, and the rail then falls back to its stock
                     * artwork — which used to be the only source, so a
                     * road-construction shot could illustrate a request for
                     * accountancy.
                     */
                    'imageUrl' => $order->featuredImageUrl(),
                    // Where the work is, falling back to who is asking — the
                    // design's row reads as a place either way.
                    'place' => $order->delivery_location
                        ?: mb_trim(implode(', ', array_filter([$order->sbo?->city, $order->sbo?->country])))
                        ?: ($order->sbo?->name ?? 'Loveworld Registry'),
                    'when' => $deadline
                        ? __('live_opportunities.deadline', ['date' => $deadline->format('M j, Y')])
                        : __('live_opportunities.ongoing'),
                    'urgent' => (bool) $urgent,
                    'url' => route('procurement.show', $order),
                ];
            })
            ->values()
            ->all();
    }
}
