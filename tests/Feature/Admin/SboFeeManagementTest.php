<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Country;
use App\Models\SboCapitalType;
use App\Models\SboFeeBand;
use App\Models\SboFeeSetting;
use App\Models\User;
use App\Support\SboFeeCalculator;
use Illuminate\Database\QueryException;
use Livewire\Volt\Volt;

beforeEach(function (): void {
    SboFeeCalculator::flushCache();
    $this->admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN->value]);
    SboFeeSetting::set('flat_fee', '25');
    SboFeeSetting::set('currency', 'Espees');
    SboFeeSetting::set('merchant_name', 'MOCDII1');
    SboFeeSetting::set('merchant_app', 'Espees Max');
});

describe('authorization', function (): void {
    it('forbids a non-admin from opening the fees page', function (): void {
        $user = User::factory()->create(['role' => UserRole::SBO_MANAGER->value]);

        Volt::actingAs($user)->test('admin.fees.index')->assertForbidden();
    });
});

describe('settings', function (): void {
    it('lets an admin update payment settings', function (): void {
        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('flatFee', '99')
            ->set('currency', 'Espees')
            ->set('merchantName', 'MOCNEW')
            ->set('merchantApp', 'Espees Max')
            ->set('espeesEnabled', true)
            ->set('espeesToken', 'TOKEN-123')
            ->set('espeesMerchantWallet', 'WALLET-123')
            ->set('espeesTestMode', true)
            ->call('saveSettings')
            ->assertHasNoErrors();

        SboFeeCalculator::flushCache();
        expect(SboFeeCalculator::flatFee())->toBe(99);
        expect(SboFeeCalculator::merchant()['name'])->toBe('MOCNEW');
        expect(SboFeeCalculator::merchant()['code'])->toBe('MOCNEW');
        expect(SboFeeCalculator::espeesGateway())->toMatchArray([
            'enabled' => true,
            'token' => 'TOKEN-123',
            'merchant_wallet' => 'WALLET-123',
            'test_mode' => true,
            'configured' => true,
        ]);
    });

    it('requires Espees credentials when checkout is enabled', function (): void {
        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('espeesEnabled', true)
            ->set('espeesToken', '')
            ->set('espeesMerchantWallet', '')
            ->call('saveSettings')
            ->assertHasErrors(['espeesToken', 'espeesMerchantWallet']);
    });

    it('renders saved payment settings in a table', function (): void {
        Volt::actingAs($this->admin)
            ->test('admin.fees.index')
            ->assertSee('Saved payment settings')
            ->assertSee('Currency')
            ->assertSee('Espees Merchant code')
            ->assertSee('Espees checkout')
            ->assertSee('Espees')
            ->assertSee('MOCDII1')
            ->assertSee('Espees Max');
    });

    it('lets an admin edit a payment setting from the table', function (): void {
        $merchant = SboFeeSetting::query()
            ->whereNull('country_id')
            ->where('key', 'merchant_name')
            ->firstOrFail();

        Volt::actingAs($this->admin)
            ->test('admin.fees.index')
            ->call('editSetting', $merchant->id)
            ->set('editSettingValue', 'MOCEDIT')
            ->call('updateSetting')
            ->assertHasNoErrors()
            ->assertSee('MOCEDIT');

        SboFeeCalculator::flushCache();
        expect(SboFeeCalculator::merchant()['name'])->toBe('MOCEDIT');
        expect(SboFeeCalculator::merchant()['code'])->toBe('MOCEDIT');
    });

    it('lets an admin delete a payment setting from the table', function (): void {
        $merchant = SboFeeSetting::query()
            ->whereNull('country_id')
            ->where('key', 'merchant_name')
            ->firstOrFail();

        Volt::actingAs($this->admin)
            ->test('admin.fees.index')
            ->call('deleteSetting', $merchant->id)
            ->assertHasNoErrors()
            ->assertDontSee('MOCDII1');

        expect(SboFeeSetting::query()->whereNull('country_id')->where('key', 'merchant_name')->exists())->toBeFalse();

        SboFeeCalculator::flushCache();
        expect(SboFeeCalculator::merchant()['name'])->toBe('');
    });
});

describe('bands', function (): void {
    it('lets an admin add a band that the calculator immediately reflects', function (): void {
        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('newBands.private.label', 'Tier 1')
            ->set('newBands.private.max_equity', null)
            ->set('newBands.private.fee', '500')
            ->call('addBand', 'private')
            ->assertHasNoErrors()
            ->assertSee('Tier 1')
            ->assertSet('newBands.private.label', '')
            ->assertSet('newBands.private.max_equity', null)
            ->assertSet('newBands.private.fee', '');

        expect(SboFeeBand::where('structure', 'private')->count())->toBe(1);

        SboFeeCalculator::flushCache();
        // Open-ended band → any equity resolves to it.
        expect(SboFeeCalculator::fee('private', 1000, 100.0))->toBe(500);
    });

    it('adds a band only to the submitted structure', function (): void {
        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('newBands.private.label', 'Private Only')
            ->set('newBands.private.max_equity', null)
            ->set('newBands.private.fee', '700')
            ->set('newBands.public.label', 'Public Draft')
            ->set('newBands.public.max_equity', null)
            ->set('newBands.public.fee', '900')
            ->call('addBand', 'private')
            ->assertHasNoErrors()
            ->assertSee('Private Only')
            ->assertDontSee('Public Draft')
            ->assertSet('newBands.public.label', 'Public Draft')
            ->assertSet('newBands.public.max_equity', null)
            ->assertSet('newBands.public.fee', '900');

        expect(SboFeeBand::where('structure', 'private')->where('label', 'Private Only')->exists())->toBeTrue();
        expect(SboFeeBand::where('structure', 'public')->exists())->toBeFalse();
    });

    it('scopes add-band validation errors to the submitted structure', function (): void {
        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('newBands.public.label', 'Public Draft')
            ->set('newBands.public.max_equity', null)
            ->set('newBands.public.fee', '900')
            ->call('addBand', 'private')
            ->assertHasErrors([
                'newBands.private.label',
                'newBands.private.fee',
            ])
            ->assertHasNoErrors([
                'newBands.public.label',
                'newBands.public.fee',
                'newBands.public.max_equity',
            ]);

        expect(SboFeeBand::count())->toBe(0);
    });

    it('reflects an edited band fee', function (): void {
        $band = SboFeeBand::create(['structure' => 'private', 'max_equity' => null, 'fee' => 50, 'label' => 'Flat', 'sort_order' => 0, 'is_active' => true]);

        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->call('editBand', $band->id)
            ->set('editBandFee', '777')
            ->set('editBandLabel', 'Flat')
            ->set('editBandMaxEquity', null)
            ->call('updateBand')
            ->assertHasNoErrors();

        SboFeeCalculator::flushCache();
        expect(SboFeeCalculator::fee('private', 1000, 100.0))->toBe(777);
    });
});

describe('capital types', function (): void {
    it('lets an admin add a capital type the calculator exposes', function (): void {
        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('newTypes.private.key', 'ordinary')
            ->set('newTypes.private.label', 'Ordinary Shares')
            ->call('addCapitalType', 'private')
            ->assertHasNoErrors()
            ->assertSee('Ordinary Shares')
            ->assertSet('newTypes.private.key', '')
            ->assertSet('newTypes.private.label', '');

        expect(SboCapitalType::count())->toBe(1);
        expect(SboFeeCalculator::capitalTypesFor('private'))->toBe(['ordinary' => 'Ordinary Shares']);
    });

    it('adds a capital type only to the submitted structure', function (): void {
        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('newTypes.private.key', 'ordinary')
            ->set('newTypes.private.label', 'Private Ordinary')
            ->set('newTypes.public.key', 'public_draft')
            ->set('newTypes.public.label', 'Public Draft')
            ->call('addCapitalType', 'private')
            ->assertHasNoErrors()
            ->assertSee('Private Ordinary')
            ->assertDontSee('Public Draft')
            ->assertSet('newTypes.public.key', 'public_draft')
            ->assertSet('newTypes.public.label', 'Public Draft');

        expect(SboCapitalType::where('structure', 'private')->where('key', 'ordinary')->exists())->toBeTrue();
        expect(SboCapitalType::where('structure', 'public')->exists())->toBeFalse();
    });

    it('rejects a duplicate key for the same structure', function (): void {
        SboCapitalType::create(['structure' => 'private', 'key' => 'ordinary', 'label' => 'Ordinary Shares', 'is_active' => true]);

        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('newTypes.private.key', 'ordinary')
            ->set('newTypes.private.label', 'Dup')
            ->set('newTypes.public.key', 'ordinary')
            ->set('newTypes.public.label', 'Public Ordinary')
            ->call('addCapitalType', 'private')
            ->assertHasErrors('newTypes.private.key')
            ->assertHasNoErrors([
                'newTypes.public.key',
                'newTypes.public.label',
            ]);

        expect(SboCapitalType::count())->toBe(1);
    });

    it('scopes capital type key format errors to the submitted structure', function (): void {
        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('newTypes.private.key', 'Bad Key')
            ->set('newTypes.private.label', 'Bad Key Type')
            ->set('newTypes.public.key', 'public_ordinary')
            ->set('newTypes.public.label', 'Public Ordinary')
            ->call('addCapitalType', 'private')
            ->assertHasErrors('newTypes.private.key')
            ->assertHasNoErrors([
                'newTypes.public.key',
                'newTypes.public.label',
            ]);

        expect(SboCapitalType::count())->toBe(0);
    });

    it('enforces unique global capital type keys at the database level', function (): void {
        SboCapitalType::create(['structure' => 'private', 'key' => 'ordinary', 'label' => 'Ordinary Shares', 'is_active' => true]);

        SboCapitalType::create(['structure' => 'private', 'key' => 'ordinary', 'label' => 'Duplicate Ordinary', 'is_active' => true]);
    })->throws(QueryException::class);
});

describe('database constraints', function (): void {
    it('enforces unique global fee setting keys at the database level', function (): void {
        SboFeeSetting::create(['country_id' => null, 'key' => 'currency', 'value' => 'Duplicate']);
    })->throws(QueryException::class);
});

describe('country scoping', function (): void {
    it('adds a band under a country scope without touching the global default', function (): void {
        $nigeria = Country::query()->create(['name' => 'Testland Nigeria']);

        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('countryId', $nigeria->id)
            ->set('newBands.private.label', 'NG Tier')
            ->set('newBands.private.max_equity', null)
            ->set('newBands.private.fee', '888')
            ->call('addBand', 'private')
            ->assertHasNoErrors()
            ->assertSee('NG Tier');

        expect(SboFeeBand::where('country_id', $nigeria->id)->count())->toBe(1);
        expect(SboFeeBand::whereNull('country_id')->where('structure', 'private')->count())->toBe(0);
    });

    it('allows the same capital-type key in different country scopes', function (): void {
        $nigeria = Country::query()->create(['name' => 'Testland Nigeria']);
        SboCapitalType::create(['country_id' => null, 'structure' => 'private', 'key' => 'ordinary', 'label' => 'Global Ordinary', 'is_active' => true]);

        // Same key, but scoped to Nigeria — not a duplicate.
        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('countryId', $nigeria->id)
            ->set('newTypes.private.key', 'ordinary')
            ->set('newTypes.private.label', 'NG Ordinary')
            ->call('addCapitalType', 'private')
            ->assertHasNoErrors()
            ->assertSee('NG Ordinary');

        expect(SboCapitalType::count())->toBe(2);
    });

    it('saves settings scoped to a country and leaves the global default intact', function (): void {
        $nigeria = Country::query()->create(['name' => 'Testland Nigeria']);

        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('countryId', $nigeria->id)
            ->set('flatFee', '250')
            ->set('currency', 'Naira')
            ->set('merchantName', 'NG-MERCHANT')
            ->set('merchantApp', 'Espees Max')
            ->call('saveSettings')
            ->assertHasNoErrors();

        SboFeeCalculator::flushCache();
        expect(SboFeeCalculator::flatFee($nigeria->id))->toBe(250);
        expect(SboFeeCalculator::currency($nigeria->id))->toBe('Naira');
        // Global default unchanged.
        expect(SboFeeCalculator::flatFee())->toBe(25);
        expect(SboFeeCalculator::currency())->toBe('Espees');
    });

    it('resolves Espees gateway settings through country fallback and overrides', function (): void {
        SboFeeSetting::set('espees_enabled', '1');
        SboFeeSetting::set('espees_token', 'GLOBAL-TOKEN');
        SboFeeSetting::set('espees_merchant_wallet', 'GLOBAL-WALLET');
        SboFeeSetting::set('espees_test_mode', '0');
        $nigeria = Country::query()->create(['name' => 'Testland Nigeria']);

        SboFeeCalculator::flushCache();
        expect(SboFeeCalculator::espeesGateway($nigeria->id)['token'])->toBe('GLOBAL-TOKEN');

        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('countryId', $nigeria->id)
            ->set('flatFee', '')
            ->set('currency', '')
            ->set('merchantName', '')
            ->set('merchantApp', '')
            ->set('espeesEnabled', true)
            ->set('espeesToken', 'NG-TOKEN')
            ->set('espeesMerchantWallet', 'NG-WALLET')
            ->set('espeesTestMode', true)
            ->call('saveSettings')
            ->assertHasNoErrors();

        SboFeeCalculator::flushCache();
        expect(SboFeeCalculator::espeesGateway($nigeria->id))->toMatchArray([
            'token' => 'NG-TOKEN',
            'merchant_wallet' => 'NG-WALLET',
            'test_mode' => true,
            'configured' => true,
        ]);
    });

    it('lets a country scope leave a setting blank to inherit the global default', function (): void {
        $nigeria = Country::query()->create(['name' => 'Testland Nigeria']);

        Volt::actingAs($this->admin)->test('admin.fees.index')
            ->set('countryId', $nigeria->id)
            ->set('flatFee', '250')
            ->set('currency', '')
            ->set('merchantName', '')
            ->set('merchantApp', '')
            ->call('saveSettings')
            ->assertHasNoErrors();

        SboFeeCalculator::flushCache();
        expect(SboFeeCalculator::flatFee($nigeria->id))->toBe(250);
        // currency was blank → no override row → inherits global.
        expect(SboFeeCalculator::currency($nigeria->id))->toBe('Espees');
        expect(SboFeeSetting::query()->where('country_id', $nigeria->id)->where('key', 'currency')->exists())->toBeFalse();
    });
});
