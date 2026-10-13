<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Sbo;
use App\Models\SboConversation;

/**
 * The category-wide group chat.
 *
 * Every verified business belongs to the group for the category it registered
 * under — one room per category (all of them), not the broad industry sector
 * above it. It is not something anyone joins or is invited to, so there is no
 * join flow and no membership screen. The roster follows verification: a
 * business that is verified is in, one whose verification lapses is out.
 *
 * The group key lives in `sbo_conversations.industry` — the column and this
 * class keep their original names so the migrations that reference them still
 * run, but what they hold and match on now is the CATEGORY, not the industry.
 *
 * Membership is written to `sbo_conversation_participants` rather than derived
 * at read time, because the pivot is what carries `last_read_at` — unread
 * counts per member are the whole reason the inbox can say anything useful
 * about a room with hundreds of people in it.
 */
final class IndustryGroupChat
{
    /**
     * Bring one SBO's membership in line with its current state.
     *
     * Called on every save, so a business that is approved, has its category
     * corrected, or is later suspended lands in the right room without anyone
     * running anything.
     */
    public static function sync(Sbo $sbo): void
    {
        /*
         * The registry is in every room by adoption, not by category, and it
         * is not eligible by the network gate — so falling through here would
         * take the "not eligible, therefore in no room" branch below and
         * detach it from every group on its next save.
         */
        if (RegistrySbo::is($sbo)) {
            return;
        }

        $category = $sbo->category;

        // Left every category room it is in but should not be — which covers a
        // recategorised business as well as one that lost its verification.
        $stale = SboConversation::query()
            ->where('type', SboConversation::TYPE_INDUSTRY)
            ->when(
                self::eligible($sbo) && null !== $category,
                fn ($q) => $q->where('industry', '!=', $category),
            )
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            $sbo->conversations()->detach($stale->all());
        }

        if ( ! self::eligible($sbo) || null === $category) {
            return;
        }

        $conversation = self::for($category, $sbo);

        // syncWithoutDetaching, so an existing member keeps their last_read_at
        // and does not have every message turn unread again on a save.
        $sbo->conversations()->syncWithoutDetaching([$conversation->id]);
    }

    /**
     * The conversation for a category, created on first need.
     *
     * The registry owns every room and sits in every one of them. It used to
     * be whichever business happened to be verified first — an author in no
     * meaningful sense, and one that made "Message admin" point at an
     * arbitrary member while looking like it worked.
     *
     * `$creator` is accepted and ignored for the same reason: the caller is
     * whichever SBO triggered the sync, and it is not the owner of the room.
     */
    public static function for(string $category, ?Sbo $creator = null): SboConversation
    {
        // Null only while an old migration replays against a schema that
        // predates the registry; see RegistrySbo::available().
        $registry = RegistrySbo::ensureIfPossible();

        $existing = SboConversation::query()
            ->where('type', SboConversation::TYPE_INDUSTRY)
            ->where('industry', $category)
            ->first();

        if ($existing) {
            // Rooms made before the registry existed are adopted rather than
            // left ownerless; syncWithoutDetaching keeps its last_read_at.
            return null === $registry ? $existing : self::adopt($existing, $registry);
        }

        $conversation = SboConversation::create([
            'type' => SboConversation::TYPE_INDUSTRY,
            // The group key: the category name. The column is named `industry`
            // for history's sake; see the class docblock.
            'industry' => $category,
            // The fallback is only reached during an old replay, and the
            // adoption migration corrects it immediately afterwards.
            'created_by_sbo_id' => $registry?->id ?? self::anyMemberOf($category)?->id,
        ]);

        return null === $registry ? $conversation : self::adopt($conversation, $registry);
    }

    /**
     * Put every currently verified business into its category's room.
     *
     * For the backfill, and for a registry whose SBOs were verified before
     * these rooms existed.
     */
    public static function backfillAll(): int
    {
        $count = 0;

        // Rooms first, so one that has no eligible members left is still
        // adopted rather than staying ownerless.
        $registry = RegistrySbo::ensureIfPossible();

        if (null !== $registry) {
            SboConversation::query()
                ->where('type', SboConversation::TYPE_INDUSTRY)
                ->each(fn (SboConversation $room) => self::adopt($room, $registry));
        }

        Sbo::query()
            ->whereNotNull('category')
            ->chunkById(200, function ($sbos) use (&$count): void {
                foreach ($sbos as $sbo) {
                    self::sync($sbo);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Create the room for every category on the platform, so all groups are
     * present (with the registry in them) before anyone verifies. Run whenever
     * the category list may have grown.
     *
     * @return int the number of categories
     */
    public static function ensureEveryCategory(): int
    {
        /*
         * An empty category's room has no member to author it, so it needs the
         * registry as owner. Without one — an old migration replaying before
         * the registry exists — skip: those rooms appear the moment the
         * registry and the category first coincide.
         */
        if (null === RegistrySbo::ensureIfPossible()) {
            return 0;
        }

        $names = Category::query()->orderBy('name')->pluck('name');

        foreach ($names as $name) {
            self::for((string) $name);
        }

        return $names->count();
    }

    /** The label the inbox shows for a room. */
    public static function name(string $category): string
    {
        return $category . ' Group';
    }

    /**
     * Make the registry the room's owner and a member of it.
     *
     * Both, and not just the owner column: an owner who is not a participant
     * cannot read the room, is not notified of anything said in it, and does
     * not appear on the roster — which is to say, is not really the admin.
     */
    private static function adopt(SboConversation $conversation, Sbo $registry): SboConversation
    {
        if ($conversation->created_by_sbo_id !== $registry->id) {
            $conversation->forceFill(['created_by_sbo_id' => $registry->id])->save();
        }

        $conversation->participants()->syncWithoutDetaching([$registry->id]);

        return $conversation;
    }

    private static function anyMemberOf(string $category): ?Sbo
    {
        return Sbo::query()->where('category', $category)->first();
    }

    /**
     * Who belongs in a category room.
     *
     * The same definition the network gate uses — approved, documents
     * compliant, fee settled — so a business that cannot use the network is not
     * sitting in a room it cannot post to.
     */
    private static function eligible(Sbo $sbo): bool
    {
        return app(SboNetworkAccess::class)->sboCanUseNetwork($sbo);
    }
}
