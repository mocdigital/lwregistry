<?php

declare(strict_types=1);

use App\Models\Country;
use App\Models\SboCapitalType;
use App\Models\SboFeeBand;
use App\Models\SboFeeSetting;
use App\Support\SboFeeCalculator;
use App\Support\SboFeeScheduleMisconfiguredException;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The calculator now reads the schedule from the database, so bind these tests
// to the app TestCase with a fresh DB (Unit tests are otherwise plain PHPUnit).
uses(Tests\TestCase::class, RefreshDatabase::class);

/*
 * Each test seeds the exact schedule it relies on, so assertions don't drift
 * when the MOC later edits the real fee schedule from the admin UI.
 */
beforeEach(function (): void {
    SboFeeCalculator::flushCache();

    SboFeeSetting::set('flat_fee', '25');
    SboFeeSetting::set('currency', 'Espees');
    SboFeeSetting::set('merchant_name', 'MOCDII1');
    SboFeeSetting::set('merchant_app', 'Espees Max');

    foreach ([
        ['structure' => 'private', 'max_equity' => 1_000_000, 'fee' => 50, 'label' => 'Low'],
        ['structure' => 'private', 'max_equity' => 10_000_000, 'fee' => 150, 'label' => 'Mid'],
        ['structure' => 'private', 'max_equity' => null, 'fee' => 400, 'label' => 'High'],
        ['structure' => 'public', 'max_equity' => 10_000_000, 'fee' => 250, 'label' => 'Low'],
        ['structure' => 'public', 'max_equity' => null, 'fee' => 2_000, 'label' => 'High'],
    ] as $i => $band) {
        SboFeeBand::create($band + ['sort_order' => $i, 'is_active' => true]);
    }

    SboCapitalType::create(['structure' => 'private', 'key' => 'ordinary', 'label' => 'Ordinary Shares', 'is_active' => true]);
});

describe('structure classification', function (): void {
    it('flags private and public as share structures', function (): void {
        expect(SboFeeCalculator::isShareStructure('private'))->toBeTrue();
        expect(SboFeeCalculator::isShareStructure('public'))->toBeTrue();
    });

    it('flags non-share structures as flat-fee', function (): void {
        expect(SboFeeCalculator::isShareStructure('no_shares'))->toBeFalse();
        expect(SboFeeCalculator::isShareStructure('sole_partnership'))->toBeFalse();
    });

    it('rejects unknown structures', function (): void {
        expect(SboFeeCalculator::isValidStructure('nonsense'))->toBeFalse();
    });
});

describe('equity', function (): void {
    it('multiplies issued shares by per-share value', function (): void {
        expect(SboFeeCalculator::equity(1000, 100.0))->toBe(100000.0);
    });
});

describe('flat fee for non-share structures', function (): void {
    it('returns the configured flat fee', function (): void {
        expect(SboFeeCalculator::fee('no_shares'))->toBe(25);
        expect(SboFeeCalculator::fee('sole_partnership'))->toBe(25);
    });
});

describe('banded fee for share structures', function (): void {
    it('picks the low band at its exact upper boundary', function (): void {
        expect(SboFeeCalculator::fee('private', 10_000, 100.0))->toBe(50);
    });

    it('rolls into the next band just above the boundary', function (): void {
        expect(SboFeeCalculator::fee('private', 1_000_001, 1.0))->toBe(150);
    });

    it('uses the open-ended band above all thresholds', function (): void {
        expect(SboFeeCalculator::fee('private', 1_000_000, 100.0))->toBe(400);
    });

    it('scopes bands per structure', function (): void {
        expect(SboFeeCalculator::fee('public', 1000, 100.0))->toBe(250);
    });

    it('throws when share inputs are missing', function (): void {
        SboFeeCalculator::fee('private');
    })->throws(InvalidArgumentException::class);

    it('throws for an unknown structure', function (): void {
        SboFeeCalculator::fee('nonsense');
    })->throws(InvalidArgumentException::class);
});

describe('fee label', function (): void {
    it('returns the band label for share structures', function (): void {
        expect(SboFeeCalculator::feeLabel('private', 1000, 100.0))->toBe('Low');
    });

    it('returns null for non-share structures', function (): void {
        expect(SboFeeCalculator::feeLabel('no_shares'))->toBeNull();
    });
});

describe('band options', function (): void {
    it('exposes ordered fee band ranges for dropdowns', function (): void {
        $options = SboFeeCalculator::bandOptionsFor('private');

        expect($options)->toHaveCount(3)
            ->and($options[0]['label'])->toBe('Low')
            ->and($options[0]['range_label'])->toBe('Up to 1,000,000')
            ->and($options[0]['display_label'])->toBe('Low — Up to 1,000,000')
            ->and($options[1]['label'])->toBe('Mid')
            ->and($options[1]['previous_max_equity'])->toBe(1_000_000)
            ->and($options[1]['range_label'])->toBe('1,000,001 - 10,000,000')
            ->and($options[1]['display_label'])->toBe('Mid — 1,000,001 - 10,000,000')
            ->and($options[2]['label'])->toBe('High')
            ->and($options[2]['range_label'])->toBe('Above 10,000,000')
            ->and($options[2]['display_label'])->toBe('High — Above 10,000,000');
    });

    it('does not duplicate labels that already describe the band range', function (): void {
        SboFeeBand::query()->delete();
        SboFeeCalculator::flushCache();

        SboFeeBand::create(['structure' => 'private', 'max_equity' => 1_000_000, 'fee' => 50, 'label' => 'Up to 1,000,000', 'sort_order' => 0, 'is_active' => true]);
        SboFeeBand::create(['structure' => 'private', 'max_equity' => 10_000_000, 'fee' => 150, 'label' => '1,000,001 – 10,000,000', 'sort_order' => 1, 'is_active' => true]);
        SboFeeBand::create(['structure' => 'private', 'max_equity' => null, 'fee' => 400, 'label' => 'Above 10,000,000', 'sort_order' => 2, 'is_active' => true]);

        $options = SboFeeCalculator::bandOptionsFor('private');

        expect($options[0]['display_label'])->toBe('Up to 1,000,000')
            ->and($options[1]['display_label'])->toBe('1,000,001 – 10,000,000')
            ->and($options[2]['display_label'])->toBe('Above 10,000,000');
    });

    it('computes the minimum issued shares for a selected band', function (): void {
        $high = SboFeeCalculator::bandOptionsFor('private')[2];

        expect(SboFeeCalculator::issuedSharesForBand($high, 100.0))->toBe(100_001);
        expect(SboFeeCalculator::equity(100_001, 100.0))->toBe(10_000_100.0);
    });

    it('returns null when a bounded band cannot contain the per-share value', function (): void {
        $low = SboFeeCalculator::bandOptionsFor('private')[0];

        expect(SboFeeCalculator::issuedSharesForBand($low, 2_000_000.0))->toBeNull();
    });
});

describe('settings', function (): void {
    it('reads currency and flat fee from the database', function (): void {
        expect(SboFeeCalculator::currency())->toBe('Espees');
        expect(SboFeeCalculator::flatFee())->toBe(25);
    });

    it('reads the merchant code from the saved backend setting', function (): void {
        expect(SboFeeCalculator::merchant()['code'])->toBe('MOCDII1');
        expect(SboFeeCalculator::merchant()['name'])->toBe('MOCDII1');
        expect(SboFeeCalculator::merchant()['app'])->toBe('Espees Max');
    });

    it('throws when active bands do not match', function (): void {
        SboFeeBand::where('structure', 'private')->update(['is_active' => false]);
        SboFeeCalculator::flushCache();

        SboFeeCalculator::fee('private', 1000, 100.0);
    })->throws(SboFeeScheduleMisconfiguredException::class);
});

describe('country scoping', function (): void {
    // The beforeEach schedule is the GLOBAL DEFAULT (country_id = null).
    beforeEach(function (): void {
        $this->nigeria = Country::query()->create(['name' => 'Testland Nigeria']);
        $this->ghana = Country::query()->create(['name' => 'Testland Ghana']);
    });

    it('falls back to the global default when a country has no override', function (): void {
        SboFeeCalculator::flushCache();

        expect(SboFeeCalculator::fee('private', 1000, 100.0, $this->ghana->id))->toBe(50);
        expect(SboFeeCalculator::flatFee($this->ghana->id))->toBe(25);
        expect(SboFeeCalculator::currency($this->ghana->id))->toBe('Espees');
    });

    it('uses a country override band when one exists for the structure', function (): void {
        SboFeeBand::create([
            'country_id' => $this->nigeria->id,
            'structure' => 'private', 'max_equity' => null, 'fee' => 9_999,
            'label' => 'NG flat', 'sort_order' => 0, 'is_active' => true,
        ]);
        SboFeeCalculator::flushCache();

        // Nigeria defines private bands → its own set wins entirely.
        expect(SboFeeCalculator::fee('private', 1000, 100.0, $this->nigeria->id))->toBe(9_999);
        // Ghana still inherits the global default.
        expect(SboFeeCalculator::fee('private', 1000, 100.0, $this->ghana->id))->toBe(50);
        // Global scope is untouched.
        expect(SboFeeCalculator::fee('private', 1000, 100.0))->toBe(50);
    });

    it('inherits global bands for a structure the country has not overridden', function (): void {
        // Nigeria overrides only public; private should still inherit global.
        SboFeeBand::create([
            'country_id' => $this->nigeria->id,
            'structure' => 'public', 'max_equity' => null, 'fee' => 7_777,
            'label' => 'NG public', 'sort_order' => 0, 'is_active' => true,
        ]);
        SboFeeCalculator::flushCache();

        expect(SboFeeCalculator::fee('public', 1000, 100.0, $this->nigeria->id))->toBe(7_777);
        expect(SboFeeCalculator::fee('private', 1000, 100.0, $this->nigeria->id))->toBe(50);
    });

    it('overrides a setting per country and falls back otherwise', function (): void {
        SboFeeSetting::set('currency', 'Naira', $this->nigeria->id);
        SboFeeSetting::set('flat_fee', '100', $this->nigeria->id);
        SboFeeCalculator::flushCache();

        expect(SboFeeCalculator::currency($this->nigeria->id))->toBe('Naira');
        expect(SboFeeCalculator::flatFee($this->nigeria->id))->toBe(100);
        // Ghana inherits the global default currency.
        expect(SboFeeCalculator::currency($this->ghana->id))->toBe('Espees');
        expect(SboFeeCalculator::currency())->toBe('Espees');
    });

    it('overrides capital types per country and falls back otherwise', function (): void {
        SboCapitalType::create([
            'country_id' => $this->nigeria->id,
            'structure' => 'private', 'key' => 'ng_only', 'label' => 'NG Only Shares', 'is_active' => true,
        ]);

        expect(SboFeeCalculator::capitalTypesFor('private', $this->nigeria->id))
            ->toBe(['ng_only' => 'NG Only Shares']);
        expect(SboFeeCalculator::capitalTypesFor('private', $this->ghana->id))
            ->toBe(['ordinary' => 'Ordinary Shares']);
    });
});
