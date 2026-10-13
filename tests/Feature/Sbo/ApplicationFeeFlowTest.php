<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use App\Support\SboFeeCalculator;
use Illuminate\Support\Str;

/*
 * Confirming the registration fee — the step that gates both payment methods.
 *
 * `SboEspeesPaymentController@start` refuses to open a checkout while
 * `calculated_fee` is null, and the manual proof panel is gated on the same
 * thing. The designed calculator posted nowhere, so `calculated_fee` could
 * never be set from the Vue side and neither method could run at all.
 */

beforeEach(function (): void {
    $this->seed(Database\Seeders\SboFeeScheduleSeeder::class);
    SboFeeCalculator::flushCache();
});

function feeUser(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

function feeSbo(User $owner): Sbo
{
    $name = 'Fee SBO ' . Str::random(6);

    return Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'unpaid',
        'status' => 'active',
        'is_active' => true,
        'country' => 'Nigeria',
    ]);
}

test('the fees step ships the real schedule, not three invented structures', function (): void {
    $user = feeUser();
    $sbo = feeSbo($user);

    // The fee page moved onto the add-business page's final step; that is where
    // the schedule is now shipped.
    $this->actingAs($user)
        ->get(route('sbo.listings.create', ['pay' => $sbo->id]))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $fees = $page->toArray()['props']['payFees'];

            expect($fees['confirmed'])->toBeFalse()
                ->and($fees['currency'])->toBe('Espees')
                ->and(collect($fees['structures'])->pluck('key')->all())
                ->toEqualCanonicalizing(['private', 'public', 'no_shares', 'sole_partnership']);

            // A share structure carries its own bands, so changing the select
            // needs no round trip.
            $private = collect($fees['structures'])->firstWhere('key', 'private');
            expect($private['isShare'])->toBeTrue()
                ->and($private['bands'])->not->toBeEmpty()
                ->and($private['capitalTypes'])->not->toBeEmpty();

            $flat = collect($fees['structures'])->firstWhere('key', 'no_shares');
            expect($flat['isShare'])->toBeFalse()->and($flat['bands'])->toBe([]);
        });
});

test('a flat-fee structure confirms straight away', function (): void {
    $user = feeUser();
    $sbo = feeSbo($user);

    $this->actingAs($user)
        ->from(route('application.show', ['step' => 'fees']))
        ->post(route('sbo.fees.confirm', $sbo), ['share_structure' => 'no_shares'])
        ->assertRedirect(route('application.show', ['step' => 'fees']))
        ->assertSessionHas('fees_success');

    expect($sbo->fresh()->calculated_fee)->toBe(SboFeeCalculator::flatFee($sbo->country_id));
});

test('a share structure needs its capital type, band and per-share value', function (): void {
    $user = feeUser();
    $sbo = feeSbo($user);

    $this->actingAs($user)
        ->post(route('sbo.fees.confirm', $sbo), ['share_structure' => 'private'])
        ->assertSessionHasErrors(['share_capital_type', 'fee_band_id', 'per_share_value']);

    expect($sbo->fresh()->calculated_fee)->toBeNull();

    $band = SboFeeCalculator::bandOptionsFor('private', $sbo->country_id)[0];

    $this->actingAs($user)
        ->post(route('sbo.fees.confirm', $sbo), [
            'share_structure' => 'private',
            'share_capital_type' => 'ordinary',
            'fee_band_id' => $band['id'],
            'per_share_value' => 1,
        ])
        ->assertSessionHasNoErrors();

    $fresh = $sbo->fresh();

    expect($fresh->calculated_fee)->toBe($band['fee'])
        ->and($fresh->fee_band)->toBe($band['label'])
        ->and($fresh->share_capital_type)->toBe('ordinary')
        // Issued shares are derived server-side from the band and the
        // per-share value; the client never supplies them.
        ->and($fresh->issued_shares_volume)->not->toBeNull();
});

test('the fee is computed server-side and a client-supplied amount is ignored', function (): void {
    $user = feeUser();
    $sbo = feeSbo($user);

    $this->actingAs($user)->post(route('sbo.fees.confirm', $sbo), [
        'share_structure' => 'no_shares',
        // Whatever a tampered client sends, this is not what gets stored.
        'calculated_fee' => 1,
        'fee' => 1,
    ]);

    expect($sbo->fresh()->calculated_fee)
        ->toBe(SboFeeCalculator::flatFee($sbo->country_id))
        ->not->toBe(1);
});

test('a per-share value that overshoots the band is refused', function (): void {
    $user = feeUser();
    $sbo = feeSbo($user);

    $bands = SboFeeCalculator::bandOptionsFor('private', $sbo->country_id);
    $first = $bands[0];

    $this->actingAs($user)
        ->post(route('sbo.fees.confirm', $sbo), [
            'share_structure' => 'private',
            'share_capital_type' => 'ordinary',
            'fee_band_id' => $first['id'],
            // One share alone already exceeds the band's ceiling.
            'per_share_value' => $first['max_equity'] + 1,
        ])
        ->assertSessionHasErrors('fee_band_id');

    expect($sbo->fresh()->calculated_fee)->toBeNull();
})->skip(
    fn (): bool => null === SboFeeCalculator::bandOptionsFor('private', null)[0]['max_equity'],
    'The first private band is unbounded in this schedule.'
);

test('the confirmed fee can be reopened, but not once it is paid', function (): void {
    $user = feeUser();
    $sbo = feeSbo($user);

    $this->actingAs($user)->post(route('sbo.fees.confirm', $sbo), ['share_structure' => 'no_shares']);
    expect($sbo->fresh()->calculated_fee)->not->toBeNull();

    $this->actingAs($user)
        ->from(route('application.show', ['step' => 'fees']))
        ->delete(route('sbo.fees.reset', $sbo))
        ->assertRedirect();

    expect($sbo->fresh()->calculated_fee)->toBeNull();

    // Now pay it, and the selection locks.
    $sbo->forceFill([
        'calculated_fee' => 25,
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user)
        ->from(route('application.show', ['step' => 'fees']))
        ->delete(route('sbo.fees.reset', $sbo))
        ->assertSessionHas('fees_error');

    expect($sbo->fresh()->calculated_fee)->toBe(25);
});

test('you cannot confirm a fee on someone else\'s application', function (): void {
    $sbo = feeSbo(feeUser());

    $this->actingAs(feeUser())
        ->post(route('sbo.fees.confirm', $sbo), ['share_structure' => 'no_shares'])
        ->assertForbidden();

    expect($sbo->fresh()->calculated_fee)->toBeNull();
});

test('espees checkout refuses to start until the fee is confirmed', function (): void {
    $user = feeUser();
    $sbo = feeSbo($user);

    $this->actingAs($user)
        ->from(route('application.show', ['step' => 'fees']))
        ->post(route('sbo.payments.espees.start', $sbo))
        ->assertRedirect()
        ->assertSessionHas('fees_error', 'Confirm the registration fee before starting Espees checkout.');
});

test('the screen reports why espees is unavailable rather than offering a dead button', function (): void {
    $user = feeUser();
    $sbo = feeSbo($user);

    $this->actingAs($user)->post(route('sbo.fees.confirm', $sbo), ['share_structure' => 'no_shares']);

    $this->actingAs($user)
        ->get(route('sbo.listings.create', ['pay' => $sbo->id]))
        ->assertInertia(function ($page): void {
            $espees = $page->toArray()['props']['payFees']['espees'];

            /*
             * No merchant token or wallet is configured in the test schedule,
             * and the reason now names them. It used to be one fixed sentence
             * for every cause, which sent administrators off to re-enter
             * credentials that were often already correct — the usual cause
             * being the enable toggle, not the credentials.
             */
            expect($espees['available'])->toBeFalse()
                ->and($espees['reason'])->toContain('merchant token')
                ->and($espees['reason'])->toContain('merchant wallet');
        });
});
