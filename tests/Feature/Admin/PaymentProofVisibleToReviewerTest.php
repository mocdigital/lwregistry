<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\SboPaymentProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

/*
 * A receipt an owner uploads has to reach the person who approves it.
 *
 * It did not: the SPA upload paths filed the file under the literal 'proofs'
 * while SboPaymentProof registers 'payment_proof', and every reviewer screen
 * reads the registered one through SboPaymentProof::file(). The admin page said
 * "Not uploaded" beside a fee line saying "Proof submitted — awaiting
 * confirmation", and the payment could never be approved.
 *
 * These pin the two ends together: the upload writes where the reviewer reads.
 */

beforeEach(function (): void {
    Storage::fake('compliance');
    $this->admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN->value]);
});

function payingOwner(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ])->fresh();
}

function unpaidSbo(User $owner): Sbo
{
    return Sbo::create([
        'name' => 'Kemafy LLC',
        'slug' => 'kemafy-llc-' . Illuminate\Support\Str::random(5),
        'created_by' => $owner->id,
        'status' => 'active',
        'is_active' => true,
        'verification_status' => 'pending',
        'payment_status' => 'unpaid',
    ]);
}

test('a proof uploaded from the application lands where the reviewer reads it', function (): void {
    $owner = payingOwner();
    $sbo = unpaidSbo($owner);

    $this->actingAs($owner)
        ->post(route('sbo.payments.proof.store', $sbo), [
            'proof' => UploadedFile::fake()->image('receipt.png'),
        ])
        ->assertSessionHasNoErrors();

    $proof = SboPaymentProof::where('sbo_id', $sbo->id)->firstOrFail();

    expect($proof->file())->not->toBeNull()
        // The private disk, not the public one the unregistered collection used.
        ->and($proof->file()->disk)->toBe('compliance');
});

test('the admin page offers the proof for review rather than saying Not uploaded', function (): void {
    $owner = payingOwner();
    $sbo = unpaidSbo($owner);

    $this->actingAs($owner)->post(route('sbo.payments.proof.store', $sbo), [
        'proof' => UploadedFile::fake()->image('receipt.png'),
    ]);

    Volt::actingAs($this->admin)
        ->test('admin.sbos.show', ['sbo' => $sbo->fresh()])
        ->assertSee('View proof')
        ->assertDontSee('No payment proof uploaded yet.');
});

test('the manager is labelled by what the business is', function (): void {
    $owner = payingOwner();
    $cob = unpaidSbo($owner);
    $cob->forceFill(['entity_type' => 'COB'])->save();

    Volt::actingAs($this->admin)
        ->test('admin.sbos.show', ['sbo' => $cob->fresh()])
        ->assertSee('COB Manager')
        ->assertDontSee('SBO Manager');
});
