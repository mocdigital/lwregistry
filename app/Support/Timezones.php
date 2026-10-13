<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * The clock a member reads the registry on.
 *
 * Everything is STORED in UTC — `app.timezone` stays UTC and nothing here
 * changes that, because moving the storage timezone silently reinterprets every
 * row already written. This is the display side: a timestamp is converted on
 * its way to the screen and nowhere else.
 *
 * The Timezone field on /settings/profile was a text box with "East Africa Time
 * (GMT+3)" typed into it, saving nothing — so a member in Lagos read every
 * deadline, message time and posting stamp in UTC while being told it was
 * Nairobi.
 */
final class Timezones
{
    /**
     * The zones offered, grouped the way somebody looks for their own.
     *
     * Not all 400-odd IANA identifiers: a list nobody can scan is worse than a
     * short one that covers the registry. Every zone the members actually trade
     * in is here, and `Timezones::isValid()` accepts any IANA name anyway, so a
     * saved value from elsewhere is never rejected.
     *
     * @return array<string, list<string>>
     */
    public static function grouped(): array
    {
        return [
            'Africa' => [
                'Africa/Lagos', 'Africa/Accra', 'Africa/Abidjan', 'Africa/Nairobi',
                'Africa/Kampala', 'Africa/Dar_es_Salaam', 'Africa/Johannesburg',
                'Africa/Harare', 'Africa/Lusaka', 'Africa/Kinshasa', 'Africa/Douala',
                'Africa/Cairo', 'Africa/Casablanca', 'Africa/Addis_Ababa',
            ],
            'Europe' => [
                'Europe/London', 'Europe/Dublin', 'Europe/Lisbon', 'Europe/Madrid',
                'Europe/Paris', 'Europe/Brussels', 'Europe/Amsterdam', 'Europe/Berlin',
                'Europe/Rome', 'Europe/Zurich', 'Europe/Stockholm', 'Europe/Warsaw',
                'Europe/Athens', 'Europe/Istanbul', 'Europe/Moscow',
            ],
            'Americas' => [
                'America/New_York', 'America/Toronto', 'America/Chicago', 'America/Denver',
                'America/Phoenix', 'America/Los_Angeles', 'America/Vancouver',
                'America/Mexico_City', 'America/Bogota', 'America/Lima',
                'America/Sao_Paulo', 'America/Argentina/Buenos_Aires',
            ],
            'Asia & Pacific' => [
                'Asia/Dubai', 'Asia/Riyadh', 'Asia/Karachi', 'Asia/Kolkata',
                'Asia/Dhaka', 'Asia/Bangkok', 'Asia/Singapore', 'Asia/Kuala_Lumpur',
                'Asia/Manila', 'Asia/Hong_Kong', 'Asia/Shanghai', 'Asia/Seoul',
                'Asia/Tokyo', 'Australia/Perth', 'Australia/Sydney', 'Pacific/Auckland',
            ],
            'Other' => ['UTC'],
        ];
    }

    /**
     * The list the select renders: identifier, a readable name, and the offset
     * as it stands today — "Africa/Lagos" means nothing to most people, and an
     * offset printed without the zone goes wrong twice a year.
     *
     * @return list<array{group: string, value: string, label: string}>
     */
    public static function options(): array
    {
        $now = Carbon::now('UTC');
        $options = [];

        foreach (self::grouped() as $group => $zones) {
            foreach ($zones as $zone) {
                $options[] = [
                    'group' => $group,
                    'value' => $zone,
                    'label' => str_replace(['_', '/'], [' ', ' — '], $zone)
                        . ' (' . $now->copy()->setTimezone($zone)->format('P') . ')',
                ];
            }
        }

        return $options;
    }

    public static function isValid(?string $zone): bool
    {
        if (null === $zone || '' === mb_trim($zone)) {
            return false;
        }

        return in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    /** The app's own zone, which is what an unset preference means. */
    public static function fallback(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /** The zone to display in for a given member, or the fallback. */
    public static function for(?User $user): string
    {
        if ( ! $user instanceof User) {
            return self::fallback();
        }

        $zone = UserPreferences::for($user)['regional']['timezone'] ?? null;

        return self::isValid($zone) ? (string) $zone : self::fallback();
    }

    /** The zone for whoever is making the current request. */
    public static function current(): string
    {
        return self::for(auth()->user());
    }

    /**
     * Format a stored (UTC) timestamp on the reader's clock.
     *
     * Null in, null out: every call site here is a nullable column, and a
     * caller that has to null-check before calling is a caller that will forget.
     */
    public static function format(?DateTimeInterface $at, string $format, ?string $zone = null): ?string
    {
        if (null === $at) {
            return null;
        }

        return Carbon::instance($at)->setTimezone($zone ?? self::current())->format($format);
    }
}
