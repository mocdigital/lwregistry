<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AdEvent;
use App\Models\AdPlacement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class AdService
{
    /**
     * Resolve the ad to show for a slot. Highest priority active ad wins;
     * ties rotate randomly so multiple campaigns share exposure.
     */
    public static function forSlot(string $slot): ?AdPlacement
    {
        $ads = AdPlacement::query()
            ->active()
            ->forSlot($slot)
            ->with('media')
            ->get();

        if ($ads->isEmpty()) {
            return null;
        }

        return self::pickFrom($ads);
    }

    /**
     * The live ad for every configured slot, shaped for the SPA to render.
     *
     * One query for all slots, so the shared prop costs the same whatever the
     * page. A slot with no live ad — or one whose creative is missing from
     * disk — is null, and that null is what makes the front-end component
     * render nothing at all rather than a placeholder box.
     *
     * Impressions are NOT recorded here: this runs on every request, for every
     * slot, whether or not the page even shows it. The client beacons a view
     * (see recordImpression + the ads.seen route) only when a real ad actually
     * renders.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public static function sharedSlots(): array
    {
        $slots = array_keys(config('ads.slots', []));

        $live = AdPlacement::query()
            ->active()
            ->whereIn('slot', $slots)
            ->with('media')
            ->get()
            ->groupBy('slot');

        $out = [];

        foreach ($slots as $slot) {
            $group = $live->get($slot);
            $out[$slot] = $group && $group->isNotEmpty()
                ? self::payload(self::pickFrom($group))
                : null;
        }

        return $out;
    }

    /**
     * @param  Collection<int, AdPlacement>  $ads
     */
    private static function pickFrom(Collection $ads): AdPlacement
    {
        $topPriority = $ads->max('priority');

        return $ads->where('priority', $topPriority)->random();
    }

    /**
     * The render payload for one ad, or null when it has no usable creative.
     *
     * @return array<string, mixed>|null
     */
    private static function payload(AdPlacement $ad): ?array
    {
        $image = $ad->imageUrl();

        if (null === $image) {
            return null;
        }

        $config = $ad->slotConfig() ?? [];
        $mobile = $config['mobile'] ?? null;

        return [
            'id' => $ad->id,
            'title' => $ad->title,
            'image' => $image,
            'mobileImage' => $ad->mobileImageUrl(),
            'clickUrl' => route('ads.click', $ad),
            'seenUrl' => route('ads.seen', $ad),
            'width' => (int) ($config['width'] ?? 0),
            'height' => (int) ($config['height'] ?? 0),
            'mobileWidth' => is_array($mobile) ? (int) ($mobile[0] ?? 0) : null,
            'mobileHeight' => is_array($mobile) ? (int) ($mobile[1] ?? 0) : null,
        ];
    }

    /**
     * Record an impression, throttled per session to avoid inflation.
     */
    public static function recordImpression(AdPlacement $ad): void
    {
        $minutes = (int) config('ads.impression_throttle_minutes', 30);
        $key = 'ad_imp_' . $ad->id . '_' . session()->getId();

        if (Cache::has($key)) {
            return;
        }

        Cache::put($key, true, now()->addMinutes($minutes));

        self::logEvent($ad, 'impression');
        $ad->increment('impressions');
    }

    public static function recordClick(AdPlacement $ad): void
    {
        self::logEvent($ad, 'click');
        $ad->increment('clicks');
    }

    private static function logEvent(AdPlacement $ad, string $type): void
    {
        AdEvent::create([
            'ad_placement_id' => $ad->id,
            'type' => $type,
            'ip_hash' => hash('sha256', (string) request()->ip()),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }
}
