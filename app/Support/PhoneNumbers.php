<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Country;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Local phone numbers into E.164.
 *
 * `User::$phone` is cast with `E164PhoneNumberCast` and no default region, so
 * assigning a number as people actually type it ("08031234567") threw "Missing
 * or invalid default region" — a 500 on the last step of registration, after
 * the account had been described in full.
 *
 * The region comes from the country the applicant selected rather than a
 * constant: the registry spans every country in the `countries` table, and the
 * wizard's +234 prefix was drawn for Nigeria only. `countries.iso2` is exactly
 * the region code libphonenumber wants.
 */
final class PhoneNumbers
{
    /**
     * Normalise to E.164, or null if it cannot be parsed as a number in that
     * region. Callers treat null as a validation failure rather than storing
     * something the cast will reject later.
     */
    public static function toE164(?string $number, ?string $region): ?string
    {
        if (null === $number || '' === mb_trim($number)) {
            return null;
        }

        // Already international — the region is then irrelevant.
        $region = str_starts_with(mb_trim($number), '+') ? null : $region;

        try {
            $parsed = PhoneNumberUtil::getInstance()->parse(mb_trim($number), $region);
        } catch (NumberParseException) {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        return $util->isValidNumber($parsed)
            ? $util->format($parsed, PhoneNumberFormat::E164)
            : null;
    }

    /** The ISO-3166 alpha-2 code for a country name, as the selects offer it. */
    public static function regionForCountry(?string $country): ?string
    {
        if (null === $country || '' === mb_trim($country)) {
            return null;
        }

        return Country::query()->where('name', mb_trim($country))->value('iso2');
    }

    /**
     * Dial codes keyed by country name, for the wizard's phone prefix.
     *
     * Sent to the client so the prefix follows the country the applicant picked
     * instead of always reading +234.
     *
     * @return array<string, string>
     */
    public static function dialCodesByCountry(): array
    {
        $util = PhoneNumberUtil::getInstance();

        return Country::query()
            ->whereNotNull('iso2')
            ->orderBy('name')
            ->pluck('iso2', 'name')
            ->map(fn (string $iso2): ?string => ($code = $util->getCountryCodeForRegion($iso2))
                ? '+' . $code
                : null)
            ->filter()
            ->all();
    }

    /**
     * ISO code keyed by country name, so the wizard can draw the right flag
     * beside the dial code.
     *
     * @return array<string, string>
     */
    public static function isoCodesByCountry(): array
    {
        return Country::query()
            ->whereNotNull('iso2')
            ->orderBy('name')
            ->pluck('iso2', 'name')
            ->map(fn (string $iso2): string => mb_strtoupper($iso2))
            ->all();
    }

    /**
     * The reverse: dial code to the country it should select.
     *
     * A dial code is not unique — +1 covers the United States, Canada and
     * twenty Caribbean nations, +7 covers Russia and Kazakhstan. Typing "+1"
     * has to resolve to ONE flag, so the country whose own code the number
     * plan is named for wins; everything else is first alphabetically, which
     * is at least stable.
     *
     * @return array<string, string>
     */
    public static function countriesByDialCode(): array
    {
        $primary = [
            '+1' => 'United States',
            '+7' => 'Russia',
            '+44' => 'United Kingdom',
            '+61' => 'Australia',
            '+212' => 'Morocco',
            '+262' => 'Réunion',
            '+590' => 'Guadeloupe',
            '+596' => 'Martinique',
            '+599' => 'Curaçao',
        ];

        $byCode = [];

        foreach (self::dialCodesByCountry() as $country => $code) {
            $byCode[$code] ??= $country;
        }

        foreach ($primary as $code => $country) {
            if (array_key_exists($code, $byCode)) {
                $byCode[$code] = $country;
            }
        }

        return $byCode;
    }
}
