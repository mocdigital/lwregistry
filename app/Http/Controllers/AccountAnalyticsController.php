<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\SboView;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * "View Analytics" — the profile's Quick Actions card.
 *
 * The card promised "Track views, clicks and leads" and linked to /dashboard,
 * which is the profile it sits on. This is the page it was pointing at.
 *
 * Everything here is counted from rows that exist. Profile views come from
 * `sbo_views`, which was added for this — see the migration for why it is a log
 * rather than a counter. Nothing is modelled that the registry does not record:
 * there is no click tracking on outbound website links, and the page says so
 * rather than drawing an empty chart.
 */
final class AccountAnalyticsController extends Controller
{
    /** Windows the range selector offers, in days. */
    private const RANGES = [7, 30, 90];

    public function __invoke(Request $request): InertiaResponse
    {
        $user = $request->user();
        $sbos = $user->sbos()->orderBy('name')->get();
        $sboIds = $sbos->pluck('id');

        $days = in_array((int) $request->query('days'), self::RANGES, true)
            ? (int) $request->query('days')
            : 30;

        $since = now()->subDays($days - 1)->startOfDay();

        // One SBO can be singled out; without it the numbers are the whole
        // account, which is what an owner with one listing wants anyway.
        $selected = $request->filled('sbo')
            ? $sbos->firstWhere('id', (int) $request->query('sbo'))
            : null;

        $scope = $selected ? collect([$selected->id]) : $sboIds;

        return Inertia::render('account/AnalyticsView', [
            'sbos' => $sbos->map(fn (Sbo $s): array => [
                'id' => $s->id,
                'name' => $s->name,
            ])->values(),
            'selectedSboId' => $selected?->id,
            'days' => $days,
            'ranges' => array_map(
                fn (int $d): array => ['value' => $d, 'label' => "Last {$d} days"],
                self::RANGES,
            ),
            'totals' => $this->totals($scope, $since, $user->id),
            'series' => $this->series($scope, $since, $days),
            'listings' => $this->perListing($sbos, $since),
            /*
             * Stated rather than drawn as a zero: the registry records no
             * outbound click on the Visit Website link, so a "clicks" tile
             * would be a number that is always nought.
             */
            'notTracked' => ['Website clicks', 'Search impressions'],
        ]);
    }

    /**
     * @param  Collection<int, int>  $sboIds
     * @return array<int, array<string, mixed>>
     */
    private function totals(Collection $sboIds, CarbonInterface $since, int|string $userId): array
    {
        if ($sboIds->isEmpty()) {
            return [];
        }

        $views = SboView::whereIn('sbo_id', $sboIds)->where('viewed_at', '>=', $since)->count();

        // A lead is someone asking to connect with you — not one you sent.
        $requests = SboConnection::whereIn('recipient_sbo_id', $sboIds)
            ->where('created_at', '>=', $since)
            ->count();

        $accepted = SboConnection::where('status', SboConnection::STATUS_ACCEPTED)
            ->where(fn ($q) => $q->whereIn('recipient_sbo_id', $sboIds)->orWhereIn('requester_sbo_id', $sboIds))
            ->where('updated_at', '>=', $since)
            ->count();

        $rfqs = PurchaseOrder::whereIn('sbo_id', $sboIds)->where('created_at', '>=', $since)->count();

        $bidsReceived = PurchaseOrderBid::whereIn(
            'purchase_order_id',
            PurchaseOrder::whereIn('sbo_id', $sboIds)->select('id'),
        )->where('submitted_at', '>=', $since)->count();

        $bidsSubmitted = PurchaseOrderBid::whereIn('bidder_sbo_id', $sboIds)
            ->where('submitted_at', '>=', $since)
            ->count();

        return [
            ['key' => 'views', 'label' => __('analytics.metric.views'), 'value' => $views],
            ['key' => 'requests', 'label' => __('analytics.metric.requests'), 'value' => $requests],
            ['key' => 'accepted', 'label' => __('analytics.metric.accepted'), 'value' => $accepted],
            ['key' => 'rfqs', 'label' => __('analytics.metric.rfqs'), 'value' => $rfqs],
            ['key' => 'bidsReceived', 'label' => __('analytics.metric.bids_received'), 'value' => $bidsReceived],
            ['key' => 'bidsSubmitted', 'label' => __('analytics.metric.bids_submitted'), 'value' => $bidsSubmitted],
        ];
    }

    /**
     * Views per day across the window.
     *
     * Every day in the range is returned, including the ones with no views —
     * a chart that skips empty days misrepresents a quiet week as a busy one.
     *
     * @param  Collection<int, int>  $sboIds
     * @return array<int, array<string, mixed>>
     */
    private function series(Collection $sboIds, CarbonInterface $since, int $days): array
    {
        $counts = $sboIds->isEmpty()
            ? collect()
            : SboView::query()
                ->selectRaw('date(viewed_at) as day, count(*) as total')
                ->whereIn('sbo_id', $sboIds)
                ->where('viewed_at', '>=', $since)
                ->groupBy('day')
                ->pluck('total', 'day');

        return collect(range(0, $days - 1))
            ->map(function (int $offset) use ($since, $counts): array {
                $date = $since->addDays($offset);

                return [
                    'date' => $date->toDateString(),
                    'label' => $date->format('M j'),
                    'views' => (int) ($counts[$date->toDateString()] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * Per listing, so an owner with several can see which one is working.
     *
     * @param  Collection<int, Sbo>  $sbos
     * @return array<int, array<string, mixed>>
     */
    private function perListing(Collection $sbos, CarbonInterface $since): array
    {
        if ($sbos->isEmpty()) {
            return [];
        }

        $ids = $sbos->pluck('id');

        $views = SboView::query()
            ->selectRaw('sbo_id, count(*) as total')
            ->whereIn('sbo_id', $ids)
            ->where('viewed_at', '>=', $since)
            ->groupBy('sbo_id')
            ->pluck('total', 'sbo_id');

        $requests = SboConnection::query()
            ->selectRaw('recipient_sbo_id, count(*) as total')
            ->whereIn('recipient_sbo_id', $ids)
            ->where('created_at', '>=', $since)
            ->groupBy('recipient_sbo_id')
            ->pluck('total', 'recipient_sbo_id');

        return $sbos->map(fn (Sbo $s): array => [
            'id' => $s->id,
            'name' => $s->name,
            'slug' => $s->slug,
            'verified' => 'approved' === $s->verification_status,
            'views' => (int) ($views[$s->id] ?? 0),
            'requests' => (int) ($requests[$s->id] ?? 0),
        ])->values()->all();
    }
}
