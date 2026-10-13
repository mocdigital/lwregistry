<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SboConnection;
use Illuminate\Support\Collection;

/**
 * "Where do I stand with this business?" — for a whole page of them at once.
 *
 * The directory card and the network inbox's people search both need the same
 * answer to decide whether to offer Connect, Pending or Message, so the lookup
 * lives here rather than in each controller.
 *
 * One query for the page, not one per row.
 */
final class SboConnectionStates
{
    public const SELF = 'self';

    public const NONE = 'none';

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    /**
     * Connection status keyed by the *other* SBO's id, from the viewer's point
     * of view.
     *
     * Only `pending` and `accepted` are reported. A declined request reads as
     * `none` — you should be able to ask again, and a permanent dead end is
     * what `blocked` is for.
     *
     * @param  Collection<int, int>  $mine
     * @param  Collection<int, int>  $theirs
     * @return array<int, string>
     */
    public static function between(Collection $mine, Collection $theirs): array
    {
        if ($mine->isEmpty() || $theirs->isEmpty()) {
            return [];
        }

        return array_map(
            fn (array $row): string => $row['status'],
            self::rowsBetween($mine, $theirs),
        );
    }

    /**
     * The same lookup, keeping the connection's id.
     *
     * Disconnecting needs the row, not just the state — the business profile's
     * Disconnect button has to know which connection to delete, and re-querying
     * per page view to find out would undo the point of the single query.
     *
     * @param  Collection<int, int>  $mine
     * @param  Collection<int, int>  $theirs
     * @return array<int, array{status: string, id: int}>
     */
    public static function rowsBetween(Collection $mine, Collection $theirs): array
    {
        if ($mine->isEmpty() || $theirs->isEmpty()) {
            return [];
        }

        return SboConnection::query()
            ->whereIn('status', [SboConnection::STATUS_PENDING, SboConnection::STATUS_ACCEPTED])
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('requester_sbo_id', $mine)->whereIn('recipient_sbo_id', $theirs))
                ->orWhere(fn ($w) => $w->whereIn('requester_sbo_id', $theirs)->whereIn('recipient_sbo_id', $mine)))
            ->get()
            ->mapWithKeys(fn (SboConnection $c): array => [
                ($mine->contains($c->requester_sbo_id) ? $c->recipient_sbo_id : $c->requester_sbo_id) => [
                    'status' => $c->status,
                    'id' => (int) $c->id,
                ],
            ])
            ->all();
    }

    /**
     * The state for one SBO, including "that is my own listing".
     *
     * @param  Collection<int, int>  $mine
     * @param  array<int, string>  $states  from between()
     */
    public static function forSbo(Collection $mine, array $states, int $sboId): string
    {
        if ($mine->contains($sboId)) {
            return self::SELF;
        }

        return $states[$sboId] ?? self::NONE;
    }
}
