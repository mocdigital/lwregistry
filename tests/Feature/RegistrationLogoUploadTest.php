<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Country;
use App\Models\Ministry;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;

/*
 * The logo an owner uploads while adding a business.
 *
 * Registration no longer creates a business — it creates a member account, and
 * the business (with its logo) is added afterwards from the workspace. So the
 * logo now belongs to the "Add your business" step (sbo.listings.store), and
 * these cover it there. Optional on purpose: blocking on a file somebody has to
 * go and find costs more registrations than it gains logos.
 */

beforeEach(function (): void {
    Notification::fake();
    Category::query()->firstOrCreate(['name' => 'Technology Services'], ['slug' => 'technology-services']);
    Country::query()->firstOrCreate(['name' => 'Nigeria'], ['code' => 'NG']);
    Ministry::query()->firstOrCreate(['name' => 'Healing School'], ['slug' => 'healing-school', 'is_active' => true]);
});

function businessOwner(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ])->fresh();
}

function listingLogoPayload(array $overrides = []): array
{
    return array_merge([
        // Saving a business IS paying for it now; manual proof needs no gateway.
        'payWith' => 'manual',
        'proof' => UploadedFile::fake()->image('receipt.png'),
        'bizName' => 'Radiant Logistics',
        'bizEntityType' => 'SBO',
        'bizMinistry' => 'Healing School',
        'bizRegistrationNumber' => 'RC-482913',
        'bizDescription' => 'We move freight across West Africa.',
        'bizLegalStructure' => 'Private Limited Company (Ltd)',
        'bizNature' => 'Technology Services',
        'bizAddress' => '12 Adeola Odeku Street',
        'bizState' => 'Lagos',
        'bizCountry' => 'Nigeria',
        'bizEmail' => 'hello@radiant.test',
        'bizPhone' => '+2348031234567',
        'agreeCode' => true,
    ], $overrides);
}

test('a logo uploaded at business setup lands on the listing', function (): void {
    $this->actingAs(businessOwner())
        ->post(route('sbo.listings.store'), listingLogoPayload([
            'bizLogo' => UploadedFile::fake()->image('logo.png', 400, 400),
        ]))->assertRedirect();

    $sbo = Sbo::query()->where('name', 'Radiant Logistics')->firstOrFail();

    expect($sbo->getMedia(Sbo::LOGO_COLLECTION))->toHaveCount(1)
        ->and($sbo->logoUrl())->not->toBeNull();
});

test('business setup completes without a logo', function (): void {
    $this->actingAs(businessOwner())
        ->post(route('sbo.listings.store'), listingLogoPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $sbo = Sbo::query()->where('name', 'Radiant Logistics')->firstOrFail();

    expect($sbo->getMedia(Sbo::LOGO_COLLECTION))->toHaveCount(0)
        // The cards fall back to their stock artwork rather than a broken image.
        ->and($sbo->logoUrl())->toBeNull();
});

test('a file that is not an image is refused, and no business is created', function (): void {
    $this->actingAs(businessOwner())
        ->post(route('sbo.listings.store'), listingLogoPayload([
            'bizLogo' => UploadedFile::fake()->create('accounts.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('bizLogo');

    expect(Sbo::query()->where('name', 'Radiant Logistics')->exists())->toBeFalse();
});

test('a logo over 5MB is refused', function (): void {
    $this->actingAs(businessOwner())
        ->post(route('sbo.listings.store'), listingLogoPayload([
            'bizLogo' => UploadedFile::fake()->image('huge.png')->size(6000),
        ]))->assertSessionHasErrors('bizLogo');
});
