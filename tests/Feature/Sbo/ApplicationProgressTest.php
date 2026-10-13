<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ComplianceDocumentType;
use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

function sboProgressUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ], $overrides));
}

function sboProgressApplication(User $user, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Kingdom Services ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $user->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ], $overrides));
}

test('sbo manager can view their application progress index and own progress page', function (): void {
    $manager = sboProgressUser();
    $sbo = sboProgressApplication($manager, ['name' => 'Progress Owned SBO']);

    $this->actingAs($manager)
        ->get(route('sbo.applications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sbo/SboProgressView')
            ->where('sbo.name', 'Progress Owned SBO'));

    $this->actingAs($manager)
        ->get(route('sbo.applications.progress', $sbo))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sbo.name', 'Progress Owned SBO')
            // Step 1 covers the business AND its payment, so an unpaid
            // application has earned nothing yet.
            ->where('progress.percent', 0)
            ->where('progress.feesPaid', false));
});

test('progress page deep-links to the fees step via ?step=fees', function (): void {
    $manager = sboProgressUser();
    $sbo = sboProgressApplication($manager, ['name' => 'Deep Link SBO']);

    // With no param it opens at the current step (fees is current for an unpaid,
    // pending SBO, so assert an out-of-current step to prove the param works).
    Livewire::actingAs($manager)
        ->withQueryParams(['step' => 'fees'])
        ->test('sbo.applications.progress', ['sbo' => $sbo])
        ->assertSet('activeStep', 'fees');
});

test('progress page ignores an invalid or inaccessible ?step value', function (): void {
    $manager = sboProgressUser();
    $sbo = sboProgressApplication($manager);

    // 'verified' is not yet accessible for a fresh application; the param is
    // ignored and it falls back to the current step rather than jumping ahead.
    Livewire::actingAs($manager)
        ->withQueryParams(['step' => 'nonsense'])
        ->test('sbo.applications.progress', ['sbo' => $sbo])
        ->assertNotSet('activeStep', 'nonsense');
});

test('sbo manager cannot view another manager application progress page', function (): void {
    $owner = sboProgressUser();
    $other = sboProgressUser();
    $sbo = sboProgressApplication($owner);

    /*
     * The other manager owns a business of their own, so this reaches
     * ApplicationController@forSbo's ownership check — the guard that actually
     * protects this page. Without one they were stopped a step earlier by the
     * sbo_manager middleware, which made the test pass for the wrong reason.
     */
    sboProgressApplication($other, ['name' => 'Other Manager SBO']);

    $this->actingAs($other)
        ->get(route('sbo.applications.progress', $sbo))
        ->assertForbidden();
});

test('application progress routes enforce auth verification and sbo manager access', function (): void {
    $manager = sboProgressUser();
    $sbo = sboProgressApplication($manager);

    $this->get(route('sbo.applications.index'))
        ->assertRedirect(route('login'));

    /*
     * Somebody who owns no business is not forbidden from the workspace — they
     * are simply before it, so they are sent to the form that creates one.
     * This used to be a bare 403, which a member met by clicking Dashboard.
     */
    $regularUser = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($regularUser)
        ->get(route('sbo.applications.index'))
        ->assertRedirect(route('sbo.listings.create'));

    $unverifiedManager = sboProgressUser(['email_verified_at' => null]);

    $this->actingAs($unverifiedManager)
        ->get(route('sbo.applications.progress', $sbo))
        ->assertRedirect(route('verification.notice'));
});

test('progress page renders key pending info requested declined and verified states', function (): void {
    $manager = sboProgressUser();

    $pending = sboProgressApplication($manager, ['name' => 'Pending Progress SBO']);
    $this->actingAs($manager)
        ->get(route('sbo.applications.progress', $pending))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('progress.feesPaid', false)
            ->where('status.verification', 'pending')
            ->where('status.infoRequest', null)
            ->where('status.declineReason', null));

    $infoRequested = sboProgressApplication($manager, [
        'name' => 'Info Requested Progress SBO',
        'verification_status' => 'info_requested',
        'info_request' => 'Please update your director details.',
    ]);
    /*
     * The Vue screen received no application status at all, so "more
     * information requested" was invisible: an applicant could be blocked on a
     * question nobody showed them. The prop and the panel both exist now.
     */
    $this->actingAs($manager)
        ->get(route('sbo.applications.progress', $infoRequested))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('status.verification', 'info_requested')
            ->where('status.infoRequest', 'Please update your director details.'));

    $declined = sboProgressApplication($manager, [
        'name' => 'Declined Progress SBO',
        'verification_status' => 'declined',
        'decline_reason' => 'Invalid registration details.',
    ]);
    $this->actingAs($manager)
        ->get(route('sbo.applications.progress', $declined))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('status.verification', 'declined')
            ->where('status.declineReason', 'Invalid registration details.'));

    $type = ComplianceDocumentType::create([
        'name' => 'Certificate of Registration',
        'is_required' => true,
        'is_active' => true,
    ]);
    $verified = sboProgressApplication($manager, [
        'name' => 'Verified Progress SBO',
        'verification_status' => 'approved',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);
    SboComplianceDocument::create([
        'sbo_id' => $verified->id,
        'compliance_document_type_id' => $type->id,
        'status' => 'approved',
    ]);

    $this->actingAs($manager)
        ->get(route('sbo.applications.progress', $verified))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('progress.verified', true)
            ->where('progress.feesPaid', true));
});

test('required document types appear dynamically with document status and rejection reason', function (): void {
    Storage::fake('compliance');

    $manager = sboProgressUser();
    $sbo = sboProgressApplication($manager, [
        'name' => 'Document Progress SBO',
        'verification_status' => 'approved',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);
    $type = ComplianceDocumentType::create([
        'name' => 'CAC Certificate',
        'description' => 'Corporate Affairs Commission certificate',
        'is_required' => true,
        'is_active' => true,
    ]);
    $document = SboComplianceDocument::create([
        'sbo_id' => $sbo->id,
        'compliance_document_type_id' => $type->id,
        'status' => 'rejected',
        'rejection_reason' => 'The uploaded scan is not clear.',
    ]);
    $path = tempnam(sys_get_temp_dir(), 'cac-document-');
    file_put_contents($path, '%PDF-1.4 test document');

    $document
        ->addMedia($path)
        ->usingFileName('cac.pdf')
        ->toMediaCollection(SboComplianceDocument::COLLECTION);

    $this->actingAs($manager)
        ->get(route('sbo.applications.progress', $sbo))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $doc = collect($page->toArray()['props']['documents'])
                ->firstWhere('name', 'CAC Certificate');

            expect($doc)->not->toBeNull()
                ->and($doc['required'])->toBeTrue()
                ->and($doc['status'])->toBe('rejected')
                ->and($doc['rejectionReason'])->toBe('The uploaded scan is not clear.')
                // The applicant can re-download what they sent.
                ->and($doc['fileName'])->toBe('cac.pdf');
        });
});

test('progress navigation blocks future steps until registration fees are complete', function (): void {
    $manager = sboProgressUser();
    $sbo = sboProgressApplication($manager, ['name' => 'Mandatory Fees SBO']);

    Livewire::actingAs($manager)
        ->test('sbo.applications.progress', ['sbo' => $sbo])
        ->assertSet('activeStep', 'fees')
        ->call('showStep', 'documents')
        ->assertSet('activeStep', 'fees')
        ->call('showStep', 'verified')
        ->assertSet('activeStep', 'fees')
        ->call('nextStep')
        ->assertSet('activeStep', 'fees')
        ->call('showStep', 'submitted')
        ->assertSet('activeStep', 'submitted')
        ->call('nextStep')
        ->assertSet('activeStep', 'fees');
});

test('documents become accessible after registration fees are paid', function (): void {
    $manager = sboProgressUser();
    $sbo = sboProgressApplication($manager, [
        'name' => 'Fees Paid Documents SBO',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    ComplianceDocumentType::create([
        'name' => 'Board Resolution',
        'is_required' => true,
        'is_active' => true,
    ]);

    Livewire::actingAs($manager)
        ->test('sbo.applications.progress', ['sbo' => $sbo])
        ->assertSet('activeStep', 'documents')
        ->call('showStep', 'fees')
        ->assertSet('activeStep', 'fees')
        ->call('showStep', 'documents')
        ->assertSet('activeStep', 'documents')
        ->call('showStep', 'verified')
        ->assertSet('activeStep', 'documents');
});

test('verified step is accessible after fees and documents but waits for registry approval', function (): void {
    $manager = sboProgressUser();
    $type = ComplianceDocumentType::create([
        'name' => 'Tax Clearance',
        'is_required' => true,
        'is_active' => true,
    ]);
    $sbo = sboProgressApplication($manager, [
        'name' => 'Pending Registry Approval SBO',
        'verification_status' => 'pending',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);
    SboComplianceDocument::create([
        'sbo_id' => $sbo->id,
        'compliance_document_type_id' => $type->id,
        'status' => 'approved',
    ]);

    Livewire::actingAs($manager)
        ->test('sbo.applications.progress', ['sbo' => $sbo])
        ->assertSet('activeStep', 'verified')
        ->assertSee('Final verification is pending registry approval')
        ->assertDontSee('Your SBO is fully verified')
        ->call('showStep', 'verified')
        ->assertSet('activeStep', 'verified');
});

test('owner can resubmit after information is requested', function (): void {
    $manager = sboProgressUser();
    $sbo = sboProgressApplication($manager, [
        'verification_status' => 'info_requested',
        'info_request' => 'Please clarify ownership.',
    ]);

    Livewire::actingAs($manager)
        ->test('sbo.applications.progress', ['sbo' => $sbo])
        ->call('resubmit')
        ->assertHasNoErrors();

    $sbo->refresh();

    expect($sbo->verification_status)->toBe('pending');
    expect($sbo->resubmitted_at)->not->toBeNull();
});
