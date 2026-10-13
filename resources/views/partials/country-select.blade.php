{{--
  Searchable country dropdown bound to a Livewire model.

  Params:
    $model       — Livewire property name (e.g. 'bizCountry')
    $value       — current value
    $countries   — collection/array of country names
    $placeholder — input placeholder text
--}}
@php
    $countryOptions = collect($countries ?? [])->values();
    $placeholder = $placeholder ?? 'Country Location of Business';
    // When true, selecting a country syncs to the server immediately so a
    // Livewire updated-hook can react (e.g. load that country's states).
    $live = $live ?? false;
    $selectMethod = $selectMethod ?? null;
@endphp
<div x-data="countrySelect(@js($value ?? ''), @js($model), @js($live), @js($selectMethod))" class="position-relative" wire:ignore @click.outside="open = false">
    <div class="input-group">
        <span class="input-group-text" x-text="selectedFlag()"></span>
        <input type="text" class="form-control"
               placeholder="{{ $placeholder }}"
               x-model="query"
               x-ref="searchInput"
               autocomplete="off"
               @focus="openForSearch()"
               @click="openForSearch()"
               @keydown.escape.prevent="open = false"
               @change="commitTyped()"
               @input="resumeSearch(); syncTyped()">
    </div>

    <ul class="list-group position-absolute w-100 shadow-sm"
        style="z-index:1085; max-height:240px; overflow-y:auto;"
        x-show="open" x-cloak>
        <template x-for="c in filtered()" :key="c">
            <li class="list-group-item list-group-item-action d-flex align-items-center"
                style="cursor:pointer;"
                @pointerdown.prevent="choose(c)">
                <span class="me-2" x-text="flagFor(c)"></span>
                <span x-text="c"></span>
            </li>
        </template>
        <li class="list-group-item text-muted small" x-show="filtered().length === 0">No match</li>
    </ul>
</div>

@once
    <script>
        const countrySelectRegionCodes = [
            'AF', 'AX', 'AL', 'DZ', 'AS', 'AD', 'AO', 'AI', 'AQ', 'AG', 'AR', 'AM', 'AW', 'AU', 'AT', 'AZ',
            'BH', 'BD', 'BB', 'BY', 'BE', 'BZ', 'BJ', 'BM', 'BT', 'BO', 'BQ', 'BA', 'BW', 'BV', 'BR', 'IO',
            'BN', 'BG', 'BF', 'BI', 'KH', 'CM', 'CA', 'CV', 'KY', 'CF', 'TD', 'CL', 'CN', 'CX', 'CC', 'CO',
            'KM', 'CG', 'CD', 'CK', 'CR', 'CI', 'HR', 'CU', 'CW', 'CY', 'CZ', 'DK', 'DJ', 'DM', 'DO', 'TL',
            'EC', 'EG', 'SV', 'GQ', 'ER', 'EE', 'SZ', 'ET', 'FK', 'FO', 'FJ', 'FI', 'FR', 'GF', 'PF', 'TF',
            'GA', 'GM', 'GE', 'DE', 'GH', 'GI', 'GR', 'GL', 'GD', 'GP', 'GU', 'GT', 'GG', 'GN', 'GW', 'GY',
            'HT', 'HM', 'HN', 'HK', 'HU', 'IS', 'IN', 'ID', 'IR', 'IQ', 'IE', 'IM', 'IL', 'IT', 'JM', 'JP',
            'JE', 'JO', 'KZ', 'KE', 'KI', 'XK', 'KW', 'KG', 'LA', 'LV', 'LB', 'LS', 'LR', 'LY', 'LI', 'LT',
            'LU', 'MO', 'MG', 'MW', 'MY', 'MV', 'ML', 'MT', 'MH', 'MQ', 'MR', 'MU', 'YT', 'MX', 'FM', 'MD',
            'MC', 'MN', 'ME', 'MS', 'MA', 'MZ', 'MM', 'NA', 'NR', 'NP', 'NL', 'NC', 'NZ', 'NI', 'NE', 'NG',
            'NU', 'NF', 'KP', 'MK', 'MP', 'NO', 'OM', 'PK', 'PW', 'PS', 'PA', 'PG', 'PY', 'PE', 'PH', 'PN',
            'PL', 'PT', 'PR', 'QA', 'RE', 'RO', 'RU', 'RW', 'BL', 'SH', 'KN', 'LC', 'MF', 'PM', 'VC', 'WS',
            'SM', 'ST', 'SA', 'SN', 'RS', 'SC', 'SL', 'SG', 'SX', 'SK', 'SI', 'SB', 'SO', 'ZA', 'GS', 'KR',
            'SS', 'ES', 'LK', 'SD', 'SR', 'SJ', 'SE', 'CH', 'SY', 'TW', 'TJ', 'TZ', 'TH', 'BS', 'TG', 'TK',
            'TO', 'TT', 'TN', 'TR', 'TM', 'TC', 'TV', 'UG', 'UA', 'AE', 'GB', 'US', 'UM', 'UY', 'UZ', 'VU',
            'VA', 'VE', 'VN', 'VG', 'VI', 'WF', 'EH', 'YE', 'ZM', 'ZW',
        ];

        const countrySelectAliases = {
            'antigua and barbuda': 'AG',
            'bonaire sint eustatius and saba': 'BQ',
            'bosnia and herzegovina': 'BA',
            'cape verde': 'CV',
            'congo': 'CG',
            'cote divoire ivory coast': 'CI',
            'czech republic': 'CZ',
            'democratic republic of the congo': 'CD',
            'east timor': 'TL',
            'fiji islands': 'FJ',
            'gambia the': 'GM',
            'guernsey and alderney': 'GG',
            'heard island and mcdonald islands': 'HM',
            'hong kong sar': 'HK',
            'macau sar': 'MO',
            'man isle of': 'IM',
            'myanmar': 'MM',
            'north korea': 'KP',
            'palestinian territory occupied': 'PS',
            'papua new guinea': 'PG',
            'pitcairn island': 'PN',
            'reunion': 'RE',
            'russia': 'RU',
            'saint helena': 'SH',
            'saint kitts and nevis': 'KN',
            'saint lucia': 'LC',
            'saint pierre and miquelon': 'PM',
            'saint vincent and the grenadines': 'VC',
            'saintbarthelemy': 'BL',
            'saintmartin french part': 'MF',
            'sint maarten dutch part': 'SX',
            'south georgia': 'GS',
            'south korea': 'KR',
            'svalbard and jan mayen islands': 'SJ',
            'swaziland': 'SZ',
            'syria': 'SY',
            'the bahamas': 'BS',
            'trinidad and tobago': 'TT',
            'turkey': 'TR',
            'united states minor outlying islands': 'UM',
            'vatican city state holy see': 'VA',
            'virgin islands british': 'VG',
            'virgin islands us': 'VI',
            'wallis and futuna islands': 'WF',
        };

        function normalizeCountryName(name) {
            return (name || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .replace(/&/g, 'and')
                .replace(/[^a-z0-9]+/g, ' ')
                .trim()
                .replace(/\s+/g, ' ');
        }

        function compactCountryName(name) {
            return normalizeCountryName(name).replace(/\s+/g, '');
        }

        function countryFlagFromCode(code) {
            if (!code || code.length !== 2) return '🌐';

            return code
                .toUpperCase()
                .replace(/./g, char => String.fromCodePoint(127397 + char.charCodeAt(0)));
        }

        function buildCountryCodeMap() {
            const names = typeof Intl !== 'undefined' && Intl.DisplayNames
                ? new Intl.DisplayNames(['en'], { type: 'region' })
                : null;
            const map = {};

            countrySelectRegionCodes.forEach(code => {
                if (!names) return;

                map[normalizeCountryName(names.of(code))] = code;
            });

            Object.entries(countrySelectAliases).forEach(([name, code]) => {
                map[normalizeCountryName(name)] = code;
                map[compactCountryName(name)] = code;
            });

            return map;
        }

        const countrySelectCodeMap = buildCountryCodeMap();

        function countrySelect(initial, model, live, selectMethod) {
            return {
                open: false,
                query: initial || '',
                selected: initial || '',
                all: @js($countryOptions),
                selecting: false,
                closeTimer: null,
                selectionSeq: 0,
                filtered() {
                    const q = (this.query || '').toLowerCase();
                    if (!q) return this.all.slice(0, 60);
                    return this.all.filter(c => c.toLowerCase().includes(q)).slice(0, 60);
                },
                openForSearch() {
                    if (this.selecting) return;

                    this.open = true;
                },
                resumeSearch() {
                    this.selecting = false;

                    if (this.closeTimer) {
                        clearTimeout(this.closeTimer);
                    }

                    this.open = true;
                },
                closeAfterSelect() {
                    this.selecting = true;
                    this.open = false;

                    if (this.closeTimer) {
                        clearTimeout(this.closeTimer);
                    }

                    this.$nextTick(() => {
                        this.open = false;

                        if (this.$refs.searchInput) {
                            this.$refs.searchInput.blur();
                        }
                    });

                    this.closeTimer = setTimeout(() => {
                        this.selecting = false;
                    }, 250);
                },
                flagFor(country) {
                    const normalized = normalizeCountryName(country);
                    const code = countrySelectCodeMap[normalized] || countrySelectCodeMap[compactCountryName(country)] || '';

                    return countryFlagFromCode(code);
                },
                selectedFlag() {
                    return this.selected ? this.flagFor(this.selected) : '🌐';
                },
                syncTyped() {
                    const q = (this.query || '').trim().toLowerCase();
                    const match = this.all.find(c => c.toLowerCase() === q) || '';

                    this.selected = match;
                },
                commitTyped() {
                    if (this.selecting) return;

                    const q = (this.query || '').trim().toLowerCase();
                    const match = this.all.find(c => c.toLowerCase() === q) || '';

                    this.selected = match;

                    if (selectMethod) {
                        const seq = ++this.selectionSeq;

                        this.$wire.call(selectMethod, match).then(() => {
                            if (seq !== this.selectionSeq) return;

                            this.selected = match;
                            this.query = match || this.query;
                            this.closeAfterSelect();
                        });

                        return;
                    }

                    this.$wire.set(model, match, live === true);
                },
                choose(c) {
                    const seq = ++this.selectionSeq;

                    this.selected = c;
                    this.query = c;
                    this.closeAfterSelect();

                    if (selectMethod) {
                        this.$wire.call(selectMethod, c).then(() => {
                            if (seq !== this.selectionSeq) return;

                            this.selected = c;
                            this.query = c;
                            this.closeAfterSelect();
                        });

                        return;
                    }

                    // live: sync to the server now so an updated-hook can react.
                    // Otherwise defer for speed.
                    this.$wire.set(model, c, live === true);
                },
            };
        }
    </script>
@endonce
