<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use App\Notifications\SboVerifiedNotification;
use App\Support\SboVerificationFinalizer;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function sealOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ], $overrides));
}

function sealSbo(User $owner, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Seal Ready SBO ' . Str::random(5);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'application_id' => 'MBN-2026-0042',
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

test('full verification issues a personalized digital seal and attaches it to the final email', function (): void {
    Storage::fake('public');
    Notification::fake();

    $owner = sealOwner(['firstname' => 'Seal']);
    $sbo = sealSbo($owner);

    app(SboVerificationFinalizer::class)->finalizeIfReady($sbo);

    $sbo->refresh();
    $seal = $sbo->seal()->first();

    expect($seal)->not->toBeNull()
        ->and($seal->issued_number)->toBe('MBN-2026-0042')
        ->and($seal->issued_year)->toBe('2026')
        ->and($seal->serial_number)->toBe('0042')
        ->and($sbo->verified_notified_at)->not->toBeNull();

    Storage::disk('public')->assertExists($seal->image_path);
    expect(Storage::disk('public')->get($seal->image_path))->toStartWith("\x89PNG");

    Notification::assertSentTo(
        $owner,
        SboVerifiedNotification::class,
        function (SboVerifiedNotification $notification) use ($owner, $seal): bool {
            $mail = $notification->toMail($owner);

            expect($mail->introLines)->toContain('Your personalized Loveworld Registry digital seal is attached to this email and is also available for download from your profile.')
                ->and($mail->attachments)->toHaveCount(1)
                ->and($mail->attachments[0]['options']['as'])->toBe($seal->downloadName())
                ->and($mail->attachments[0]['options']['mime'])->toBe('image/png');

            return true;
        }
    );
});

test('owners can download their issued seal but unrelated users cannot', function (): void {
    Storage::fake('public');
    Notification::fake();

    $owner = sealOwner();
    $sbo = sealSbo($owner);
    app(SboVerificationFinalizer::class)->finalizeIfReady($sbo);
    $seal = $sbo->fresh('seal')->seal;

    $this->actingAs($owner)
        ->get(route('sbo.seals.download', $seal))
        ->assertOk()
        ->assertDownload($seal->downloadName());

    Storage::disk('public')->delete($seal->image_path);
    Storage::disk('public')->assertMissing($seal->image_path);

    $preview = $this->get(route('sbo.seals.image', $seal))
        ->assertOk();

    expect($preview->headers->get('content-type'))->toContain('image/png')
        ->and($preview->headers->get('content-disposition'))->toContain('inline')
        ->and($preview->streamedContent())->toStartWith("\x89PNG");

    Storage::disk('public')->assertExists($seal->image_path);

    $otherUser = User::factory()->create(['role' => UserRole::SBO_MANAGER]);

    $this->actingAs($otherUser)
        ->get(route('sbo.seals.download', $seal))
        ->assertForbidden();
});

test('profile and public verification pages surface the generated seal', function (): void {
    Storage::fake('public');
    Notification::fake();

    $owner = sealOwner();
    $sbo = sealSbo($owner, ['name' => 'Public Seal Ministry SBO']);

    app(SboVerificationFinalizer::class)->finalizeIfReady($sbo);

    $sbo->refresh();
    $seal = $sbo->seal()->firstOrFail();

    $response = $this->actingAs($owner)
        ->get(route('account.profile'))
        ->assertOk();

    /*
     * The profile's Digital SBO Seal card. It was hardcoded to "No seal issued
     * yet" with three static ticks, so it said the same thing to a fully
     * verified business as to a brand new one; these are the props that give it
     * the three states it actually has.
     */
    $response->assertInertia(fn ($page) => $page
        ->where('seal.issued', true)
        ->where('seal.status', $seal->status)
        ->where('seal.sboName', 'Public Seal Ministry SBO')
        ->where('seal.number', $seal->issued_number)
        // Payment issues the seal; verification re-badges it. Both states
        // offer the certificate, which is what the download button opens.
        ->where('seal.certificateUrl', route('sbo.certificate.download', $sbo)));

    expect(inertiaProp($response, 'seal.imageUrl'))->toStartWith('data:image/png;base64,');

    expect(inertiaPluck($response, 'seal.checklist', 'done')->all())
        ->toBe([true, true, true, true]);

    $this->get(route('sbo.verify', $sbo->moc_registration_number))
        ->assertOk()
        ->assertSee('Valid SBO Registration')
        ->assertSee('Personalized Digital Seal')
        ->assertSee('MBN-2026-0042');
});
