<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Locale;
use Transliterator;

/**
 * Translating the labels on data, without translating the data.
 *
 * A row in `categories` is called "Bookstores & Publishers" and it stays called
 * that: it is a stored value, it is what `?category=` carries, it is what a
 * filter matches on, and rewriting it in French would break every one of those.
 * But the visitor reading the directory in French should not be shown an
 * English chip.
 *
 * So the value and its label are separated at the last possible moment. The
 * value travels as it always did; the label is looked up under `data.{group}.`
 * plus a slug of the English, and falls back to the English when no translation
 * exists. That fallback is the point — the registry gains categories, countries
 * and trades over time, and a new one appearing in English is a missing
 * translation rather than a blank chip or a dotted key.
 *
 * The frontend has the same function, `td()` in useI18n, keyed identically, for
 * the values it renders straight from a payload.
 */
final class DataLabels
{
    /** Business categories — `categories.name` and `sbos.category`. */
    public const CATEGORY = 'category';

    /** Sectors — `sbos.industry`, see SboIndustries. */
    public const INDUSTRY = 'industry';

    /** Verification, application, order and bid states. */
    public const STATUS = 'status';

    /** Country names — localised through ICU, see country(). */
    public const COUNTRY = 'country';

    /**
     * The label for one value, or the value itself when it has no translation.
     */
    public static function get(string $group, ?string $value): ?string
    {
        if (null === $value || '' === mb_trim($value)) {
            return $value;
        }

        $key = self::key($group, $value);

        return Lang::has($key) ? __($key) : $value;
    }

    /**
     * A country name in the reader's language.
     *
     * Countries are the one group that needs no translation file. ICU already
     * ships every country name in every locale, and `countries.iso2` is the key
     * into it, so "South Africa" becomes "Sudáfrica" without anybody typing it.
     * A name with no matching row — free text on an older listing — is returned
     * unchanged.
     */
    public static function country(?string $name): ?string
    {
        if (null === $name || '' === mb_trim($name)) {
            return $name;
        }

        $code = Countries::codeFor($name);

        if (null === $code) {
            return $name;
        }

        $display = Locale::getDisplayRegion('-' . $code, app()->getLocale());

        // ICU echoes the code back when it knows nothing about the region.
        return '' === $display || $display === $code ? $name : $display;
    }

    /**
     * `data.country.*` for every country a listing trades in.
     *
     * Shared with the client so `td(value, 'country')` resolves in the browser
     * the same way `country()` does here. Scoped to the countries in use rather
     * than all 250, because a name the registry does not hold is a name no
     * screen can render.
     *
     * @return array<string, string>
     */
    public static function countryLabels(): array
    {
        $locale = app()->getLocale();

        return Cache::remember(
            'data.country_labels.' . $locale,
            now()->addHour(),
            fn (): array => Sbo::query()
                ->whereNotNull('country')
                ->where('country', '!=', '')
                ->distinct()
                ->pluck('country')
                ->mapWithKeys(fn (string $name): array => [
                    self::key(self::COUNTRY, $name) => (string) self::country($name),
                ])
                ->all(),
        );
    }

    /** The translation key a value is stored under. */
    public static function key(string $group, string $value): string
    {
        return 'data.' . $group . '.' . self::slug($value);
    }

    /**
     * `Restaurants & Cafés` -> `restaurants_cafes`.
     *
     * Accent-folded and punctuation-stripped so the same trade written two ways
     * — "Restaurants & Cafés" in the seed, "Restaurants & Cafes" in the footer
     * — resolves to one key instead of two, one of which would be missing.
     */
    public static function slug(string $value): string
    {
        $ascii = Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($value) ?? $value;
        $slug = mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $ascii));

        return mb_trim($slug, '_');
    }
}
