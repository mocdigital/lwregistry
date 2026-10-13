<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\AuthModal;
use App\Models\Category;
use App\Models\Country;
use App\Models\Sbo;
use App\Models\User;
use App\Notifications\AdminNewSboNotification;
use App\Notifications\SboRegistrationReceivedNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

function validJoinFormData(array $overrides = []): array
{
    return array_merge([
        'bizName' => 'Kingdom Tech Services',
        'bizLegalStructure' => 'Private Limited Company (Ltd)',
        'bizAddress' => '10 Registry Road',
        'bizState' => 'Lagos',
        'bizPhone' => '+2348012345678',
        'bizEmail' => 'hello@kingdomtech.example',
        'bizWebsite' => 'kingdomtech.example',
        'bizSocials' => [
            ['platform' => 'Instagram', 'handle' => '@kingdomtech'],
        ],
        'bizNature' => Category::query()->value('name'),
        'bizYearEstablished' => '2024',
        'bizEmployees' => '12',
        'bizCountry' => Country::query()->where('name', 'Nigeria')->value('name') ?? Country::query()->value('name'),
        'bizPremiseType' => 'both',
        'ownerSurname' => 'Doe',
        'ownerFirstName' => 'Jane',
        'ownerCountry' => Country::query()->where('name', 'Nigeria')->value('name') ?? Country::query()->value('name'),
        'ownerResidentialAddress' => '20 Owner Street',
        'ownerKingschat' => 'janedoe',
        'ownerPhone' => '+2348023456789',
        'ownerEmail' => 'jane.doe@example.com',
        'ownerChurchZone' => 'Lagos Zone 1',
        'ownerLocalAssembly' => 'Central Assembly',
        'ownerYearsAsMember' => '5',
        'ownerZonalPastor' => 'Pastor Example',
        'ownerLocalPastor' => 'Pastor Local',
        'bizPassword' => 'Password123',
        'bizPasswordConfirmation' => 'Password123',
        'bizAgreeTerms' => true,
        'bizAgreeCode' => true,
    ], $overrides);
}

test('join form creates an sbo manager and normalizes website before saving', function (): void {
    Notification::fake();

    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'email' => 'admin@example.test',
    ]);

    Livewire::test(AuthModal::class)
        ->set(validJoinFormData())
        ->call('registerBusiness')
        ->assertHasNoErrors()
        ->assertRedirect(route('post-login'));

    assertDatabaseHas('users', [
        'email' => 'jane.doe@example.com',
        'role' => UserRole::SBO_MANAGER->value,
    ]);

    assertDatabaseHas('sbos', [
        'name' => 'Kingdom Tech Services',
        'website' => 'https://kingdomtech.example',
        'country' => 'Nigeria',
        'owner_country' => 'Nigeria',
        'legal_structure' => 'Private Limited Company (Ltd)',
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
        'payment_status' => 'unpaid',
        'documents_status' => 'pending',
        'approved_by' => null,
        'moc_registration_number' => null,
    ]);

    $owner = User::where('email', 'jane.doe@example.com')->firstOrFail();
    $sbo = Sbo::where('name', 'Kingdom Tech Services')->firstOrFail();

    expect($owner->role)->toBe(UserRole::SBO_MANAGER);
    expect($sbo->date_approved)->not->toBeNull();
    expect($sbo->feesPaid())->toBeFalse();
    expect($sbo->isFullyVerified())->toBeFalse();

    // At registration the member gets the verification email and nothing else.
    // The welcome/next-steps email waits until the address is verified.
    Notification::assertSentTo($owner, App\Notifications\VerifyEmailQueued::class);
    Notification::assertNotSentTo($owner, SboRegistrationReceivedNotification::class);
    Notification::assertNotSentTo($admin, AdminNewSboNotification::class);
});

test('the welcome email is sent only once the owner verifies their address', function (): void {
    Notification::fake();

    Livewire::test(AuthModal::class)
        ->set(validJoinFormData())
        ->call('registerBusiness')
        ->assertHasNoErrors();

    $owner = User::where('email', 'jane.doe@example.com')->firstOrFail();

    // Not yet — the address is unverified.
    Notification::assertNotSentTo($owner, SboRegistrationReceivedNotification::class);

    // Verifying the address triggers the welcome — exactly once, so a member
    // does not receive duplicate welcomes.
    $owner->markEmailAsVerified();
    event(new Illuminate\Auth\Events\Verified($owner));

    Notification::assertSentToTimes($owner, SboRegistrationReceivedNotification::class, 1);
});

test('the registration defaults the entity type to SBO', function (): void {
    Livewire::test(AuthModal::class)
        ->set(validJoinFormData())
        ->call('registerBusiness')
        ->assertHasNoErrors();

    $sbo = Sbo::where('name', 'Kingdom Tech Services')->firstOrFail();
    expect($sbo->entity_type)->toBe('SBO')
        ->and($sbo->entityTypeLabel())->toBe('SBO (Strategic Business Organization)');
});

test('an applicant can register as a COB', function (): void {
    Livewire::test(AuthModal::class)
        ->set(validJoinFormData(['bizEntityType' => 'COB']))
        ->call('registerBusiness')
        ->assertHasNoErrors();

    $sbo = Sbo::where('name', 'Kingdom Tech Services')->firstOrFail();
    expect($sbo->entity_type)->toBe('COB')
        ->and($sbo->entityTypeLabel())->toBe('COB (Citizen Owned Business)');
});

test('an invalid entity type is rejected', function (): void {
    Livewire::test(AuthModal::class)
        ->set(validJoinFormData(['bizEntityType' => 'XYZ']))
        ->call('registerBusiness')
        ->assertHasErrors(['bizEntityType']);
});

test('join welcome email points owners to registration fees without pending review language', function (): void {
    $owner = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'firstname' => 'Jane',
    ]);

    $sbo = Sbo::create([
        'name' => 'Welcome Email SBO',
        'slug' => 'welcome-email-sbo',
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
    ]);

    $mail = (new SboRegistrationReceivedNotification($sbo))->toMail($owner);
    $intro = collect($mail->introLines)->flatten()->join("\n");

    expect($mail->subject)->toBe('Welcome to the SBO Registry – Loveworld Registry');
    expect($intro)->toContain('Your email has been verified');
    expect($intro)->toContain('complete your registration fee payment');
    expect($intro)->not->toContain('pending admin review');
    expect($intro)->not->toContain('approved by an administrator');
    expect($mail->actionText)->toBe('Continue to registration fees');
});

test('join form rejects invalid country names and unsupported fixed options', function (): void {
    Livewire::test(AuthModal::class)
        ->set(validJoinFormData([
            'bizLegalStructure' => 'Invented Structure',
            'bizCountry' => 'Nigerai',
            'ownerCountry' => 'Untitled Country',
            'ownerChurchZone' => 'Invented Zone',
            'bizSocials' => [
                ['platform' => 'MySpace', 'handle' => 'kingdomtech'],
            ],
        ]))
        ->call('registerBusiness')
        ->assertHasErrors([
            'bizLegalStructure',
            'bizCountry',
            'ownerCountry',
            'ownerChurchZone',
            'bizSocials.0.platform',
        ]);
});

test('join form accepts blank optional social rows but rejects partial ones', function (): void {
    Livewire::test(AuthModal::class)
        ->set(validJoinFormData([
            'bizSocials' => [
                ['platform' => '', 'handle' => ''],
            ],
        ]))
        ->call('businessNextStep')
        ->assertHasNoErrors();

    Livewire::test(AuthModal::class)
        ->set(validJoinFormData([
            'bizSocials' => [
                ['platform' => 'Instagram', 'handle' => ''],
            ],
        ]))
        ->call('businessNextStep')
        ->assertHasErrors(['bizSocials.0.handle']);
});

test('join form requires a stronger account password', function (): void {
    Livewire::test(AuthModal::class)
        ->set(validJoinFormData([
            'bizPassword' => 'password',
            'bizPasswordConfirmation' => 'password',
        ]))
        ->call('registerBusiness')
        ->assertHasErrors(['bizPassword']);
});

test('join form requires turnstile when configured', function (): void {
    config([
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
    ]);

    Livewire::test(AuthModal::class)
        ->set(validJoinFormData())
        ->call('registerBusiness')
        ->assertHasErrors(['turnstileToken']);
});

test('final submit returns to the earliest step owning a failed field', function (): void {
    User::factory()->create(['email' => 'taken.owner@example.com']);

    // The user is on the last step when a step-2 field (the now-taken owner
    // email) fails re-validation; the form must jump back so the error is
    // not trapped inside a hidden panel.
    Livewire::test(AuthModal::class)
        ->set(validJoinFormData(['ownerEmail' => 'taken.owner@example.com']))
        ->set('businessStep', 3)
        ->call('registerBusiness')
        ->assertHasErrors(['ownerEmail'])
        ->assertSet('businessStep', 2);
});
