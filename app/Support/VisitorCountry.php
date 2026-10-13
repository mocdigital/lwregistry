<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use MaxMind\Db\Reader;
use Throwable;

/**
 * The country a request comes from, by IP address.
 *
 * Read from the DB-IP country database on disk (config/locales.php `geoip`),
 * so it costs no outside call per visitor. One lookup per address per day via
 * the cache; the file is opened once per process.
 *
 * Only ever a hint — it decides the interface language for a first-time
 * visitor and nothing else. Private and reserved addresses, a missing database
 * and any reader failure all answer null, which the caller treats as "no idea".
 */
final class VisitorCountry
{
    private static ?Reader $reader = null;

    /** @var string|null A country forced by tests; '' forces "unknown". */
    private static ?string $forced = null;

    /** ISO 3166-1 alpha-2 code, upper-case, or null when unknown. */
    public static function of(Request $request): ?string
    {
        if (null !== self::$forced) {
            return '' === self::$forced ? null : self::$forced;
        }

        $ip = (string) $request->ip();

        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $path = (string) config('locales.geoip.database');

        if ( ! is_file($path)) {
            return null;
        }

        $code = Cache::remember(
            'visitor-country:' . $ip,
            now()->addDay(),
            fn (): string => self::lookup($path, $ip),
        );

        return '' === $code ? null : $code;
    }

    /**
     * Pin the answer for a test, bypassing the database. Null restores the
     * real lookup; an empty string stands for "unknown".
     */
    public static function force(?string $code): void
    {
        self::$forced = $code;
    }

    private static function lookup(string $path, string $ip): string
    {
        try {
            self::$reader ??= new Reader($path);

            $record = self::$reader->get($ip);

            return mb_strtoupper((string) ($record['country']['iso_code'] ?? ''));
        } catch (Throwable $e) {
            report($e);

            return '';
        }
    }
}
