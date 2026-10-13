<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SboCapitalType;
use App\Models\SboFeeBand;
use App\Models\SboFeeSetting;
use Illuminate\Database\Seeder;

/**
 * Seeds a starting SBO fee schedule so the feature works out of the box.
 *
 * ALL AMOUNTS ARE PLACEHOLDERS — the Ministry of Commerce (MOC), Loveworld
 * Nation replaces them from the admin Fees page (no deploy). Idempotent, so it
 * can be re-run safely without duplicating rows.
 *
 * Everything seeded here is the GLOBAL DEFAULT (country_id = null); it applies
 * to every country until a country-specific override is added from the admin
 * Fees page. Country overrides are never seeded.
 */
final class SboFeeScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'flat_fee' => '25',
            'currency' => 'Espees',
            'merchant_name' => 'MOCDII1',
            'merchant_app' => 'Espees Max',
        ];

        foreach ($settings as $key => $value) {
            SboFeeSetting::set($key, $value);
        }

        $capitalTypes = [
            'private' => [
                ['key' => 'ordinary', 'label' => 'Ordinary Shares'],
                ['key' => 'preference', 'label' => 'Preference Shares'],
            ],
            'public' => [
                ['key' => 'ordinary', 'label' => 'Ordinary Shares'],
                ['key' => 'preference', 'label' => 'Preference Shares'],
                ['key' => 'redeemable', 'label' => 'Redeemable Shares'],
            ],
        ];

        foreach ($capitalTypes as $structure => $types) {
            foreach ($types as $i => $type) {
                SboCapitalType::updateOrCreate(
                    ['country_id' => null, 'structure' => $structure, 'key' => $type['key']],
                    ['label' => $type['label'], 'sort_order' => $i, 'is_active' => true],
                );
            }
        }

        $bands = [
            'private' => [
                ['max_equity' => 1_000_000, 'fee' => 50, 'label' => 'Up to 1,000,000'],
                ['max_equity' => 10_000_000, 'fee' => 150, 'label' => '1,000,001 – 10,000,000'],
                ['max_equity' => 100_000_000, 'fee' => 400, 'label' => '10,000,001 – 100,000,000'],
                ['max_equity' => null, 'fee' => 1_000, 'label' => 'Above 100,000,000'],
            ],
            'public' => [
                ['max_equity' => 10_000_000, 'fee' => 250, 'label' => 'Up to 10,000,000'],
                ['max_equity' => 100_000_000, 'fee' => 750, 'label' => '10,000,001 – 100,000,000'],
                ['max_equity' => 1_000_000_000, 'fee' => 2_000, 'label' => '100,000,001 – 1,000,000,000'],
                ['max_equity' => null, 'fee' => 5_000, 'label' => 'Above 1,000,000,000'],
            ],
        ];

        foreach ($bands as $structure => $structureBands) {
            foreach ($structureBands as $i => $band) {
                SboFeeBand::updateOrCreate(
                    ['country_id' => null, 'structure' => $structure, 'label' => $band['label']],
                    [
                        'max_equity' => $band['max_equity'],
                        'fee' => $band['fee'],
                        'sort_order' => $i,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
