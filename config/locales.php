<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Supported locales
|--------------------------------------------------------------------------
|
| The languages the interface is offered in. One place, because four things
| need the same list and must not disagree: the header selector, the validation
| rule on the switch endpoint, the Accept-Language negotiation for first-time
| visitors, and the target list offered for chat translation.
|
| `native` is what the selector shows — a French speaker looking for their
| language scans for "Français", not "French". `flag` is a region emoji rather
| than an image so it needs no asset pipeline and scales with the type.
|
| Adding a locale here is most of the work: create lang/{code}.json beside the
| others and it appears in the selector.
|
*/

return [
    'supported' => [
        'en' => ['name' => 'English', 'native' => 'English', 'flag' => '🇬🇧'],
        'fr' => ['name' => 'French', 'native' => 'Français', 'flag' => '🇫🇷'],
        'es' => ['name' => 'Spanish', 'native' => 'Español', 'flag' => '🇪🇸'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Language by country
    |--------------------------------------------------------------------------
    |
    | A first-time visitor whose browser does not ask for French or Spanish is
    | served by where they are: the country their IP address resolves to, looked
    | up in the DB-IP database below (see Locales::resolve for the order). ISO
    | 3166-1 alpha-2 codes, per locale.
    |
    | Countries where French or Spanish is the language of public life. Places
    | that are officially bilingual with another majority language (Canada,
    | Switzerland) are left out: a visitor there is better served by their
    | browser's language, which still wins when it is one we speak.
    |
    */
    'countries' => [
        'fr' => [
            'FR', 'BE', 'LU', 'MC', 'BJ', 'BF', 'BI', 'CM', 'CF', 'TD', 'KM', 'CG', 'CD',
            'CI', 'DJ', 'GA', 'GN', 'MG', 'ML', 'NE', 'RW', 'SN', 'SC', 'TG', 'HT',
            'GP', 'MQ', 'GF', 'RE', 'YT', 'NC', 'PF', 'PM', 'WF', 'BL', 'MF',
        ],
        'es' => [
            'ES', 'MX', 'AR', 'CO', 'CL', 'PE', 'VE', 'EC', 'GT', 'CU', 'BO', 'DO', 'HN',
            'PY', 'SV', 'NI', 'CR', 'PA', 'UY', 'PR', 'GQ',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | IP → country database
    |--------------------------------------------------------------------------
    |
    | DB-IP's free country-level database (CC BY 4.0 — the footer credits it).
    | Published monthly under a dated name; `geoip:update` fetches the current
    | month's file (the previous month's if it is not out yet) into `database`,
    | and the scheduler runs it monthly. No account or key is involved. A
    | missing file simply disables the country step.
    |
    */
    'geoip' => [
        'database' => storage_path('app/geoip/dbip-country-lite.mmdb'),
        'source' => 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz',
    ],
];
