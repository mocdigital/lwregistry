<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\ComplianceDocumentType;
use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

/**
 * Create a fully-verified SBO. With no active required document types (none are
 * seeded in tests), documentsCompliant() reduces to the manual flag, so a
 * verified SBO is: approved + documents_status compliant + fees paid.
 */
function verifiedSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Timeline SBO ' . Str::random(5);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => User::factory()->create(['role' => UserRole::SBO_MANAGER->value])->id,
        'verification_status' => 'approved',
        'approved_by' => User::factory()->create(['role' => UserRole::ADMIN->value])->id,
        'date_approved' => now(),
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'payment_submitted_at' => now(),
        'verified_notified_at' => now(),
        'moc_registration_number' => 'MOC-2026-0001',
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

beforeEach(function (): void {
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN->value]);
});

describe('authorization', function (): void {
    it('forbids a non-super-admin from revoking fees or verification', function (): void {
        $sbo = verifiedSbo();
        $admin = User::factory()->create(['role' => UserRole::ADMIN->value]); // has approve_sbos, not super

        Volt::actingAs($admin)->test('admin.sbos.show', ['sbo' => $sbo])->call('revokeFeesPaid')->assertForbidden();
        Volt::actingAs($admin)->test('admin.sbos.show', ['sbo' => $sbo])->call('revokeDocumentsCompliant')->assertForbidden();
        Volt::actingAs($admin)->test('admin.sbos.show', ['sbo' => $sbo])->call('revokeVerified')->assertForbidden();
        Volt::actingAs($admin)->test('admin.sbos.show', ['sbo' => $sbo])->call('confirmFeesPaid')->assertForbidden();
        Volt::actingAs($admin)->test('admin.sbos.show', ['sbo' => $sbo])->call('confirmDocumentsCompliant')->assertForbidden();
        Volt::actingAs($admin)->test('admin.sbos.show', ['sbo' => $sbo])->call('issueFinalVerification')->assertForbidden();

        expect($sbo->fresh()->feesPaid())->toBeTrue();
    });
});

describe('manual advancement controls', function (): void {
    it('lets super admins confirm payment from the timeline', function (): void {
        $sbo = verifiedSbo([
            'payment_status' => 'unpaid',
            'payment_confirmed_at' => null,
            'verified_notified_at' => null,
            'moc_registration_number' => null,
        ]);

        Volt::actingAs($this->superAdmin)->test('admin.sbos.show', ['sbo' => $sbo])
            ->assertSee('Confirm payment')
            ->call('confirmFeesPaid')
            ->assertHasNoErrors();

        expect($sbo->fresh()->feesPaid())->toBeTrue();
    });

    it('lets super admins confirm document compliance from the timeline', function (): void {
        $sbo = verifiedSbo([
            'documents_status' => 'pending',
            'documents_compliant_at' => null,
            'verified_notified_at' => null,
            'moc_registration_number' => null,
        ]);
        $type = ComplianceDocumentType::create([
            'name' => 'Timeline Required Certificate',
            'is_required' => true,
            'is_active' => true,
        ]);

        Volt::actingAs($this->superAdmin)->test('admin.sbos.show', ['sbo' => $sbo])
            ->assertSee('Mark compliant')
            ->call('confirmDocumentsCompliant')
            ->assertHasNoErrors();

        $document = SboComplianceDocument::where('sbo_id', $sbo->id)
            ->where('compliance_document_type_id', $type->id)
            ->firstOrFail();

        expect($document->status)->toBe('approved')
            ->and($sbo->fresh()->documentsCompliant())->toBeTrue();
    });

    it('lets super admins issue final verification from the timeline', function (): void {
        Storage::fake('public');
        Notification::fake();

        $sbo = verifiedSbo([
            'application_id' => 'MBN-2026-7070',
            'verified_notified_at' => null,
            'moc_registration_number' => null,
        ]);

        Volt::actingAs($this->superAdmin)->test('admin.sbos.show', ['sbo' => $sbo])
            ->assertSee('Issue final')
            ->call('issueFinalVerification')
            ->assertHasNoErrors();

        $sbo->refresh();

        expect($sbo->verified_notified_at)->not->toBeNull()
            ->and($sbo->moc_registration_number)->toStartWith('MOC-')
            ->and($sbo->seal()->exists())->toBeTrue();
    });
});

describe('revoke fees paid', function (): void {
    it('returns the SBO to the gated (unpaid) stage', function (): void {
        $sbo = verifiedSbo();

        Volt::actingAs($this->superAdmin)->test('admin.sbos.show', ['sbo' => $sbo])
            ->call('revokeFeesPaid')
            ->assertHasNoErrors();

        $sbo->refresh();
        expect($sbo->payment_status)->toBe('unpaid')
            ->and($sbo->payment_confirmed_at)->toBeNull()
            ->and($sbo->payment_submitted_at)->toBeNull()
            ->and($sbo->feesPaid())->toBeFalse();
    });

    it('cascades: a verified SBO is un-verified when fees are revoked', function (): void {
        $sbo = verifiedSbo();

        Volt::actingAs($this->superAdmin)->test('admin.sbos.show', ['sbo' => $sbo])->call('revokeFeesPaid');

        $sbo->refresh();
        expect($sbo->verified_notified_at)->toBeNull()
            ->and($sbo->moc_registration_number)->toBeNull()
            ->and($sbo->isFullyVerified())->toBeFalse()
            // Documents + registry approval are left intact.
            ->and($sbo->documents_status)->toBe('compliant')
            ->and($sbo->verification_status)->toBe('approved');
    });

    it('logs the revoke', function (): void {
        $sbo = verifiedSbo();

        Volt::actingAs($this->superAdmin)->test('admin.sbos.show', ['sbo' => $sbo])->call('revokeFeesPaid');

        expect(ActivityLog::where('type', 'sbo_fees_revoked')->where('title', $sbo->name)->exists())->toBeTrue();
    });
});

describe('revoke document compliance', function (): void {
    it('returns approved compliance documents to pending and clears final verification artifacts', function (): void {
        $sbo = verifiedSbo();
        $type = ComplianceDocumentType::create([
            'name' => 'Certificate of Incorporation',
            'is_required' => true,
            'is_active' => true,
        ]);
        $document = SboComplianceDocument::create([
            'sbo_id' => $sbo->id,
            'compliance_document_type_id' => $type->id,
            'status' => 'approved',
            'reviewed_by' => $this->superAdmin->id,
            'reviewed_at' => now(),
        ]);

        expect($sbo->fresh()->documentsCompliant())->toBeTrue();

        Volt::actingAs($this->superAdmin)->test('admin.sbos.show', ['sbo' => $sbo])
            ->call('revokeDocumentsCompliant')
            ->assertHasNoErrors();

        $sbo->refresh();
        $document->refresh();

        expect($document->status)->toBe('pending')
            ->and($document->reviewed_by)->toBeNull()
            ->and($document->reviewed_at)->toBeNull()
            ->and($sbo->documents_status)->toBe('pending')
            ->and($sbo->documents_compliant_at)->toBeNull()
            ->and($sbo->verified_notified_at)->toBeNull()
            ->and($sbo->moc_registration_number)->toBeNull()
            ->and($sbo->feesPaid())->toBeTrue()
            ->and($sbo->isApproved())->toBeTrue();

        expect(ActivityLog::where('type', 'sbo_documents_revoked')->where('title', $sbo->name)->exists())->toBeTrue();
    });
});

describe('revoke verification', function (): void {
    it('un-verifies by returning registry approval to pending, leaving fees and documents intact', function (): void {
        $sbo = verifiedSbo();

        Volt::actingAs($this->superAdmin)->test('admin.sbos.show', ['sbo' => $sbo])
            ->call('revokeVerified')
            ->assertHasNoErrors();

        $sbo->refresh();
        expect($sbo->verified_notified_at)->toBeNull()
            ->and($sbo->moc_registration_number)->toBeNull()
            ->and($sbo->isFullyVerified())->toBeFalse()
            // Verified is derived (approved + documents + fees); un-verifying breaks
            // the approval gate — reversible by re-approving. Fees and documents stay.
            ->and($sbo->feesPaid())->toBeTrue()
            ->and($sbo->documentsCompliant())->toBeTrue()
            ->and($sbo->isApproved())->toBeFalse()
            ->and($sbo->verification_status)->toBe('pending');

        expect(ActivityLog::where('type', 'sbo_verification_revoked')->where('title', $sbo->name)->exists())->toBeTrue();
    });

    it('does not revoke final verification before final artifacts are issued', function (): void {
        $sbo = verifiedSbo([
            'verified_notified_at' => null,
            'moc_registration_number' => null,
        ]);

        Volt::actingAs($this->superAdmin)->test('admin.sbos.show', ['sbo' => $sbo])
            ->call('revokeVerified')
            ->assertHasNoErrors();

        $sbo->refresh();

        expect($sbo->isApproved())->toBeTrue()
            ->and($sbo->feesPaid())->toBeTrue()
            ->and($sbo->documentsCompliant())->toBeTrue()
            ->and($sbo->verification_status)->toBe('approved');
    });
});

describe('timeline rendering', function (): void {
    it('renders the validation timeline with a super-admin revoke control', function (): void {
        $sbo = verifiedSbo();

        Volt::actingAs($this->superAdmin)->test('admin.sbos.show', ['sbo' => $sbo])
            ->assertSee('Validation timeline')
            ->assertSee('Revoke paid status')
            ->assertSee('Revoke document compliance')
            ->assertSee('Revoke final verification');
    });

    it('hides revoke controls from a non-super-admin', function (): void {
        $sbo = verifiedSbo();
        $admin = User::factory()->create(['role' => UserRole::ADMIN->value]);

        Volt::actingAs($admin)->test('admin.sbos.show', ['sbo' => $sbo])
            ->assertSee('Validation timeline')
            ->assertDontSee('Revoke paid status')
            ->assertDontSee('Revoke document compliance')
            ->assertDontSee('Revoke final verification');
    });
});
