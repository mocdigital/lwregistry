<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use App\Models\SboAddress;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Turns a business's postal address into a point on a map.
 *
 * Nominatim — OpenStreetMap's own geocoder — because it needs no API key and no
 * account, which is the same reason the map itself is OpenStreetMap rather than
 * Google. Their usage policy asks for an identifying User-Agent and no more
 * than one request a second; a result is written back to the SBO and never
 * looked up again, so a business is geocoded once in its lifetime rather than
 * once per page view.
 *
 * Every failure path is silent and cached. A profile must render whether or not
 * a third-party service is reachable, so a lookup that times out leaves the map
 * absent and the address intact rather than turning the page into an error.
 */
final class Geocoder
{
    /** Nominatim asks callers not to hold the connection open. */
    private const TIMEOUT_SECONDS = 4;

    /**
     * How long before a failed lookup is retried.
     *
     * Not never: an address that fails today may resolve once OpenStreetMap
     * gains the street. Not immediately either, or every view of an
     * unresolvable address becomes an outbound request.
     */
    private const RETRY_AFTER_DAYS = 30;

    /**
     * Ensure `$sbo` has coordinates, looking them up if needed.
     *
     * Returns the SBO either way — the caller renders a map if the columns are
     * populated and an address-only block if they are not.
     */
    /**
     * Resolve a branch address, caching the point on the row itself.
     *
     * Same rule as ensure(): skip when the stored point still matches the
     * address that produced it, retry a failure after RETRY_AFTER_DAYS.
     */
    public static function ensureAddress(SboAddress $address): SboAddress
    {
        if ( ! array_key_exists('geocoded_at', $address->getAttributes())) {
            $address = $address->fresh() ?? $address;
        }

        $line = $address->line();

        if ('' === $line) {
            return $address;
        }

        $attrs = $address->getAttributes();
        $resolvedFor = $attrs['geocoded_address'] ?? null;

        if ($resolvedFor === $line) {
            // Already resolved for this exact address, or attempted recently
            // enough that asking Nominatim again would be rude.
            if (null !== ($attrs['latitude'] ?? null)) {
                return $address;
            }

            $attemptedAt = $address->geocoded_at;

            if (null !== $attemptedAt && $attemptedAt->gt(now()->subDays(self::RETRY_AFTER_DAYS))) {
                return $address;
            }
        }

        $point = self::resolveLine(
            $line,
            mb_trim(implode(', ', array_filter([$address->city, $address->state, $address->country]))),
            mb_trim(implode(', ', array_filter([$address->city, $address->country]))),
        );

        $address->forceFill([
            'latitude' => $point['lat'] ?? null,
            'longitude' => $point['lon'] ?? null,
            'geocoded_at' => now(),
            'geocoded_address' => $line,
        ])->saveQuietly();

        return $address;
    }

    public static function ensure(Sbo $sbo): Sbo
    {
        /*
         * A model straight out of `create()` holds only the attributes that
         * were inserted, so the coordinate columns are absent rather than null
         * — and strict attribute mode throws on reading them. Reloading is the
         * difference between working for a row that was just written and only
         * working for one that was read back.
         */
        if ( ! array_key_exists('geocoded_at', $sbo->getAttributes())) {
            $sbo = $sbo->fresh() ?? $sbo;
        }

        if ( ! self::shouldLookUp($sbo)) {
            return $sbo;
        }

        $address = self::addressFor($sbo);

        if ('' === $address) {
            return $sbo;
        }

        $point = self::resolve($sbo, $address);

        $sbo->forceFill([
            'latitude' => $point['lat'] ?? null,
            'longitude' => $point['lon'] ?? null,
            'geocoded_at' => now(),
            'geocoded_address' => $address,
        ])->saveQuietly();

        return $sbo;
    }

    /** The full postal address, as one line. */
    public static function addressFor(Sbo $sbo): string
    {
        return mb_trim(implode(', ', array_filter([
            $sbo->address,
            $sbo->city,
            $sbo->state,
            $sbo->country,
        ])));
    }

    /**
     * Try the full address, then progressively coarser ones.
     *
     * A street that OpenStreetMap has never heard of — a new development, a
     * rural address, or simply a typo — should not cost the profile its map
     * entirely. Falling back to the city puts the pin in the right place at a
     * coarser zoom, which is far more use than a grey box, and the exact
     * address is printed beside it either way.
     *
     * @return array{lat?: float, lon?: float}
     */
    private static function resolve(Sbo $sbo, string $full): array
    {
        return self::resolveLine(
            $full,
            // Drop the street.
            mb_trim(implode(', ', array_filter([$sbo->city, $sbo->state, $sbo->country]))),
            // Drop the region too.
            mb_trim(implode(', ', array_filter([$sbo->city, $sbo->country]))),
        );
    }

    /**
     * The candidates in order, first hit wins.
     *
     * @return array{lat?: float, lon?: float}
     */
    private static function resolveLine(string ...$candidates): array
    {
        foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
            $point = self::lookUp($candidate);

            if ([] !== $point) {
                return $point;
            }
        }

        return [];
    }

    private static function shouldLookUp(Sbo $sbo): bool
    {
        $attrs = $sbo->getAttributes();

        // Still absent after the reload in ensure(): a partial select, so
        // there is nothing safe to read and nothing to update.
        if ( ! array_key_exists('geocoded_at', $attrs)) {
            return false;
        }

        $address = self::addressFor($sbo);

        // The address moved, so whatever we resolved before is the wrong place.
        if (($attrs['geocoded_address'] ?? null) !== $address) {
            return true;
        }

        if (null !== ($attrs['latitude'] ?? null)) {
            return false;
        }

        $attemptedAt = $sbo->geocoded_at;

        return null === $attemptedAt
            || $attemptedAt->lt(now()->subDays(self::RETRY_AFTER_DAYS));
    }

    /**
     * @return array{lat?: float, lon?: float}
     */
    private static function lookUp(string $address): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                /*
                 * Nominatim's policy requires a User-Agent that identifies the
                 * application and a way to reach whoever runs it. An anonymous
                 * or spoofed agent gets the caller blocked.
                 */
                ->withHeaders([
                    'User-Agent' => config('app.name', 'LW Registry')
                        . ' (' . config('app.url', 'https://example.test') . ')',
                ])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $address,
                    'format' => 'jsonv2',
                    'limit' => 1,
                ]);

            if ($response->failed()) {
                return [];
            }

            $hit = $response->json('0');

            if ( ! is_array($hit) || ! isset($hit['lat'], $hit['lon'])) {
                return [];
            }

            return ['lat' => (float) $hit['lat'], 'lon' => (float) $hit['lon']];
        } catch (ConnectionException) {
            // A timeout or unreachable host from a free, rate-limited service is
            // expected and already handled (the map is simply absent). Logging
            // it as an error only buries the failures that matter.
            return [];
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
