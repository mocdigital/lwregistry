<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\SboSeal;
use App\Models\User;
use App\Support\SboSealIssuer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * The seal is earned by paying, and confirmed by verification.
 *
 * `SboSealIssuer` used to refuse anything short of full verification, so a
 * member who had paid had nothing to download while compliance reviewed their
 * documents. And the seal PNG carries only the registration number — it says
 * nothing about whose business it is — so the download is a certificate built
 * around it.
 */

beforeEach(function (): void {
    Storage::fake('public');
});

function certificateOwner(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

function certificateSbo(User $owner, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Seal SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
        'country' => 'Nigeria',
        'category' => 'Technology Services',
        'address' => '12 Adeola Odeku Street',
        'city' => 'Lagos',
    ], $overrides));
}

test('paying issues a provisional seal, before any verification', function (): void {
    $sbo = certificateSbo(certificateOwner());
    expect($sbo->isFullyVerified())->toBeFalse();

    $seal = app(SboSealIssuer::class)->issueFor($sbo);

    expect($seal->status)->toBe(SboSeal::STATUS_PROVISIONAL)
        ->and($seal->isVerified())->toBeFalse()
        ->and($seal->verified_at)->toBeNull()
        ->and($seal->existsOnDisk())->toBeTrue();
});

test('an unpaid application gets no seal at all', function (): void {
    $sbo = certificateSbo(certificateOwner(), ['payment_status' => 'unpaid', 'payment_confirmed_at' => null]);

    expect(fn () => app(SboSealIssuer::class)->issueFor($sbo))->toThrow(LogicException::class);
});

test('verification upgrades the seal and keeps the original issue date', function (): void {
    $owner = certificateOwner();
    $sbo = certificateSbo($owner);

    $provisional = app(SboSealIssuer::class)->issueFor($sbo);
    $issuedAt = $provisional->issued_at;
    $provisionalPath = $provisional->image_path;

    $sbo->forceFill([
        'verification_status' => 'approved',
        'date_approved' => now(),
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
    ])->save();

    $verified = app(SboSealIssuer::class)->issueFor($sbo->fresh());

    expect($verified->status)->toBe(SboSeal::STATUS_VERIFIED)
        ->and($verified->verified_at)->not->toBeNull()
        // The issue date is when it was paid for; upgrading must not move it.
        ->and($verified->issued_at->timestamp)->toBe($issuedAt->timestamp)
        // One seal per SBO, re-rendered rather than duplicated.
        ->and(SboSeal::where('sbo_id', $sbo->id)->count())->toBe(1)
        ->and($verified->image_path)->not->toBe($provisionalPath)
        ->and($verified->existsOnDisk())->toBeTrue();

    // The superseded provisional image is not left behind on disk.
    expect(Storage::disk('public')->exists($provisionalPath))->toBeFalse();
});

test('the profile card reports the seal, its badge and the certificate link', function (): void {
    $owner = certificateOwner();
    // Approved so the profile is reachable (the workspace is locked until
    // approval), but documents are still pending, so the seal stays provisional.
    $sbo = certificateSbo($owner, ['name' => 'Certified Ltd', 'verification_status' => 'approved']);

    $this->actingAs($owner)
        ->get(route('account.profile'))
        ->assertOk()
        ->assertInertia(function ($page) use ($sbo): void {
            $seal = $page->toArray()['props']['seal'];

            expect($seal['issued'])->toBeTrue()
                ->and($seal['status'])->toBe('provisional')
                ->and($seal['sboName'])->toBe('Certified Ltd')
                ->and($seal['certificateUrl'])->toBe(route('sbo.certificate.download', $sbo))
                ->and($seal['imageUrl'])->not->toBeNull()
                // The checklist is this application's real state: approved and
                // fees paid, documents not yet compliant (still provisional).
                ->and(collect($seal['checklist'])->firstWhere('label', 'Fees Paid')['done'])->toBeTrue()
                ->and(collect($seal['checklist'])->firstWhere('label', 'Approved')['done'])->toBeTrue()
                ->and(collect($seal['checklist'])->firstWhere('label', 'Documents')['done'])->toBeFalse();
        });
});

test('the certificate downloads as a PDF carrying the business details', function (): void {
    $owner = certificateOwner();
    $sbo = certificateSbo($owner, ['name' => 'Certified Ltd', 'website' => 'certified.example']);

    $response = $this->actingAs($owner)->get(route('sbo.certificate.download', $sbo));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');

    // dompdf's download() returns a plain Response, not a streamed one.
    $pdf = $response->getContent();
    expect(mb_strlen($pdf))->toBeGreaterThan(1000)
        ->and(mb_substr($pdf, 0, 4))->toBe('%PDF')
        /*
         * The Kingdom Commerce artwork is embedded, so the sheet is far larger
         * than the plain typographic certificate it replaced. A PDF that came
         * back without it would still be a valid PDF, just blank — which is the
         * failure this size floor catches.
         */
        ->and(mb_strlen($pdf))->toBeGreaterThan(100_000);

    // Landscape, matching the artwork's aspect ratio.
    expect($pdf)->toContain('/MediaBox');
});

test('the certificate fills in the ruled fields from the business', function (): void {
    $owner = certificateOwner();
    $sbo = certificateSbo($owner, [
        'name' => 'Certified Ltd',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
        'category' => 'Software Development',
    ]);

    $controller = new ReflectionClass(App\Http\Controllers\SboCertificateDownloadController::class);
    $fields = $controller->getMethod('fields');
    $fields->setAccessible(true);

    $seal = app(SboSealIssuer::class)->issueFor($sbo);
    $values = $fields->invoke($controller->newInstance(), $sbo->fresh(), $seal);

    expect($values['name'])->toBe('Certified Ltd')
        ->and($values['city'])->toBe('Lagos')
        ->and($values['country'])->toBe('Nigeria')
        // The sector, not the trade: the licence names the industry above it.
        ->and($values['sector'])->toBe('Technology')
        ->and($values['licenseNumber'])->toBe($seal->issued_number)
        ->and($values['status'])->toBeIn(['Verified', 'Provisional']);
});

test('a long business name is clamped rather than running over the next label', function (): void {
    $owner = certificateOwner();
    $sbo = certificateSbo($owner, [
        'name' => 'The Exceedingly Long Kingdom Enterprise Trading Company Limited',
    ]);

    $controller = new ReflectionClass(App\Http\Controllers\SboCertificateDownloadController::class);
    $fields = $controller->getMethod('fields');
    $fields->setAccessible(true);

    $seal = app(SboSealIssuer::class)->issueFor($sbo);
    $values = $fields->invoke($controller->newInstance(), $sbo->fresh(), $seal);

    // dompdf has no ellipsis, so an unclamped name prints straight through
    // "Kingdom Business ID:".
    expect(mb_strlen($values['name']))->toBeLessThanOrEqual(30)
        ->and($values['name'])->toEndWith('…');
});

test('the certificate carries a real qr code for the verification url', function (): void {
    $data = App\Support\QrCodeImage::dataUri('https://registry.test/verify/MBN-2026-0042');

    // The artwork's own QR is a picture of one; this is the code that resolves.
    expect($data)->toStartWith('data:image/png;base64,');

    $png = base64_decode(mb_substr((string) $data, mb_strlen('data:image/png;base64,')), true);
    $size = getimagesizefromstring($png);

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe($size[1]);
});

test('the certificate is owner-only and needs a settled fee', function (): void {
    $owner = certificateOwner();
    $sbo = certificateSbo($owner);

    $this->actingAs(certificateOwner())
        ->get(route('sbo.certificate.download', $sbo))
        ->assertForbidden();

    $unpaid = certificateSbo($owner, ['payment_status' => 'unpaid', 'payment_confirmed_at' => null]);

    $this->actingAs($owner)
        ->get(route('sbo.certificate.download', $unpaid))
        ->assertNotFound();
});
