<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\SboPaymentProofUpload;
use App\Models\Sbo;
use App\Models\SboPaymentProof;
use App\Models\User;
use App\Notifications\AdminSboPaymentSuccessfulNotification;
use App\Notifications\SboFeesPaidNotification;
use App\Notifications\SboVerifiedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

function paymentProofManager(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ], $overrides));
}

function paymentProofAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);
}

function paymentProofSbo(User $manager, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Payment Proof SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $manager->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'unpaid',
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

function attachPaymentProofFile(SboPaymentProof $proof): void
{
    $path = tempnam(sys_get_temp_dir(), 'payment-proof-');
    file_put_contents($path, '%PDF-1.4 payment proof');

    $proof
        ->addMedia($path)
        ->usingFileName('payment-proof.pdf')
        ->toMediaCollection(SboPaymentProof::COLLECTION);
}

test('sbo owner can upload proof of payment from the progress workflow', function (): void {
    Storage::fake('compliance');

    $manager = paymentProofManager();
    $sbo = paymentProofSbo($manager);

    Livewire::actingAs($manager)
        ->test(SboPaymentProofUpload::class, ['sboId' => $sbo->id])
        ->set('proof', UploadedFile::fake()->image('receipt.jpg'))
        ->call('upload')
        ->assertHasNoErrors()
        ->assertDispatched('payment-proof-uploaded')
        ->assertDispatched('ui-toast')
        ->assertSet('proof', null);

    $proof = SboPaymentProof::where('sbo_id', $sbo->id)->first();

    expect($proof)->not->toBeNull();
    expect($proof->status)->toBe('pending');
    expect($proof->file())->not->toBeNull();
    Storage::disk('compliance')->assertExists($proof->file()->getPathRelativeToRoot());
});

test('sbo owner can upload a png proof of payment', function (): void {
    Storage::fake('compliance');

    $manager = paymentProofManager();
    $sbo = paymentProofSbo($manager);

    Livewire::actingAs($manager)
        ->test(SboPaymentProofUpload::class, ['sboId' => $sbo->id])
        ->set('proof', UploadedFile::fake()->image('receipt.png'))
        ->call('upload')
        ->assertHasNoErrors()
        ->assertDispatched('ui-toast');

    $proof = SboPaymentProof::where('sbo_id', $sbo->id)->firstOrFail();

    expect($proof->file())->not->toBeNull();
    Storage::disk('compliance')->assertExists($proof->file()->getPathRelativeToRoot());
});

test('admin can reject proof of payment with a visible reason', function (): void {
    Storage::fake('compliance');

    $manager = paymentProofManager();
    $admin = paymentProofAdmin();
    $sbo = paymentProofSbo($manager);
    $proof = SboPaymentProof::create(['sbo_id' => $sbo->id, 'status' => 'pending']);
    attachPaymentProofFile($proof);

    Livewire::actingAs($admin)
        ->test('admin.sbos.show', ['sbo' => $sbo])
        ->set('paymentProofRejectReason', 'Receipt amount does not match.')
        ->call('rejectPaymentProof', $proof->id)
        ->assertHasNoErrors();

    $proof->refresh();

    expect($proof->status)->toBe('rejected');
    expect($proof->rejection_reason)->toBe('Receipt amount does not match.');
    expect($sbo->fresh()->feesPaid())->toBeFalse();
});

test('admin approval of proof of payment completes verification and sends final email', function (): void {
    Storage::fake('compliance');
    Storage::fake('public');
    Notification::fake();

    $manager = paymentProofManager();
    $admin = paymentProofAdmin();
    $sbo = paymentProofSbo($manager, ['moc_registration_number' => null]);
    $proof = SboPaymentProof::create(['sbo_id' => $sbo->id, 'status' => 'pending']);
    attachPaymentProofFile($proof);

    Livewire::actingAs($admin)
        ->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('approvePaymentProof', $proof->id)
        ->assertHasNoErrors();

    $sbo->refresh();
    $proof->refresh();

    expect($proof->status)->toBe('approved');
    expect($sbo->feesPaid())->toBeTrue();
    expect($sbo->isFullyVerified())->toBeTrue();
    expect($sbo->moc_registration_number)->toStartWith('MOC-');
    expect($sbo->verified_notified_at)->not->toBeNull();

    Notification::assertSentTo($manager, SboFeesPaidNotification::class);
    Notification::assertSentTo($admin, AdminSboPaymentSuccessfulNotification::class);
    Notification::assertSentTo($manager, SboVerifiedNotification::class);
});

test('public validation page validates only fully verified moc registration numbers', function (): void {
    $manager = paymentProofManager();
    $verified = paymentProofSbo($manager, [
        'name' => 'Validated Ministry SBO',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'moc_registration_number' => 'MOC-2026-9999',
        'verified_notified_at' => now(),
    ]);

    $this->get(route('sbo.verify', $verified->moc_registration_number))
        ->assertOk()
        ->assertSee('Valid SBO Registration')
        ->assertSee('Validated Ministry SBO')
        ->assertSee('MOC-2026-9999');

    $pending = paymentProofSbo($manager, [
        'moc_registration_number' => 'MOC-2026-0002',
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
    ]);

    $this->get(route('sbo.verify', $pending->moc_registration_number))
        ->assertOk()
        ->assertSee('Registration Not Validated')
        ->assertDontSee($pending->name);
});
