<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Country;
use Illuminate\Support\Facades\Cache;

/**
 * Country name to ISO code.
 *
 * Listings store the country as a name, not a code, so localising one means
 * getting back to the code ICU knows it by. The whole table is 250 rows and
 * never changes within a request, so it is loaded once and cached for a day
 * rather than queried per listing on a directory page showing thirty.
 */
final class Countries
{
    private const CACHE_KEY = 'countries.name_to_iso2';

    /** @var array<string, string>|null */
    private static ?array $memo = null;

    public static function codeFor(?string $name): ?string
    {
        if (null === $name || '' === mb_trim($name)) {
            return null;
        }

        return self::map()[mb_strtolower(mb_trim($name))] ?? null;
    }

    /** Forget the cached table — for tests and for the seeder. */
    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string>
     */
    private static function map(): array
    {
        if (null !== self::$memo) {
            return self::$memo;
        }

        return self::$memo = Cache::remember(
            self::CACHE_KEY,
            now()->addDay(),
            fn (): array => Country::query()
                ->whereNotNull('iso2')
                ->pluck('iso2', 'name')
                ->mapWithKeys(fn (string $iso2, string $name): array => [
                    mb_strtolower(mb_trim($name)) => mb_strtoupper($iso2),
                ])
                ->all(),
        );
    }
}
