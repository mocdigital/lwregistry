<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\Timezones;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * `/notifications` — the full notification history.
 *
 * The route used to render the designed screen with seven hardcoded cards and
 * fixed counts ("All (23)", "5 Unread"). The `notifications` table and forty
 * notification classes writing to it already existed; nothing read them here.
 *
 * Category, icon, tint and the Action Required flag are derived on the model
 * (see App\Models\Notification), so this controller only queries, filters,
 * counts and groups.
 */
final class NotificationController extends Controller
{
    /** The seven pills in Filters_Row 253:8970, in the order the design draws them. */
    private const FILTERS = ['all', 'unread', 'action', 'applications', 'payments', 'network', 'system'];

    private const PER_PAGE = 15;

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $filter = in_array($request->query('filter'), self::FILTERS, true)
            ? (string) $request->query('filter')
            : 'all';

        $search = mb_trim((string) $request->query('search', ''));

        /*
         * Category and Action Required are derived from the payload, not stored
         * as columns, so they cannot be filtered in SQL. The full set is loaded
         * once, filtered in memory and paginated by hand.
         *
         * That is fine at this size — a user's own notifications — and it keeps
         * the classification in exactly one place. If the table grows past a
         * few thousand rows per user, the fix is a stored `category` column
         * written at send time, not a second copy of the mapping in a query.
         */
        $all = $user->notifications()->get();

        $counts = [
            'all' => $all->count(),
            'unread' => $all->filter(fn (Notification $n): bool => $n->isUnread())->count(),
            'action' => $all->filter(fn (Notification $n): bool => $n->isActionRequired())->count(),
            'applications' => $all->filter(fn (Notification $n): bool => Notification::CATEGORY_APPLICATIONS === $n->category())->count(),
            'payments' => $all->filter(fn (Notification $n): bool => Notification::CATEGORY_PAYMENTS === $n->category())->count(),
            'network' => $all->filter(fn (Notification $n): bool => Notification::CATEGORY_NETWORK === $n->category())->count(),
            'system' => $all->filter(fn (Notification $n): bool => Notification::CATEGORY_SYSTEM === $n->category())->count(),
        ];

        $matching = $all
            ->filter(fn (Notification $n): bool => $this->matchesFilter($n, $filter))
            ->filter(fn (Notification $n): bool => $this->matchesSearch($n, $search))
            ->values();

        $page = max(1, (int) $request->query('page', '1'));
        $lastPage = max(1, (int) ceil($matching->count() / self::PER_PAGE));
        $page = min($page, $lastPage);

        $rows = $matching->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();

        return Inertia::render('account/NotificationsView', [
            'user' => [
                'name' => mb_trim($user->firstname . ' ' . $user->lastname),
                'email' => $user->email,
                'initials' => mb_strtoupper(
                    mb_substr((string) $user->firstname, 0, 1) . mb_substr((string) $user->lastname, 0, 1)
                ),
                'photoUrl' => $user->profilePhotoUrl(),
            ],
            'filter' => $filter,
            'search' => $search,
            'counts' => $counts,
            'groups' => $this->group($rows),
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $matching->count(),
                'from' => $rows->isEmpty() ? null : (($page - 1) * self::PER_PAGE) + 1,
                'to' => $rows->isEmpty() ? null : (($page - 1) * self::PER_PAGE) + $rows->count(),
            ],
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->markAsRead();

        return back(fallback: route('notifications.index'))->with('status', 'notification-read');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back(fallback: route('notifications.index'))->with('status', 'notifications-read');
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->delete();

        return back(fallback: route('notifications.index'))->with('status', 'notification-deleted');
    }

    /**
     * Scoped to the signed-in user rather than resolved by route-model binding:
     * the id is a UUID a caller could otherwise guess at, and a 404 (not a 403)
     * is the right answer for someone else's notification.
     */
    private function find(Request $request, string $id): Notification
    {
        /** @var Notification|null $notification */
        $notification = $request->user()->notifications()->whereKey($id)->first();

        abort_if(null === $notification, 404);

        return $notification;
    }

    private function matchesFilter(Notification $notification, string $filter): bool
    {
        return match ($filter) {
            'all' => true,
            'unread' => $notification->isUnread(),
            'action' => $notification->isActionRequired(),
            default => $notification->category() === $filter,
        };
    }

    private function matchesSearch(Notification $notification, string $search): bool
    {
        if ('' === $search) {
            return true;
        }

        return str_contains(
            mb_strtolower($notification->title() . ' ' . $notification->body()),
            mb_strtolower($search),
        );
    }

    /**
     * The feed is drawn as dated sections ("Today — August 12, 2026",
     * "Yesterday", "Earlier This Week"). Rows arrive newest-first, so the
     * groups come out in the right order without a second sort.
     *
     * @param  Collection<int, Notification>  $rows
     * @return array<int, array{label: string, cards: array<int, array<string, mixed>>}>
     */
    private function group(Collection $rows): array
    {
        return $rows
            ->groupBy(fn (Notification $n): string => $this->groupLabel($n->created_at))
            ->map(fn (Collection $cards, string $label): array => [
                'label' => $label,
                'cards' => $cards->map(fn (Notification $n): array => $this->card($n))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** Typed against the interface: the app's date factory returns CarbonImmutable. */
    private function groupLabel(?CarbonInterface $at): string
    {
        if (null === $at) {
            return __('notifications.group.undated');
        }

        // On the reader's clock, or "Today" is decided in UTC and a member in
        // Lagos sees this morning filed under yesterday until 01:00.
        $at = $at->copy()->setTimezone(Timezones::current());

        return match (true) {
            $at->isToday() => __('notifications.group.today', ['date' => $at->format('F j, Y')]),
            $at->isYesterday() => __('notifications.group.yesterday'),
            $at->greaterThan(now()->subWeek()) => __('notifications.group.this_week'),
            $at->greaterThan(now()->subMonth()) => __('notifications.group.this_month'),
            default => $at->format('F Y'),
        };
    }

    /** @return array<string, mixed> */
    private function card(Notification $notification): array
    {
        $url = $notification->url();

        return [
            'id' => $notification->id,
            'title' => $notification->title(),
            'body' => $notification->body(),
            // The design writes relative times ("2 hours ago", "Yesterday at
            // 3:45 PM"); diffForHumans is the same idea and stays honest.
            'time' => $notification->created_at?->diffForHumans(),
            'icon' => $notification->icon(),
            'tint' => $notification->tint(),
            'unread' => $notification->isUnread(),
            'badge' => $notification->isActionRequired() ? 'Action Required' : null,
            'action' => $url ? [
                'label' => $notification->actionLabel(),
                'url' => $url,
            ] : null,
            /*
             * Only connection requests draw the Accept / Decline pair
             * (Extra_Action_Buttons 253:9039). The id is what those buttons
             * PATCH against.
             */
            'connectionId' => 'sbo_connection_requested' === $notification->slug()
                ? $notification->pendingConnectionId()
                : null,
        ];
    }
}
