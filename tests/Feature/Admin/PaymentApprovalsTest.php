<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Sbo;
use App\Models\SboPaymentProof;
use App\Models\SboPaymentTransaction;
use App\Models\User;
use App\Notifications\SboFeesPaidNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

function paymentApprovalsAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);
}

function paymentApprovalsManager(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ], $overrides));
}

function paymentApprovalsSbo(User $manager, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Payment Queue SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $manager->id,
        'primary_contact_email' => $manager->email,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
        'status' => 'active',
        'is_active' => true,
        'calculated_fee' => 250,
        'fee_band' => '1,000,001 - 10,000,000',
        'payment_reference' => 'PAY-' . Str::upper(Str::random(6)),
        'payment_submitted_at' => now(),
    ], $overrides));
}

function paymentApprovalsProof(Sbo $sbo, string $status = 'pending'): SboPaymentProof
{
    $proof = SboPaymentProof::create([
        'sbo_id' => $sbo->id,
        'status' => $status,
    ]);

    $path = tempnam(sys_get_temp_dir(), 'payment-approval-proof-');
    file_put_contents($path, '%PDF-1.4 payment approval proof');

    $proof
        ->addMedia($path)
        ->usingFileName('payment-approval-proof.pdf')
        ->toMediaCollection(SboPaymentProof::COLLECTION);

    return $proof->fresh();
}

function paymentApprovalsTransaction(Sbo $sbo, string $status = 'pending'): SboPaymentTransaction
{
    return SboPaymentTransaction::create([
        'sbo_id' => $sbo->id,
        'provider' => 'espees',
        'local_reference' => 'ESPEES-' . Str::upper(Str::random(8)),
        'provider_reference' => 'PROVIDER-' . Str::upper(Str::random(8)),
        'amount' => (int) $sbo->calculated_fee,
        'currency' => 'Espees',
        'gateway_status' => 'submitted',
        'review_status' => $status,
        'raw_payload' => ['transaction_reference' => 'PROVIDER-REF'],
        'submitted_at' => $sbo->payment_submitted_at ?? now(),
        'reviewed_at' => 'approved' === $status ? now() : null,
    ]);
}

test('payment approvals page requires approval permission', function (): void {
    Storage::fake('compliance');

    $admin = paymentApprovalsAdmin();
    $manager = paymentApprovalsManager();

    Volt::actingAs($admin)
        ->test('admin.approvals.payments')
        ->assertOk()
        ->assertSee('Review submitted SBO registration payments');

    Volt::actingAs($manager)
        ->test('admin.approvals.payments')
        ->assertForbidden();
});

test('pending payment approvals render newest first with proof links and sbo details', function (): void {
    Storage::fake('compliance');

    $admin = paymentApprovalsAdmin();
    $owner = paymentApprovalsManager();
    $olderSbo = paymentApprovalsSbo($owner, [
        'name' => 'Older Payment SBO',
        'payment_reference' => 'PAY-OLDER',
        'payment_submitted_at' => now()->subDays(2),
    ]);
    $newerSbo = paymentApprovalsSbo($owner, [
        'name' => 'Newest Payment SBO',
        'payment_reference' => 'PAY-NEWEST',
        'payment_submitted_at' => now(),
    ]);

    $olderProof = paymentApprovalsProof($olderSbo);
    $newerProof = paymentApprovalsProof($newerSbo);

    Volt::actingAs($admin)
        ->test('admin.approvals.payments')
        ->assertSeeInOrder(['Newest Payment SBO', 'Older Payment SBO'])
        ->assertSee('PAY-NEWEST')
        ->assertSee('PAY-OLDER')
        ->assertSee(route('payment-proofs.download', $newerProof), false)
        ->assertSee(route('payment-proofs.download', $olderProof), false);
});

test('approved payment approvals render Espees gateway audit submissions without actions', function (): void {
    $admin = paymentApprovalsAdmin();
    $owner = paymentApprovalsManager();
    $sbo = paymentApprovalsSbo($owner, [
        'name' => 'Espees Queue SBO',
        'payment_reference' => 'PROVIDER-QUEUE',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    paymentApprovalsTransaction($sbo, 'approved')->update(['provider_reference' => 'PROVIDER-QUEUE']);

    Volt::actingAs($admin)
        ->test('admin.approvals.payments')
        ->set('statusFilter', 'approved')
        ->assertSee('Espees Queue SBO')
        ->assertSee('Espees')
        ->assertSee('Gateway redirect received')
        ->assertSee('PROVIDER-QUEUE')
        ->assertSee('No action');
});

test('payment approvals filters show the selected proof statuses', function (): void {
    Storage::fake('compliance');

    $admin = paymentApprovalsAdmin();
    $owner = paymentApprovalsManager();
    paymentApprovalsProof(paymentApprovalsSbo($owner, ['name' => 'Pending Queue SBO']), 'pending');
    paymentApprovalsProof(paymentApprovalsSbo($owner, [
        'name' => 'Approved Queue SBO',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]), 'approved');
    paymentApprovalsProof(paymentApprovalsSbo($owner, ['name' => 'Rejected Queue SBO']), 'rejected');

    Volt::actingAs($admin)
        ->test('admin.approvals.payments')
        ->assertSee('Pending Queue SBO')
        ->assertDontSee('Approved Queue SBO')
        ->assertDontSee('Rejected Queue SBO')
        ->set('statusFilter', 'approved')
        ->assertSee('Approved Queue SBO')
        ->assertDontSee('Pending Queue SBO')
        ->set('statusFilter', 'rejected')
        ->assertSee('Rejected Queue SBO')
        ->assertDontSee('Approved Queue SBO')
        ->set('statusFilter', 'all')
        ->assertSee('Pending Queue SBO')
        ->assertSee('Approved Queue SBO')
        ->assertSee('Rejected Queue SBO');
});

test('admin can approve a payment proof from the queue', function (): void {
    Storage::fake('compliance');
    Notification::fake();

    $admin = paymentApprovalsAdmin();
    $owner = paymentApprovalsManager();
    $sbo = paymentApprovalsSbo($owner, ['name' => 'Approved Payment Queue SBO']);
    $proof = paymentApprovalsProof($sbo);

    Volt::actingAs($admin)
        ->test('admin.approvals.payments')
        ->call('openApproveModal', $proof->id)
        ->call('approveSelectedPayment')
        ->assertHasNoErrors();

    $proof->refresh();
    $sbo->refresh();

    expect($proof->status)->toBe('approved')
        ->and($proof->reviewed_by)->toBe($admin->id)
        ->and($proof->reviewed_at)->not->toBeNull()
        ->and($sbo->feesPaid())->toBeTrue();

    expect(ActivityLog::where('type', 'sbo_payment_proof_approved')->where('title', $sbo->name)->exists())->toBeTrue();

    Notification::assertSentTo($owner, SboFeesPaidNotification::class);
});

test('admin rejection from the queue requires a reason and does not mark fees paid', function (): void {
    Storage::fake('compliance');

    $admin = paymentApprovalsAdmin();
    $owner = paymentApprovalsManager();
    $sbo = paymentApprovalsSbo($owner, ['name' => 'Rejected Payment Queue SBO']);
    $proof = paymentApprovalsProof($sbo);

    Volt::actingAs($admin)
        ->test('admin.approvals.payments')
        ->call('openRejectModal', $proof->id)
        ->call('rejectSelectedPayment')
        ->assertHasErrors(['rejectionReason' => 'required'])
        ->set('rejectionReason', 'Uploaded receipt is unreadable.')
        ->call('rejectSelectedPayment')
        ->assertHasNoErrors();

    $proof->refresh();

    expect($proof->status)->toBe('rejected')
        ->and($proof->rejection_reason)->toBe('Uploaded receipt is unreadable.')
        ->and($sbo->fresh()->feesPaid())->toBeFalse();
});

test('admin can reject an Espees payment transaction from the queue', function (): void {
    $admin = paymentApprovalsAdmin();
    $owner = paymentApprovalsManager();
    $sbo = paymentApprovalsSbo($owner, ['name' => 'Rejected Espees Queue SBO']);
    $transaction = paymentApprovalsTransaction($sbo);

    Volt::actingAs($admin)
        ->test('admin.approvals.payments')
        ->call('openRejectModal', $transaction->id, 'transaction')
        ->set('rejectionReason', 'Gateway reference could not be reconciled.')
        ->call('rejectSelectedPayment')
        ->assertHasNoErrors();

    $transaction->refresh();

    expect($transaction->review_status)->toBe('rejected')
        ->and($transaction->rejection_reason)->toBe('Gateway reference could not be reconciled.')
        ->and($sbo->fresh()->feesPaid())->toBeFalse();
});

test('already paid payment proofs are not approved twice', function (): void {
    Storage::fake('compliance');
    Notification::fake();

    $admin = paymentApprovalsAdmin();
    $owner = paymentApprovalsManager();
    $sbo = paymentApprovalsSbo($owner, [
        'name' => 'Already Paid Queue SBO',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);
    $proof = paymentApprovalsProof($sbo, 'approved');
    $activityCount = ActivityLog::count();

    Volt::actingAs($admin)
        ->test('admin.approvals.payments')
        ->call('openApproveModal', $proof->id)
        ->call('approveSelectedPayment')
        ->assertHasNoErrors();

    expect(ActivityLog::count())->toBe($activityCount);
    Notification::assertNotSentTo($owner, SboFeesPaidNotification::class);
});

test('payment approval email includes confirmation details and listing instructions', function (): void {
    $owner = paymentApprovalsManager(['firstname' => 'Ada']);
    $sbo = paymentApprovalsSbo($owner, [
        'name' => 'Email Details SBO',
        'application_id' => 'MBN-2026-0912',
        'calculated_fee' => 500,
    ]);

    $mail = (string) (new SboFeesPaidNotification($sbo))->toMail($owner)->render();

    expect(str_contains($mail, 'Application ID: MBN-2026-0912'))->toBeTrue();
    expect(str_contains($mail, 'Amount confirmed: 500'))->toBeTrue();
    expect(str_contains($mail, 'set up your business listings'))->toBeTrue();
});
