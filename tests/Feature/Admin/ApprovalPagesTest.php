<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\ComplianceDocumentType;
use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Models\User;
use App\Notifications\SboVerifiedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

function approvalPagesAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);
}

function approvalPagesRegularUser(): User
{
    return User::factory()->create([
        'role' => UserRole::USER,
        'email_verified_at' => now(),
    ]);
}

test('approval queue pages render for admins', function (string $component, string $copy): void {
    Volt::actingAs(approvalPagesAdmin())
        ->test($component)
        ->assertOk()
        ->assertSee($copy);
})->with([
    ['admin.approvals.index', 'Track SBO validation across payment, documents, and final verification from one workflow view.'],
    ['admin.approvals.documents', 'Review uploaded SBO compliance documents'],
    ['admin.approvals.final-verification', 'Track SBOs that have application approval'],
    ['admin.approvals.info-requests', 'Track SBO applications where admins requested more information'],
    ['admin.approvals.rejected-history', 'Audit rejected applications'],
]);

test('approval queue pages reject users without approval permission', function (string $component): void {
    Volt::actingAs(approvalPagesRegularUser())
        ->test($component)
        ->assertForbidden();
})->with([
    ['admin.approvals.index'],
    ['admin.approvals.documents'],
    ['admin.approvals.final-verification'],
    ['admin.approvals.info-requests'],
    ['admin.approvals.rejected-history'],
]);

test('legacy approval routes redirect to the merged validation workflow', function (string $route): void {
    $this->actingAs(approvalPagesAdmin())
        ->get(route($route))
        ->assertRedirect(route('admin.approvals.index'));
})->with([
    ['admin.approvals.payments'],
    ['admin.approvals.documents'],
    ['admin.approvals.final-verification'],
]);

test('merged approval workflow shows high-level validation stages', function (): void {
    $owner = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    $sbo = Sbo::create([
        'name' => 'Merged Approval Timeline SBO',
        'slug' => 'merged-approval-timeline-sbo-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'approved_by' => approvalPagesAdmin()->id,
        'date_approved' => now(),
        'documents_status' => 'pending',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);

    Volt::actingAs(approvalPagesAdmin())
        ->test('admin.approvals.index')
        // The Payments/Documents queues now only list owners who have paid /
        // submitted docs; this one has done neither, so view it via "All".
        ->set('stageFilter', 'all')
        ->assertOk()
        ->assertSee($sbo->name)
        ->assertSee('Registry')
        ->assertSee('Payment')
        ->assertSee('Documents')
        // "Final" and "Verified" were merged into a single "Verified" stage.
        ->assertSee('Verified')
        ->assertSee('Document approval');
});

test('the payments queue lists only owners who have actually paid', function (): void {
    $owner = User::factory()->create(['role' => UserRole::SBO_MANAGER, 'email_verified_at' => now()]);

    // Approved but has not paid and submitted nothing — nothing to review.
    Sbo::create([
        'name' => 'Awaiting Fee SBO', 'slug' => 'awaiting-fee-' . Str::random(5),
        'created_by' => $owner->id, 'verification_status' => 'approved',
        'status' => 'active', 'is_active' => true, 'payment_status' => 'unpaid',
    ]);

    // Approved and has submitted a payment awaiting confirmation — reviewable.
    Sbo::create([
        'name' => 'Fee Submitted SBO', 'slug' => 'fee-submitted-' . Str::random(5),
        'created_by' => $owner->id, 'verification_status' => 'approved',
        'status' => 'active', 'is_active' => true, 'payment_status' => 'unpaid',
        'payment_submitted_at' => now(),
    ]);

    Volt::actingAs(approvalPagesAdmin())
        ->test('admin.approvals.index')
        ->set('stageFilter', 'payment')
        ->assertSee('Fee Submitted SBO')
        ->assertDontSee('Awaiting Fee SBO');
});

test('the documents queue excludes owners who have not submitted documents', function (): void {
    $owner = User::factory()->create(['role' => UserRole::SBO_MANAGER, 'email_verified_at' => now()]);

    // Approved and paid, but no documents uploaded — nothing to review yet.
    Sbo::create([
        'name' => 'No Docs SBO', 'slug' => 'no-docs-' . Str::random(5),
        'created_by' => $owner->id, 'verification_status' => 'approved',
        'status' => 'active', 'is_active' => true,
        'payment_status' => 'paid', 'payment_confirmed_at' => now(),
        'documents_status' => 'pending',
    ]);

    Volt::actingAs(approvalPagesAdmin())
        ->test('admin.approvals.index')
        ->set('stageFilter', 'documents')
        ->assertDontSee('No Docs SBO');

    // It is still reachable under "All".
    Volt::actingAs(approvalPagesAdmin())
        ->test('admin.approvals.index')
        ->set('stageFilter', 'all')
        ->assertSee('No Docs SBO');
});

test('merged approval workflow lets super admins revoke a selected validation step', function (): void {
    $admin = approvalPagesAdmin();
    $owner = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    $sbo = Sbo::create([
        'name' => 'Merged Revoke SBO',
        'slug' => 'merged-revoke-sbo-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'approved_by' => $admin->id,
        'date_approved' => now(),
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'verified_notified_at' => now(),
        'moc_registration_number' => 'MOC-2026-8080',
        'status' => 'active',
        'is_active' => true,
    ]);

    Volt::actingAs($admin)
        ->test('admin.approvals.index')
        ->call('revokeStep', $sbo->id, 'documents')
        ->assertHasNoErrors()
        ->assertSee('Document compliance revoked');

    $sbo->refresh();

    expect($sbo->documents_status)->toBe('pending')
        ->and($sbo->documents_compliant_at)->toBeNull()
        ->and($sbo->verified_notified_at)->toBeNull()
        ->and($sbo->moc_registration_number)->toBeNull()
        ->and($sbo->feesPaid())->toBeTrue()
        ->and($sbo->isApproved())->toBeTrue();

    expect(ActivityLog::where('type', 'sbo_documents_revoked')->where('title', $sbo->name)->exists())->toBeTrue();
});

test('merged approval workflow can manually confirm payment for testing', function (): void {
    $admin = approvalPagesAdmin();
    $owner = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    $sbo = Sbo::create([
        'name' => 'Manual Payment Confirm SBO',
        'slug' => 'manual-payment-confirm-sbo-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'approved_by' => $admin->id,
        'date_approved' => now(),
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
        'status' => 'active',
        'is_active' => true,
    ]);

    Volt::actingAs($admin)
        ->test('admin.approvals.index')
        ->call('confirmStep', $sbo->id, 'fees')
        ->assertHasNoErrors()
        ->assertSee('Payment confirmed');

    $sbo->refresh();

    expect($sbo->feesPaid())->toBeTrue()
        ->and($sbo->payment_confirmed_at)->not->toBeNull();

    expect(ActivityLog::where('type', 'sbo_fees_paid_manual_override')->where('title', $sbo->name)->exists())->toBeTrue();
});

test('merged approval workflow can manually confirm document compliance for testing', function (): void {
    $admin = approvalPagesAdmin();
    $owner = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
    $type = ComplianceDocumentType::create([
        'name' => 'Testing Certificate',
        'is_required' => true,
        'is_active' => true,
    ]);

    $sbo = Sbo::create([
        'name' => 'Manual Documents Confirm SBO',
        'slug' => 'manual-documents-confirm-sbo-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'approved_by' => $admin->id,
        'date_approved' => now(),
        'documents_status' => 'pending',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);

    Volt::actingAs($admin)
        ->test('admin.approvals.index')
        ->call('confirmStep', $sbo->id, 'documents')
        ->assertHasNoErrors()
        ->assertSee('Document compliance confirmed');

    $sbo->refresh();
    $document = SboComplianceDocument::where('sbo_id', $sbo->id)
        ->where('compliance_document_type_id', $type->id)
        ->firstOrFail();

    expect($document->status)->toBe('approved')
        ->and($document->reviewed_by)->toBe($admin->id)
        ->and($sbo->documentsCompliant())->toBeTrue()
        ->and($sbo->documents_status)->toBe('compliant')
        ->and($sbo->documents_compliant_at)->not->toBeNull();

    expect(ActivityLog::where('type', 'sbo_documents_compliant_manual_override')->where('title', $sbo->name)->exists())->toBeTrue();
});

test('merged approval workflow can issue final verification for testing', function (): void {
    Storage::fake('public');
    Notification::fake();

    $admin = approvalPagesAdmin();
    $owner = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    $sbo = Sbo::create([
        'name' => 'Manual Final Verification SBO',
        'slug' => 'manual-final-verification-sbo-' . Str::random(5),
        'created_by' => $owner->id,
        'application_id' => 'MBN-2026-9090',
        'verification_status' => 'approved',
        'approved_by' => $admin->id,
        'date_approved' => now(),
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'verified_notified_at' => null,
        'moc_registration_number' => null,
        'status' => 'active',
        'is_active' => true,
    ]);

    Volt::actingAs($admin)
        ->test('admin.approvals.index')
        ->call('confirmStep', $sbo->id, 'verified')
        ->assertHasNoErrors()
        ->assertSee('Final verification issued');

    $sbo->refresh();
    $seal = $sbo->seal()->first();

    expect($sbo->verified_notified_at)->not->toBeNull()
        ->and($sbo->moc_registration_number)->toStartWith('MOC-')
        ->and($seal)->not->toBeNull()
        ->and($seal->issued_number)->toBe('MBN-2026-9090');

    Notification::assertSentTo($owner, SboVerifiedNotification::class);
});
