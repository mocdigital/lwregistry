<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            // ISO 3166-1 alpha-2 — the resolution key for country-scoped config
            // (fees today, more later). Nullable so unmatched rows don't block.
            $table->string('iso2', 2)->nullable()->after('name')->index();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->dropColumn('iso2');
        });
    }

    /**
     * Backfill iso2 by matching the countries table's names (normalized) to the
     * dr5hn dataset's ISO2 codes. A handful of countries are spelled
     * differently between the two sources; those are bridged by $aliases below,
     * mirroring WorldStatesCitiesSeeder.
     */
    private function backfill(): void
    {
        $iso2ByNormalizedName = $this->iso2Map();

        // countries-table name (normalized) => dataset name (normalized), for
        // the countries the two sources spell differently.
        $aliases = [
            'swaziland' => 'eswatini',
            'guernseyandalderney' => 'guernsey',
            'cotedivoireivorycoast' => 'ivorycoast',
            'gambiathe' => 'thegambia',
            'easttimor' => 'timorleste',
        ];

        foreach (DB::table('countries')->select('id', 'name')->get() as $country) {
            $key = $this->normalize($country->name);
            $key = $aliases[$key] ?? $key;

            if (isset($iso2ByNormalizedName[$key])) {
                DB::table('countries')->where('id', $country->id)
                    ->update(['iso2' => $iso2ByNormalizedName[$key]]);
            }
        }
    }

    private function normalize(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(mb_trim($name)));
    }

    /**
     * Normalized dataset country name => ISO 3166-1 alpha-2 code.
     *
     * @return array<string, string>
     */
    private function iso2Map(): array
    {
        return [
            'afghanistan' => 'AF',
            'alandislands' => 'AX',
            'albania' => 'AL',
            'algeria' => 'DZ',
            'americansamoa' => 'AS',
            'andorra' => 'AD',
            'angola' => 'AO',
            'anguilla' => 'AI',
            'antarctica' => 'AQ',
            'antiguaandbarbuda' => 'AG',
            'argentina' => 'AR',
            'armenia' => 'AM',
            'aruba' => 'AW',
            'australia' => 'AU',
            'austria' => 'AT',
            'azerbaijan' => 'AZ',
            'bahrain' => 'BH',
            'bangladesh' => 'BD',
            'barbados' => 'BB',
            'belarus' => 'BY',
            'belgium' => 'BE',
            'belize' => 'BZ',
            'benin' => 'BJ',
            'bermuda' => 'BM',
            'bhutan' => 'BT',
            'bolivia' => 'BO',
            'bonairesinteustatiusandsaba' => 'BQ',
            'bosniaandherzegovina' => 'BA',
            'botswana' => 'BW',
            'bouvetisland' => 'BV',
            'brazil' => 'BR',
            'britishindianoceanterritory' => 'IO',
            'brunei' => 'BN',
            'bulgaria' => 'BG',
            'burkinafaso' => 'BF',
            'burundi' => 'BI',
            'cambodia' => 'KH',
            'cameroon' => 'CM',
            'canada' => 'CA',
            'capeverde' => 'CV',
            'caymanislands' => 'KY',
            'centralafricanrepublic' => 'CF',
            'chad' => 'TD',
            'chile' => 'CL',
            'china' => 'CN',
            'christmasisland' => 'CX',
            'cocoskeelingislands' => 'CC',
            'colombia' => 'CO',
            'comoros' => 'KM',
            'congo' => 'CG',
            'cookislands' => 'CK',
            'costarica' => 'CR',
            'croatia' => 'HR',
            'cuba' => 'CU',
            'curaao' => 'CW',
            'cyprus' => 'CY',
            'czechrepublic' => 'CZ',
            'democraticrepublicofthecongo' => 'CD',
            'denmark' => 'DK',
            'djibouti' => 'DJ',
            'dominica' => 'DM',
            'dominicanrepublic' => 'DO',
            'ecuador' => 'EC',
            'egypt' => 'EG',
            'elsalvador' => 'SV',
            'equatorialguinea' => 'GQ',
            'eritrea' => 'ER',
            'estonia' => 'EE',
            'eswatini' => 'SZ',
            'ethiopia' => 'ET',
            'falklandislands' => 'FK',
            'faroeislands' => 'FO',
            'fijiislands' => 'FJ',
            'finland' => 'FI',
            'france' => 'FR',
            'frenchguiana' => 'GF',
            'frenchpolynesia' => 'PF',
            'frenchsouthernterritories' => 'TF',
            'gabon' => 'GA',
            'georgia' => 'GE',
            'germany' => 'DE',
            'ghana' => 'GH',
            'gibraltar' => 'GI',
            'greece' => 'GR',
            'greenland' => 'GL',
            'grenada' => 'GD',
            'guadeloupe' => 'GP',
            'guam' => 'GU',
            'guatemala' => 'GT',
            'guernsey' => 'GG',
            'guinea' => 'GN',
            'guineabissau' => 'GW',
            'guyana' => 'GY',
            'haiti' => 'HT',
            'heardislandandmcdonaldislands' => 'HM',
            'honduras' => 'HN',
            'hongkongsar' => 'HK',
            'hungary' => 'HU',
            'iceland' => 'IS',
            'india' => 'IN',
            'indonesia' => 'ID',
            'iran' => 'IR',
            'iraq' => 'IQ',
            'ireland' => 'IE',
            'israel' => 'IL',
            'italy' => 'IT',
            'ivorycoast' => 'CI',
            'jamaica' => 'JM',
            'japan' => 'JP',
            'jersey' => 'JE',
            'jordan' => 'JO',
            'kazakhstan' => 'KZ',
            'kenya' => 'KE',
            'kiribati' => 'KI',
            'kosovo' => 'XK',
            'kuwait' => 'KW',
            'kyrgyzstan' => 'KG',
            'laos' => 'LA',
            'latvia' => 'LV',
            'lebanon' => 'LB',
            'lesotho' => 'LS',
            'liberia' => 'LR',
            'libya' => 'LY',
            'liechtenstein' => 'LI',
            'lithuania' => 'LT',
            'luxembourg' => 'LU',
            'macausar' => 'MO',
            'madagascar' => 'MG',
            'malawi' => 'MW',
            'malaysia' => 'MY',
            'maldives' => 'MV',
            'mali' => 'ML',
            'malta' => 'MT',
            'manisleof' => 'IM',
            'marshallislands' => 'MH',
            'martinique' => 'MQ',
            'mauritania' => 'MR',
            'mauritius' => 'MU',
            'mayotte' => 'YT',
            'mexico' => 'MX',
            'micronesia' => 'FM',
            'moldova' => 'MD',
            'monaco' => 'MC',
            'mongolia' => 'MN',
            'montenegro' => 'ME',
            'montserrat' => 'MS',
            'morocco' => 'MA',
            'mozambique' => 'MZ',
            'myanmar' => 'MM',
            'namibia' => 'NA',
            'nauru' => 'NR',
            'nepal' => 'NP',
            'netherlands' => 'NL',
            'newcaledonia' => 'NC',
            'newzealand' => 'NZ',
            'nicaragua' => 'NI',
            'niger' => 'NE',
            'nigeria' => 'NG',
            'niue' => 'NU',
            'norfolkisland' => 'NF',
            'northernmarianaislands' => 'MP',
            'northkorea' => 'KP',
            'northmacedonia' => 'MK',
            'norway' => 'NO',
            'oman' => 'OM',
            'pakistan' => 'PK',
            'palau' => 'PW',
            'palestinianterritoryoccupied' => 'PS',
            'panama' => 'PA',
            'papuanewguinea' => 'PG',
            'paraguay' => 'PY',
            'peru' => 'PE',
            'philippines' => 'PH',
            'pitcairnisland' => 'PN',
            'poland' => 'PL',
            'portugal' => 'PT',
            'puertorico' => 'PR',
            'qatar' => 'QA',
            'reunion' => 'RE',
            'romania' => 'RO',
            'russia' => 'RU',
            'rwanda' => 'RW',
            'saintbarthelemy' => 'BL',
            'sainthelena' => 'SH',
            'saintkittsandnevis' => 'KN',
            'saintlucia' => 'LC',
            'saintmartinfrenchpart' => 'MF',
            'saintpierreandmiquelon' => 'PM',
            'saintvincentandthegrenadines' => 'VC',
            'samoa' => 'WS',
            'sanmarino' => 'SM',
            'saotomeandprincipe' => 'ST',
            'saudiarabia' => 'SA',
            'senegal' => 'SN',
            'serbia' => 'RS',
            'seychelles' => 'SC',
            'sierraleone' => 'SL',
            'singapore' => 'SG',
            'sintmaartendutchpart' => 'SX',
            'slovakia' => 'SK',
            'slovenia' => 'SI',
            'solomonislands' => 'SB',
            'somalia' => 'SO',
            'southafrica' => 'ZA',
            'southgeorgia' => 'GS',
            'southkorea' => 'KR',
            'southsudan' => 'SS',
            'spain' => 'ES',
            'srilanka' => 'LK',
            'sudan' => 'SD',
            'suriname' => 'SR',
            'svalbardandjanmayenislands' => 'SJ',
            'sweden' => 'SE',
            'switzerland' => 'CH',
            'syria' => 'SY',
            'taiwan' => 'TW',
            'tajikistan' => 'TJ',
            'tanzania' => 'TZ',
            'thailand' => 'TH',
            'thebahamas' => 'BS',
            'thegambia' => 'GM',
            'timorleste' => 'TL',
            'togo' => 'TG',
            'tokelau' => 'TK',
            'tonga' => 'TO',
            'trinidadandtobago' => 'TT',
            'tunisia' => 'TN',
            'turkey' => 'TR',
            'turkmenistan' => 'TM',
            'turksandcaicosislands' => 'TC',
            'tuvalu' => 'TV',
            'uganda' => 'UG',
            'ukraine' => 'UA',
            'unitedarabemirates' => 'AE',
            'unitedkingdom' => 'GB',
            'unitedstates' => 'US',
            'unitedstatesminoroutlyingislands' => 'UM',
            'uruguay' => 'UY',
            'uzbekistan' => 'UZ',
            'vanuatu' => 'VU',
            'vaticancitystateholysee' => 'VA',
            'venezuela' => 'VE',
            'vietnam' => 'VN',
            'virginislandsbritish' => 'VG',
            'virginislandsus' => 'VI',
            'wallisandfutunaislands' => 'WF',
            'westernsahara' => 'EH',
            'yemen' => 'YE',
            'zambia' => 'ZM',
            'zimbabwe' => 'ZW',
        ];
    }
};
