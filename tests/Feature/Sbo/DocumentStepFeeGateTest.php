<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ComplianceDocumentType;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Step 3 (document uploads) opens once the registration fee has been PAID —
 * either settled, or submitted and awaiting an admin's confirmation. The owner
 * does not wait for the admin to confirm before uploading; the admin reviews
 * once every step is done. A member who has made NO payment is still sent back
 * to the fee step.
 */

function feeGateMember(string $status, bool $confirmed, bool $submitted = false): array
{
    $user = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    $sbo = Sbo::create([
        'name' => 'Fee Gate ' . Str::random(4),
        'slug' => 'fee-gate-' . Str::random(6),
        'created_by' => $user->id,
        'category' => 'Software Development',
        'verification_status' => 'approved',
        'documents_status' => 'pending',
        'payment_status' => $status,
        'payment_confirmed_at' => $confirmed ? now() : null,
        'payment_submitted_at' => $submitted ? now() : null,
        'status' => 'active',
        'is_active' => true,
    ]);

    return [$user, $sbo];
}

test('an unpaid member is redirected from step 3 to the payment step', function (): void {
    [$user] = feeGateMember('unpaid', false);

    // Payment moved onto the add-business page's final step, so the fee gate
    // now sends an unpaid member there rather than to a separate fees screen.
    $target = app(App\Support\SboWorkspaceAccess::class)->paymentTarget($user);

    $this->actingAs($user)
        ->get(route('application.show', ['step' => 'documents']))
        ->assertRedirect(route('sbo.listings.create', ['pay' => $target->id]));
});

test('an unpaid member cannot upload a compliance document', function (): void {
    [$user, $sbo] = feeGateMember('unpaid', false);
    $type = ComplianceDocumentType::query()->firstOrFail();

    $this->actingAs($user)
        ->post(route('sbo.documents.store'), [
            'sbo_id' => $sbo->id,
            'compliance_document_type_id' => $type->id,
            'document' => UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf'),
        ])
        ->assertForbidden();
});

test('a settled fee (paid and confirmed, as an admin confirmation leaves it) opens step 3', function (): void {
    [$user] = feeGateMember('paid', true);

    $this->actingAs($user)
        ->get(route('application.show', ['step' => 'documents']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('account/ApplicationView')
            ->where('step', 'documents'));
});

test('a member saves their TIN number once the payment is approved', function (): void {
    [$user, $sbo] = feeGateMember('paid', true);

    $this->actingAs($user)
        ->post(route('application.tin.store'), ['tax_number' => '12345678'])
        ->assertRedirect();

    expect($sbo->fresh()->tax_number)->toBe('12345678');
});

test('the TIN number must be numeric', function (): void {
    [$user] = feeGateMember('paid', true);

    $this->actingAs($user)
        ->post(route('application.tin.store'), ['tax_number' => 'not-a-number'])
        ->assertSessionHasErrors('tax_number');
});

test('the TIN number cannot be saved before a payment is made', function (): void {
    [$user] = feeGateMember('unpaid', false);

    $this->actingAs($user)
        ->post(route('application.tin.store'), ['tax_number' => '12345678'])
        ->assertForbidden();
});

test('a submitted payment awaiting approval does not open step 3', function (): void {
    // Manual proof sets payment_submitted_at but the fee is not yet approved.
    // Documents open only once an admin approves the payment, so until then the
    // owner is held on the payment step.
    [$user] = feeGateMember('unpaid', false, submitted: true);

    $target = app(App\Support\SboWorkspaceAccess::class)->paymentTarget($user);

    $this->actingAs($user)
        ->get(route('application.show', ['step' => 'documents']))
        ->assertRedirect(route('sbo.listings.create', ['pay' => $target->id]));
});
