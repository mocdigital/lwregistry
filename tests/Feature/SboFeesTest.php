<?php

declare(strict_types=1);

use App\Livewire\SboFees;
use App\Models\Country;
use App\Models\Sbo;
use App\Models\SboCapitalType;
use App\Models\SboFeeBand;
use App\Models\SboFeeSetting;
use App\Models\SboPaymentProof;
use App\Models\User;
use App\Notifications\AdminFeePaymentSubmittedNotification;
use App\Support\SboFeeCalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('compliance');
    SboFeeCalculator::flushCache();

    SboFeeSetting::set('flat_fee', '25');
    SboFeeSetting::set('currency', 'Espees');
    SboFeeSetting::set('merchant_name', 'MOCDII1');
    SboFeeSetting::set('merchant_app', 'Espees Max');
    SboFeeSetting::set('espees_enabled', '0');

    SboCapitalType::create(['structure' => 'private', 'key' => 'ordinary', 'label' => 'Ordinary Shares', 'is_active' => true]);
    $this->lowBand = SboFeeBand::create(['structure' => 'private', 'max_equity' => 1_000_000, 'fee' => 50, 'label' => 'Low', 'sort_order' => 0, 'is_active' => true]);
    $this->highBand = SboFeeBand::create(['structure' => 'private', 'max_equity' => null, 'fee' => 400, 'label' => 'High', 'sort_order' => 1, 'is_active' => true]);

    $this->user = User::factory()->create();
    $this->sbo = Sbo::create([
        'name' => 'Acme Ltd',
        'slug' => 'acme-ltd',
        'created_by' => $this->user->id,
        'payment_status' => 'unpaid',
    ]);
});

describe('fee confirmation', function (): void {
    it('renders private and public band dropdown options for share structures', function (): void {
        SboCapitalType::create(['structure' => 'public', 'key' => 'ordinary', 'label' => 'Public Ordinary', 'is_active' => true]);
        SboFeeBand::create(['structure' => 'public', 'max_equity' => 2_000_000, 'fee' => 80, 'label' => 'Public Low', 'sort_order' => 0, 'is_active' => true]);
        SboFeeCalculator::flushCache();

        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'private')
            ->assertSee('Low')
            ->assertSee('High')
            ->assertSee('Up to 1,000,000')
            ->set('shareStructure', 'public')
            ->assertSee('Public Low')
            ->assertSee('Up to 2,000,000');
    });

    it('does not duplicate range-style labels or show fees inside dropdown options', function (): void {
        SboFeeBand::query()->where('structure', 'private')->delete();
        SboFeeBand::create(['structure' => 'private', 'max_equity' => 1_000_000, 'fee' => 50, 'label' => 'Up to 1,000,000', 'sort_order' => 0, 'is_active' => true]);
        SboFeeBand::create(['structure' => 'private', 'max_equity' => 10_000_000, 'fee' => 150, 'label' => '1,000,001 – 10,000,000', 'sort_order' => 1, 'is_active' => true]);
        SboFeeBand::create(['structure' => 'private', 'max_equity' => null, 'fee' => 400, 'label' => 'Above 10,000,000', 'sort_order' => 2, 'is_active' => true]);
        SboFeeCalculator::flushCache();

        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'private')
            ->assertSee('1,000,001 – 10,000,000')
            ->assertDontSee('1,000,001 – 10,000,000 — 1,000,001 - 10,000,000')
            ->assertDontSee('(150 Espees)');
    });

    it('computes and stores a banded fee server-side for a share structure', function (): void {
        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'private')
            ->set('shareCapitalType', 'ordinary')
            ->set('selectedFeeBandId', $this->lowBand->id)
            ->set('perShareValue', 100.0)
            ->call('confirmSelections')
            ->assertHasNoErrors();

        $this->sbo->refresh();
        expect($this->sbo->calculated_fee)->toBe(50);
        expect($this->sbo->fee_band)->toBe('Low');
        expect($this->sbo->share_structure)->toBe('private');
        expect($this->sbo->issued_shares_volume)->toBe(1);
        expect((float) $this->sbo->per_share_value)->toBe(100.0);
    });

    it('saves an incomplete fee setup as a draft without confirming a fee', function (): void {
        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'private')
            ->set('shareCapitalType', 'ordinary')
            ->set('selectedFeeBandId', $this->lowBand->id)
            ->set('perShareValue', 100.0)
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSee('Your fee setup was saved');

        $this->sbo->refresh();

        expect($this->sbo->calculated_fee)->toBeNull()
            ->and($this->sbo->share_structure)->toBe('private')
            ->and($this->sbo->share_capital_type)->toBe('ordinary')
            ->and($this->sbo->issued_shares_volume)->toBe(1)
            ->and((float) $this->sbo->per_share_value)->toBe(100.0);
    });

    it('computes the minimum share capital for an open-ended band', function (): void {
        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'private')
            ->set('shareCapitalType', 'ordinary')
            ->set('selectedFeeBandId', $this->highBand->id)
            ->set('perShareValue', 100.0)
            ->assertSee('Computed issued shares')
            ->assertSee('10,001')
            ->assertSee('1,000,100.00')
            ->call('confirmSelections')
            ->assertHasNoErrors();

        $this->sbo->refresh();
        expect($this->sbo->calculated_fee)->toBe(400);
        expect($this->sbo->fee_band)->toBe('High');
        expect($this->sbo->issued_shares_volume)->toBe(10_001);
    });

    it('uses the SBO country override fee when the country has its own band', function (): void {
        $nigeria = Country::query()->create(['name' => 'Testland Nigeria']);
        $ngBand = SboFeeBand::create([
            'country_id' => $nigeria->id,
            'structure' => 'private', 'max_equity' => null, 'fee' => 999,
            'label' => 'NG All', 'sort_order' => 0, 'is_active' => true,
        ]);
        $this->sbo->update(['country_id' => $nigeria->id]);
        SboFeeCalculator::flushCache();

        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'private')
            ->assertSee('NG All')
            ->assertDontSee('Low')
            ->set('shareCapitalType', 'ordinary')
            ->set('selectedFeeBandId', $ngBand->id)
            ->set('perShareValue', 100.0)
            ->call('confirmSelections')
            ->assertHasNoErrors();

        $this->sbo->refresh();
        expect($this->sbo->calculated_fee)->toBe(999);
        expect($this->sbo->fee_band)->toBe('NG All');
    });

    it('rejects an arbitrary share capital type key', function (): void {
        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'private')
            ->set('shareCapitalType', 'made_up_type')
            ->set('selectedFeeBandId', $this->lowBand->id)
            ->set('perShareValue', 100.0)
            ->call('confirmSelections')
            ->assertHasErrors('shareCapitalType');

        expect($this->sbo->fresh()->calculated_fee)->toBeNull();
    });

    it('blocks confirmation when no active fee band matches the selection', function (): void {
        SboFeeBand::query()->where('structure', 'private')->update(['is_active' => false]);
        SboFeeCalculator::flushCache();

        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'private')
            ->set('shareCapitalType', 'ordinary')
            ->set('selectedFeeBandId', $this->lowBand->id)
            ->set('perShareValue', 100.0)
            ->call('confirmSelections')
            ->assertHasErrors('selectedFeeBandId');

        expect($this->sbo->fresh()->calculated_fee)->toBeNull();
    });

    it('rejects a stale fee band id', function (): void {
        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'private')
            ->set('shareCapitalType', 'ordinary')
            ->set('selectedFeeBandId', 999999)
            ->set('perShareValue', 100.0)
            ->call('confirmSelections')
            ->assertHasErrors('selectedFeeBandId');

        expect($this->sbo->fresh()->calculated_fee)->toBeNull();
    });

    it('blocks impossible per-share values for a bounded fee band', function (): void {
        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'private')
            ->set('shareCapitalType', 'ordinary')
            ->set('selectedFeeBandId', $this->lowBand->id)
            ->set('perShareValue', 2_000_000.0)
            ->assertSee('cannot be used with this per-share value')
            ->call('confirmSelections')
            ->assertHasErrors('selectedFeeBandId');

        expect($this->sbo->fresh()->calculated_fee)->toBeNull();
    });

    it('applies the flat fee for a non-share structure without share inputs', function (): void {
        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'no_shares')
            ->call('confirmSelections')
            ->assertHasNoErrors();

        $this->sbo->refresh();
        expect($this->sbo->calculated_fee)->toBe(25);
        expect($this->sbo->share_capital_type)->toBeNull();
        expect($this->sbo->issued_shares_volume)->toBeNull();
    });
});

describe('proof of payment', function (): void {
    it('shows a completed payment state and hides further payment controls after confirmation', function (): void {
        $this->sbo->update([
            'calculated_fee' => 25,
            'share_structure' => 'no_shares',
            'payment_status' => 'paid',
            'payment_confirmed_at' => now(),
        ]);

        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->assertSee('Registration payment confirmed')
            ->assertDontSee('Manual payment')
            ->assertDontSee('How to pay');
    });

    it('renders a highlighted copyable merchant code in the payment instructions', function (): void {
        $this->sbo->update(['calculated_fee' => 25, 'share_structure' => 'no_shares']);

        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->assertSeeHtml('sbo-payment-card-stack')
            ->assertSee('How to pay')
            ->assertSee('Merchant code')
            ->assertSee('MOCDII1')
            ->assertSeeHtml('data-copy-merchant-code');
    });

    it('shows Espees checkout for normal SBO owners when it is configured', function (): void {
        SboFeeSetting::set('espees_enabled', '1');
        SboFeeSetting::set('espees_token', 'TOKEN-123');
        SboFeeSetting::set('espees_merchant_wallet', 'WALLET-123');
        SboFeeCalculator::flushCache();

        $this->sbo->update(['calculated_fee' => 25, 'share_structure' => 'no_shares']);

        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->assertSee('Manual payment')
            ->assertSee('How to pay')
            ->assertSee('@moc-itdept')
            ->assertSee('KingsChat')
            ->assertSeeHtml('<details class="sbo-manual-pay mb-3">')
            ->assertDontSeeHtml('<details class="sbo-manual-pay mb-3" open>')
            ->assertSee('Pay securely with Espees')
            ->assertSee('Pay 25 Espees with Espees');
    });

    it('keeps the Espees checkout endpoint owner-scoped', function (): void {
        $other = User::factory()->create(['role' => App\Enums\UserRole::SBO_MANAGER]);
        Sbo::create([
            'name' => 'Other Organization',
            'slug' => 'other-organization',
            'created_by' => $other->id,
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($other)
            ->post('/sbo/applications/' . $this->sbo->id . '/payments/espees/start')
            ->assertForbidden();
    });

    it('uses the saved backend merchant code on the payment card', function (): void {
        SboFeeSetting::set('merchant_name', 'DYNAMIC-CODE');
        SboFeeCalculator::flushCache();

        $this->sbo->update(['calculated_fee' => 25, 'share_structure' => 'no_shares']);

        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->assertSee('DYNAMIC-CODE')
            ->assertSeeHtml('data-code="DYNAMIC-CODE"');
    });

    it('marks payment submitted without confirming it, and notifies admins', function (): void {
        Notification::fake();
        $admin = User::factory()->create(['role' => App\Enums\UserRole::ADMIN->value]);

        $this->sbo->update(['calculated_fee' => 25, 'share_structure' => 'no_shares']);

        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('paymentReference', 'TXN-123')
            ->set('proof', UploadedFile::fake()->image('proof.png', 200, 200))
            ->call('submitProof')
            ->assertHasNoErrors()
            ->assertDispatched('payment-proof-uploaded')
            ->assertDispatched('ui-toast')
            ->assertSet('paymentReference', 'TXN-123')
            ->assertSet('proof', null);

        $this->sbo->refresh();
        $paymentProof = SboPaymentProof::where('sbo_id', $this->sbo->id)->first();

        expect($this->sbo->payment_submitted_at)->not->toBeNull();
        expect($this->sbo->payment_status)->toBe('unpaid'); // NOT auto-confirmed
        expect($this->sbo->feesPaid())->toBeFalse();
        expect($paymentProof)->not->toBeNull();
        expect($paymentProof->status)->toBe('pending');
        expect($paymentProof->file())->not->toBeNull();
        expect($this->sbo->payment_reference)->toBe('TXN-123');
        Storage::disk('compliance')->assertExists($paymentProof->file()->getPathRelativeToRoot());

        Notification::assertSentTo($admin, AdminFeePaymentSubmittedNotification::class);
    });

    it('rejects a non-pdf/jpg/png proof file', function (): void {
        $this->sbo->update(['calculated_fee' => 25, 'share_structure' => 'no_shares']);

        Livewire::actingAs($this->user)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('proof', UploadedFile::fake()->create('proof.txt', 10, 'text/plain'))
            ->call('submitProof')
            ->assertHasErrors('proof');

        $this->sbo->refresh();
        expect($this->sbo->payment_submitted_at)->toBeNull();
    });
});

describe('authorization', function (): void {
    it('forbids a non-owner from confirming a fee', function (): void {
        $other = User::factory()->create();

        Livewire::actingAs($other)
            ->test(SboFees::class, ['sboId' => $this->sbo->id])
            ->set('shareStructure', 'no_shares')
            ->call('confirmSelections')
            ->assertForbidden();
    });
});
