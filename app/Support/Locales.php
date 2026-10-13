<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Which language to serve, and the list of languages on offer.
 *
 * Resolution order, most specific first:
 *
 *   1. the signed-in member's saved choice   — they told us, once, deliberately
 *   2. the session                            — a guest who used the selector
 *   3. Accept-Language, when it asks for
 *      French or Spanish                      — the browser's own preference
 *   4. the visitor's country                  — where they are (VisitorCountry)
 *   5. Accept-Language asking for English,
 *      then the app default                   — English
 *
 * A signed-in member's saved choice outranks their browser deliberately: a
 * French speaker on a borrowed English-configured laptop should still get
 * French, and someone who explicitly picked English should not be flipped back
 * to French by a header they never set.
 *
 * The country step sits BELOW a browser asking for French or Spanish but ABOVE
 * one asking for English. Nearly every browser lists English, most often as
 * the factory setting rather than a choice, so treating it as a preference
 * would mean nobody in France ever saw French. A browser asking for French or
 * Spanish, on the other hand, is a real signal and keeps winning.
 */
final class Locales
{
    public const SESSION_KEY = 'locale';

    /**
     * @return array<string, array{name: string, native: string, flag: string}>
     */
    public static function supported(): array
    {
        return config('locales.supported', []);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::supported());
    }

    public static function isSupported(?string $locale): bool
    {
        return null !== $locale && array_key_exists($locale, self::supported());
    }

    public static function fallback(): string
    {
        $default = (string) config('app.locale', 'en');

        return self::isSupported($default) ? $default : 'en';
    }

    /** The list the header selector renders. */
    public static function forSelector(): array
    {
        return collect(self::supported())
            ->map(fn (array $meta, string $code): array => [
                'code' => $code,
                'name' => $meta['name'],
                'native' => $meta['native'],
                'flag' => $meta['flag'],
            ])
            ->values()
            ->all();
    }

    public static function resolve(Request $request, ?User $user): string
    {
        if ($user instanceof User) {
            // Guarded read: `locale` is newer than plenty of the code that
            // builds User instances, and strict attribute mode throws on a
            // bare read when the column was not selected.
            $saved = array_key_exists('locale', $user->getAttributes()) ? $user->locale : null;

            if (self::isSupported($saved)) {
                return (string) $saved;
            }
        }

        $session = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (self::isSupported($session)) {
            return (string) $session;
        }

        $browser = self::fromAcceptLanguage($request);

        if (null !== $browser && $browser !== self::fallback()) {
            return $browser;
        }

        return self::fromCountry($request) ?? $browser ?? self::fallback();
    }

    /**
     * The language of the country the request comes from, when it is one we
     * speak (config/locales.php `countries`).
     */
    private static function fromCountry(Request $request): ?string
    {
        $country = VisitorCountry::of($request);

        if (null === $country) {
            return null;
        }

        foreach ((array) config('locales.countries', []) as $locale => $codes) {
            if (in_array($country, (array) $codes, true) && self::isSupported((string) $locale)) {
                return (string) $locale;
            }
        }

        return null;
    }

    /**
     * The best supported match from the browser's Accept-Language header.
     *
     * Matches on the primary subtag, so `fr-CA` and `es-419` land on `fr` and
     * `es` rather than falling through to English — a Quebecois visitor asking
     * for French should get French.
     */
    private static function fromAcceptLanguage(Request $request): ?string
    {
        $header = (string) $request->header('Accept-Language', '');

        if ('' === mb_trim($header)) {
            return null;
        }

        foreach (explode(',', $header) as $part) {
            // "fr-CA;q=0.9" -> "fr"
            $tag = mb_strtolower(mb_trim(explode(';', $part)[0]));
            $primary = explode('-', $tag)[0];

            if (self::isSupported($primary)) {
                return $primary;
            }
        }

        return null;
    }
}
