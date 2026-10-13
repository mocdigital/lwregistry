<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The listing the registry itself speaks as.
 *
 * A member's "Message admin" and an administrator's reply are two ends of one
 * conversation, and the chat is SBO-to-SBO throughout. So the registry gets an
 * SBO to be. Every administrator posts as this one listing rather than as
 * themselves: a member is talking to the registry, not to whichever member of
 * staff happened to pick the thread up, and staff change.
 */
final class RegistrySbo
{
    /**
     * Never `approved`.
     *
     * The directory, the categories page and the home page's featured row all
     * filter on that status, so leaving it off keeps this listing out of every
     * public list without a single query having to know the flag exists.
     */
    private const STATUS = 'internal';

    /**
     * Whether this schema knows about the registry at all.
     *
     * `2026_08_06_100000_backfill_industry_group_chats` calls into
     * IndustryGroupChat, which now wants the registry — but it runs before the
     * column adding it exists. A migration replays history, so the code it
     * calls has to be able to say "not yet" rather than fail on a schema that
     * was correct when that migration was written. The later adoption
     * migration puts every room right afterwards.
     */
    public static function available(): bool
    {
        return Schema::hasColumn('sbos', 'is_registry');
    }

    public static function current(): ?Sbo
    {
        if ( ! self::available()) {
            return null;
        }

        return Sbo::query()->where('is_registry', true)->first();
    }

    /** The registry's listing when the schema allows one, otherwise null. */
    public static function ensureIfPossible(): ?Sbo
    {
        return self::available() ? self::ensure() : null;
    }

    /** The registry's listing, created on first use. */
    public static function ensure(): Sbo
    {
        $existing = self::current();

        if ($existing instanceof Sbo) {
            return $existing;
        }

        $name = (string) config('registry.support_name');

        return Sbo::create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(5)),
            /*
             * Owned by nobody. `created_by` is not nullable, so it points at
             * the first administrator available; it is not used to decide who
             * may read the thread, which goes through the participants pivot
             * like every other conversation.
             */
            'created_by' => AdminRecipients::users()->first()?->id,
            'verification_status' => self::STATUS,
            'status' => 'active',
            'is_active' => false,
            'is_registry' => true,
        ]);
    }

    public static function is(?Sbo $sbo): bool
    {
        return self::available() && (bool) $sbo?->is_registry;
    }
}
