<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\SboFeeSetting;
use App\Models\User;
use App\Support\SboFeeCalculator;
use App\Support\SboFeePayload;
use Illuminate\Support\Str;

/*
 * What the fee screens are told about the Espees gateway.
 *
 * The panel used to answer every failure with one sentence: "Espees checkout is
 * not configured for this country yet. An administrator sets the merchant token
 * and wallet on the Fees admin page." That is wrong in the most common case —
 * the Fees page saves the token and wallet independently of the enable toggle,
 * so a fully credentialled gateway that nobody switched on sent administrators
 * off to re-enter credentials that were already correct.
 */

function gatewayTestSbo(): Sbo
{
    $owner = User::factory()->create(['email_verified_at' => now()]);

    return Sbo::create([
        'name' => 'Fee Co',
        'slug' => 'fee-co-' . Str::random(6),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
    ]);
}

function setEspeesGateway(array $settings): void
{
    foreach ($settings as $key => $value) {
        SboFeeSetting::set($key, $value, null);
    }

    SboFeeCalculator::flushCache();
}

afterEach(fn () => SboFeeCalculator::flushCache());

test('credentials present but the toggle off says so, instead of blaming the credentials', function (): void {
    $sbo = gatewayTestSbo();

    setEspeesGateway([
        'espees_enabled' => '0',
        'espees_token' => str_repeat('a', 48),
        'espees_merchant_wallet' => '0x' . str_repeat('b', 40),
    ]);

    $espees = SboFeePayload::for($sbo, $sbo->creator)['espees'];

    expect($espees['available'])->toBeFalse()
        ->and($espees['reason'])->toContain('switched off')
        ->and($espees['reason'])->toContain('Enable Espees checkout')
        // The old message sent admins to re-enter these; they are fine.
        ->and($espees['reason'])->not->toContain('merchant token');
});

test('a genuinely missing credential is named', function (): void {
    $sbo = gatewayTestSbo();

    setEspeesGateway([
        'espees_enabled' => '1',
        'espees_token' => '',
        'espees_merchant_wallet' => '0x' . str_repeat('b', 40),
    ]);

    expect(SboFeePayload::for($sbo, $sbo->creator)['espees']['reason'])
        ->toContain('merchant token')
        ->not->toContain('merchant wallet');

    setEspeesGateway(['espees_token' => str_repeat('a', 48), 'espees_merchant_wallet' => '']);

    expect(SboFeePayload::for($sbo, $sbo->creator)['espees']['reason'])
        ->toContain('merchant wallet')
        ->not->toContain('merchant token');
});

test('turning the toggle on with credentials in place makes checkout available', function (): void {
    $sbo = gatewayTestSbo();

    setEspeesGateway([
        'espees_enabled' => '1',
        'espees_token' => str_repeat('a', 48),
        'espees_merchant_wallet' => '0x' . str_repeat('b', 40),
    ]);

    $espees = SboFeePayload::for($sbo, $sbo->creator)['espees'];

    expect($espees['available'])->toBeTrue()
        ->and($espees['reason'])->toBeNull();
});

test('the manual-payment merchant comes from the admin page, not a constant', function (): void {
    $sbo = gatewayTestSbo();

    setEspeesGateway(['merchant_name' => 'ESPMER01', 'merchant_app' => 'Espees Max']);

    $merchant = SboFeePayload::for($sbo, $sbo->creator)['merchant'];

    /*
     * The screen hardcoded 'MOCDII1'. An administrator setting ESPMER01 here
     * would have had applicants sent to a merchant code that was never
     * configured anywhere.
     */
    expect($merchant['code'])->toBe('ESPMER01')
        ->and($merchant['app'])->toBe('Espees Max')
        ->and($merchant['code'])->not->toBe('MOCDII1');
});
