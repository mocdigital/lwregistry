<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Country;
use App\Models\Ministry;
use App\Models\Sbo;
use App\Models\User;
use App\Models\Zone;

/*
 * Registration and business setup, end to end.
 *
 * Registration now creates a MEMBER ACCOUNT only — the person, their contact and
 * Loveworld-member details, an email and a password. The business is added
 * afterwards, from the workspace (sbo.listings.store), where the business
 * details and the Code of Conduct are collected and the member details are
 * copied onto the new SBO.
 */

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'ownerSurname' => 'Okafor',
        'ownerFirstName' => 'Chidi',
        'ownerCountry' => 'Nigeria',
        'ownerKingschat' => '@chidi',
        'ownerResidentialAddress' => '9 Bourdillon Road, Ikoyi',
        'ownerPhone' => '08031234567',
        'ownerChurchZone' => 'Lagos Zone 1',
        'ownerLocalAssembly' => 'Ikoyi Assembly',
        'ownerYearsAsMember' => '8',
        'ownerZonalPastor' => 'Pastor A',
        'ownerLocalPastor' => 'Pastor B',
        'email' => 'chidi@ditabol.test',
        'password' => 'Passw0rdSafe',
        'passwordConfirmation' => 'Passw0rdSafe',
        'agreeTerms' => true,
    ], $overrides);
}

/*
 * Adding a business and paying for it are ONE request: the payment section is
 * on the add-business form, and pressing one of its buttons is what saves the
 * business. So every payload that expects a business to exist afterwards
 * carries a payment intent — here, manual proof, which needs no gateway.
 */
function listingPayload(array $overrides = []): array
{
    return array_merge([
        'payWith' => 'manual',
        'proof' => Illuminate\Http\UploadedFile::fake()->image('receipt.png'),
        'bizName' => 'Ditabol Homes Nigeria Limited',
        'bizEntityType' => 'SBO',
        'bizMinistry' => 'Healing School',
        'bizTradingName' => 'Ditabol Homes',
        'bizRegistrationNumber' => 'RC-482913',
        'bizLegalStructure' => 'Private Limited Company (Ltd)',
        'bizNature' => 'Software Development',
        'bizCountry' => 'Nigeria',
        'bizState' => 'Lagos State',
        'bizAddress' => '14 Admiralty Way, Lekki',
        'bizEmail' => 'hello@ditabol.test',
        'bizPhone' => '+2348031234567',
        'bizWebsite' => 'ditabol.test',
        'bizYearEstablished' => '2011',
        'bizEmployees' => '12',
        'bizDescription' => 'We build and maintain property management software.',
        'agreeCode' => true,
    ], $overrides);
}

/** A verified member who has completed registration, ready to add a business. */
function verifiedMember(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'firstname' => 'Chidi',
        'lastname' => 'Okafor',
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
        'country' => 'Nigeria',
        'residential_address' => '9 Bourdillon Road, Ikoyi',
        'church_zone' => 'Lagos Zone 1',
        'local_assembly' => 'Ikoyi Assembly',
    ], $overrides))->fresh();
}

beforeEach(function (): void {
    Category::query()->firstOrCreate(
        ['slug' => 'software-development'],
        ['name' => 'Software Development', 'business_count' => 0],
    );
    Country::query()->firstOrCreate(['name' => 'Nigeria'], ['code' => 'NG']);
    Zone::query()->firstOrCreate(['name' => 'Lagos Zone 1'], ['is_active' => true]);
    Ministry::query()->firstOrCreate(['name' => 'Healing School'], ['slug' => 'healing-school', 'is_active' => true]);
});

/* ---------------------------------------------------------------- registration */

test('registration creates a member account and no business', function (): void {
    $this->post(route('register'), registerPayload())->assertRedirect();

    $user = User::where('email', 'chidi@ditabol.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::SBO_MANAGER)
        ->and($user->church_zone)->toBe('Lagos Zone 1')
        ->and($user->local_assembly)->toBe('Ikoyi Assembly')
        ->and($user->zone_id)->toBe(Zone::where('name', 'Lagos Zone 1')->value('id'))
        ->and(Sbo::where('created_by', $user->id)->exists())->toBeFalse();

    $this->assertAuthenticated();
});

/*
 * The verification mail is sent during the request. When the provider refused
 * it (Resend: "domain is not verified", 2026-10-02), registration answered with
 * a bare 500 even though the account had been saved.
 */
test('a mail-provider failure does not turn registration into a 500', function (): void {
    $this->mock(Illuminate\Contracts\Notifications\Dispatcher::class)
        ->shouldReceive('sendNow')
        ->andThrow(new Symfony\Component\Mailer\Exception\TransportException('Request to Resend API failed.'));

    $this->post(route('register'), registerPayload())
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'verification-link-failed');

    expect(User::where('email', 'chidi@ditabol.test')->exists())->toBeTrue();
    $this->assertAuthenticated();
});

test('resending the verification mail reports a provider failure instead of a 500', function (): void {
    $this->mock(Illuminate\Contracts\Notifications\Dispatcher::class)
        ->shouldReceive('sendNow')
        ->andThrow(new Symfony\Component\Mailer\Exception\TransportException('Request to Resend API failed.'));

    $this->actingAs(User::factory()->unverified()->create())
        ->from(route('verification.notice'))
        ->post(route('verification.send'))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'verification-link-failed');
});

test('the account email is required and must be unique', function (): void {
    User::factory()->create(['email' => 'taken@ditabol.test']);

    $this->post(route('register'), registerPayload(['email' => 'taken@ditabol.test']))
        ->assertSessionHasErrors('email');

    $this->post(route('register'), registerPayload(['email' => '']))
        ->assertSessionHasErrors('email');
});

test('the church zone must be a real active zone', function (): void {
    $this->post(route('register'), registerPayload(['ownerChurchZone' => 'Nowhere Zone']))
        ->assertSessionHasErrors('ownerChurchZone');
});

test('the terms must be accepted, and the passwords must match and be strong', function (): void {
    $this->post(route('register'), registerPayload(['agreeTerms' => false]))
        ->assertSessionHasErrors('agreeTerms');

    $this->post(route('register'), registerPayload(['passwordConfirmation' => 'Mismatched1']))
        ->assertSessionHasErrors('passwordConfirmation');

    $this->post(route('register'), registerPayload(['password' => 'weak', 'passwordConfirmation' => 'weak']))
        ->assertSessionHasErrors('password');

    expect(User::where('email', 'chidi@ditabol.test')->exists())->toBeFalse();
});

/* -------------------------------------------------------------- business setup */

test('the add-business page offers every option its selects validate against', function (): void {
    $response = $this->actingAs(verifiedMember())->get(route('sbo.listings.create'))->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('auth/RegisterWizardView')
        ->where('mode', 'listing')
        ->has('categories')
        ->has('countries')
        ->has('legalStructures')
        ->has('ministries'));
});

test('a verified member adds a business, inheriting their member details', function (): void {
    $user = verifiedMember();

    $response = $this->actingAs($user)
        ->post(route('sbo.listings.store'), listingPayload());

    $sbo = Sbo::where('name', 'Ditabol Homes Nigeria Limited')->firstOrFail();

    // Straight to the payment step for the new business — same signup flow.
    $response->assertRedirect(route('sbo.listings.create', ['pay' => $sbo->id]));

    expect($sbo->created_by)->toBe($user->id)
        ->and($sbo->entity_type)->toBe('SBO')
        ->and($sbo->ministry)->toBe('Healing School')
        ->and($sbo->trading_name)->toBe('Ditabol Homes')
        ->and($sbo->registration_number)->toBe('RC-482913')
        // Member details copied from the account, not re-entered.
        ->and($sbo->church_zone)->toBe('Lagos Zone 1')
        ->and($sbo->local_assembly)->toBe('Ikoyi Assembly')
        ->and($sbo->owner_first_name)->toBe('Chidi')
        ->and($sbo->code_of_conduct_agreed_at)->not->toBeNull();
});

test('the Code of Conduct must be agreed to add a business', function (): void {
    $this->actingAs(verifiedMember())
        ->post(route('sbo.listings.store'), listingPayload(['agreeCode' => false]))
        ->assertSessionHasErrors('agreeCode');

    expect(Sbo::where('name', 'Ditabol Homes Nigeria Limited')->exists())->toBeFalse();
});

test('an SBO must name a ministry, and it is stored', function (): void {
    $this->actingAs(verifiedMember())
        ->post(route('sbo.listings.store'), listingPayload(['bizMinistry' => '']))
        ->assertSessionHasErrors('bizMinistry');
});

test('a COB business is added without a ministry', function (): void {
    $response = $this->actingAs(verifiedMember())
        ->post(route('sbo.listings.store'), listingPayload([
            'bizName' => 'Citizen Owned Co',
            'bizEntityType' => 'COB',
            'bizMinistry' => '',
        ]));

    $sbo = Sbo::where('name', 'Citizen Owned Co')->firstOrFail();

    // A COB also goes to the payment step; its entity type is preserved.
    $response->assertRedirect(route('sbo.listings.create', ['pay' => $sbo->id]));

    expect($sbo->entity_type)->toBe('COB')
        ->and($sbo->ministry)->toBeNull();
});

test('the payment reveals for the owner and not for anyone else', function (): void {
    $owner = verifiedMember();
    $this->actingAs($owner)->post(route('sbo.listings.store'), listingPayload());
    $sbo = Sbo::where('name', 'Ditabol Homes Nigeria Limited')->firstOrFail();

    // The owner sees the payment revealed on the same add-business view.
    $this->actingAs($owner)
        ->get(route('sbo.listings.create', ['pay' => $sbo->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/RegisterWizardView')
            ->where('payStep', true)
            ->where('sbo.id', $sbo->id)
            ->has('payFees'));

    // Someone else passing the same ?pay just gets the form — no payment, no leak.
    $this->actingAs(verifiedMember(['email' => 'other@example.com']))
        ->get(route('sbo.listings.create', ['pay' => $sbo->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('payStep', false)
            ->where('sbo', null)
            ->where('payFees', null));
});

test('the entity type must be chosen — there is no SBO default', function (): void {
    $this->actingAs(verifiedMember())
        ->post(route('sbo.listings.store'), listingPayload(['bizEntityType' => '']))
        ->assertSessionHasErrors('bizEntityType');

    expect(Sbo::where('name', 'Ditabol Homes Nigeria Limited')->exists())->toBeFalse();
});

test('the COB entry route opens the add-business form', function (): void {
    $this->actingAs(verifiedMember())
        ->get(route('cob.listings.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/RegisterWizardView'));
});

test('an unverified member cannot add a business', function (): void {
    $user = verifiedMember(['email_verified_at' => null]);

    $this->actingAs($user)
        ->post(route('sbo.listings.store'), listingPayload())
        ->assertRedirect(route('verification.notice'));

    expect(Sbo::where('created_by', $user->id)->exists())->toBeFalse();
});

test('the fee chosen on the form is confirmed when the business is saved', function (): void {
    $this->seed(Database\Seeders\SboFeeScheduleSeeder::class);
    App\Support\SboFeeCalculator::flushCache();

    $owner = verifiedMember();

    $this->actingAs($owner)->post(route('sbo.listings.store'), listingPayload([
        // A flat-fee structure picked in the calculator on the form.
        'bizShareStructure' => 'no_shares',
    ]));

    $sbo = Sbo::where('name', 'Ditabol Homes Nigeria Limited')->firstOrFail();

    // Carried through, so the payment revealed below needs no second picker.
    expect($sbo->share_structure)->toBe('no_shares')
        ->and($sbo->calculated_fee)->not->toBeNull();

    $this->actingAs($owner)
        ->get(route('sbo.listings.create', ['pay' => $sbo->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('payFees.confirmed', true));
});

test('filling the form without pressing a payment button saves nothing', function (): void {
    $user = verifiedMember();

    $payload = listingPayload();
    unset($payload['payWith'], $payload['proof']);

    $this->actingAs($user)
        ->post(route('sbo.listings.store'), $payload)
        ->assertSessionHasErrors('payWith');

    expect(Sbo::where('created_by', $user->id)->exists())->toBeFalse();
});

test('paying manually saves the business and files the proof in one request', function (): void {
    Illuminate\Support\Facades\Storage::fake('compliance');

    $user = verifiedMember();

    $this->actingAs($user)
        ->post(route('sbo.listings.store'), listingPayload())
        ->assertSessionHasNoErrors();

    $sbo = Sbo::where('name', 'Ditabol Homes Nigeria Limited')->firstOrFail();
    $proof = App\Models\SboPaymentProof::where('sbo_id', $sbo->id)->firstOrFail();

    expect($proof->status)->toBe('pending')
        /*
         * file(), not getMedia('proofs'): this is the accessor every reviewer
         * screen reads. Filed anywhere else the receipt is invisible to them,
         * which is exactly the bug this asserts against.
         */
        ->and($proof->file())->not->toBeNull()
        ->and($proof->file()->disk)->toBe('compliance')
        // Submitted, not paid: documents stay shut until an admin approves it.
        ->and($sbo->fresh()->payment_submitted_at)->not->toBeNull()
        ->and($sbo->fresh()->feesPaid())->toBeFalse();
});

test('manual payment needs the proof file itself', function (): void {
    $user = verifiedMember();

    $payload = listingPayload();
    unset($payload['proof']);

    $this->actingAs($user)
        ->post(route('sbo.listings.store'), $payload)
        ->assertSessionHasErrors('proof');

    expect(Sbo::where('created_by', $user->id)->exists())->toBeFalse();
});

test('an unconfigured Espees gateway leaves the business saved as an unpaid draft', function (): void {
    $user = verifiedMember();

    // No gateway configured in the test schedule, so checkout refuses — but the
    // business is already saved, which is the point: they come back and pay.
    $this->actingAs($user)
        ->post(route('sbo.listings.store'), listingPayload([
            'payWith' => 'espees',
            'proof' => null,
        ]))
        ->assertRedirect();

    $sbo = Sbo::where('created_by', $user->id)->firstOrFail();

    expect($sbo->payment_status)->toBe('unpaid')
        ->and($sbo->payment_submitted_at)->toBeNull();
});
