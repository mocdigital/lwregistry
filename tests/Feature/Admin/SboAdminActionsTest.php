<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\SboPaymentProof;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

function adminActionsSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Actions SBO ' . Str::random(5);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => User::factory()->create(['role' => UserRole::SBO_MANAGER->value])->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'payment_submitted_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

beforeEach(function (): void {
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN->value]);
});

describe('authorization', function (): void {
    it('forbids a non-super-admin from unapproving, suspending, or deleting', function (): void {
        $sbo = adminActionsSbo();
        // A compliance admin has approve_sbos but is not a super admin.
        $admin = User::factory()->create(['role' => UserRole::ADMIN->value]);

        Volt::actingAs($admin)->test('admin.sbos.index')->call('unapprove', $sbo->id)->assertForbidden();
        Volt::actingAs($admin)->test('admin.sbos.index')->call('suspend', $sbo->id)->assertForbidden();
        Volt::actingAs($admin)->test('admin.sbos.index')->call('deleteSbo', $sbo->id)->assertForbidden();

        expect(Sbo::whereKey($sbo->id)->exists())->toBeTrue();
    });
});

describe('unapprove', function (): void {
    it('returns an SBO to the pre-payment stage: pending status + unpaid fees, documents intact', function (): void {
        $sbo = adminActionsSbo();

        Volt::actingAs($this->superAdmin)->test('admin.sbos.index')
            ->call('unapprove', $sbo->id)
            ->assertHasNoErrors();

        $sbo->refresh();
        expect($sbo->payment_status)->toBe('unpaid')
            ->and($sbo->payment_confirmed_at)->toBeNull()
            ->and($sbo->payment_submitted_at)->toBeNull()
            ->and($sbo->feesPaid())->toBeFalse()
            // Registration status flips back to pending (badge shows "Pending").
            ->and($sbo->verification_status)->toBe('pending')
            ->and($sbo->approved_by)->toBeNull()
            ->and($sbo->date_approved)->toBeNull()
            // Document compliance is left intact.
            ->and($sbo->documents_status)->toBe('compliant');
    });

    it('flips the listing so the SBO moves from the approved to the pending filter', function (): void {
        $sbo = adminActionsSbo(['name' => 'Badge Flip SBO']);

        // Approved filter shows it before, pending filter does not.
        Volt::actingAs($this->superAdmin)->test('admin.sbos.index')
            ->set('statusFilter', 'approved')->assertSee('Badge Flip SBO');

        Volt::actingAs($this->superAdmin)->test('admin.sbos.index')->call('unapprove', $sbo->id);

        // After unapprove it appears under Pending, not Approved.
        Volt::actingAs($this->superAdmin)->test('admin.sbos.index')
            ->set('statusFilter', 'pending')->assertSee('Badge Flip SBO');
        Volt::actingAs($this->superAdmin)->test('admin.sbos.index')
            ->set('statusFilter', 'approved')->assertDontSee('Badge Flip SBO');
    });

    it('keeps the payment proof but sets it back to pending', function (): void {
        $sbo = adminActionsSbo();
        $proof = SboPaymentProof::create([
            'sbo_id' => $sbo->id,
            'status' => 'approved',
            'reviewed_by' => $this->superAdmin->id,
            'reviewed_at' => now(),
        ]);

        Volt::actingAs($this->superAdmin)->test('admin.sbos.index')->call('unapprove', $sbo->id);

        $proof->refresh();
        expect(SboPaymentProof::whereKey($proof->id)->exists())->toBeTrue()
            ->and($proof->status)->toBe('pending')
            ->and($proof->reviewed_by)->toBeNull()
            ->and($proof->reviewed_at)->toBeNull();
    });

    it('is a no-op when the SBO never paid or submitted', function (): void {
        $sbo = adminActionsSbo(['payment_status' => 'unpaid', 'payment_confirmed_at' => null, 'payment_submitted_at' => null]);

        Volt::actingAs($this->superAdmin)->test('admin.sbos.index')->call('unapprove', $sbo->id);

        expect($sbo->refresh()->payment_status)->toBe('unpaid');
    });
});

describe('suspend + delete', function (): void {
    it('suspends an SBO (inactive)', function (): void {
        $sbo = adminActionsSbo();

        Volt::actingAs($this->superAdmin)->test('admin.sbos.index')->call('suspend', $sbo->id);

        $sbo->refresh();
        expect($sbo->is_active)->toBeFalse()
            ->and($sbo->status)->toBe('suspended');
    });

    it('permanently deletes an SBO', function (): void {
        $sbo = adminActionsSbo();

        Volt::actingAs($this->superAdmin)->test('admin.sbos.index')->call('deleteSbo', $sbo->id);

        expect(Sbo::whereKey($sbo->id)->exists())->toBeFalse();
    });
});
