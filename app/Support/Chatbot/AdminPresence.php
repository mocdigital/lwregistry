<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Which administrators are online to take a conversation.
 *
 * A heartbeat rather than a socket: the admin console pings while it is open,
 * and "online" means it pinged recently. Nothing has to be cleaned up when a
 * tab is simply closed — the row just goes stale and stops counting — and it
 * needs no websocket server running, which this deployment does not have.
 */
final class AdminPresence
{
    /** How long a heartbeat counts for. A console pings well inside this. */
    private const WINDOW_SECONDS = 45;

    /** Record that an administrator's console is open right now. */
    public static function heartbeat(int $userId): void
    {
        DB::table('chatbot_admin_presence')->updateOrInsert(
            ['user_id' => $userId],
            ['last_seen_at' => now()],
        );
    }

    /** Is anybody available to take a handoff? */
    public static function anyOnline(): bool
    {
        return DB::table('chatbot_admin_presence')
            ->where('last_seen_at', '>=', self::threshold())
            ->exists();
    }

    /**
     * The administrators currently online.
     *
     * @return array<int, int> user ids
     */
    public static function onlineIds(): array
    {
        return DB::table('chatbot_admin_presence')
            ->where('last_seen_at', '>=', self::threshold())
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private static function threshold(): CarbonInterface
    {
        return now()->subSeconds(self::WINDOW_SECONDS);
    }
}
