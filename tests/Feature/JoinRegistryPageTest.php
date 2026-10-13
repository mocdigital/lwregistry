<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\ReferralLandingController;
use App\Livewire\JoinRegistry;
use App\Models\Category;
use App\Models\Country;
use App\Models\Sbo;
use App\Models\User;
use App\Models\Zone;
use Livewire\Livewire;

it('redirects the retired join page to the register wizard', function (): void {
    // /join is gone; the name stays so old route('join') links resolve, and it
    // now lands people on the /register wizard instead.
    $this->get(route('join'))->assertRedirect('/register');
});

it('redirects a signed-in visitor away from the join page', function (): void {
    $user = User::factory()->create(['role' => UserRole::SBO_MANAGER->value]);

    Livewire::actingAs($user)->test(JoinRegistry::class)
        ->assertRedirect(route('post-login'));
});

it('opens in register mode', function (): void {
    Livewire::test(JoinRegistry::class)
        ->assertSet('mode', 'register')
        ->assertSet('businessStep', 1);
});

it('pre-selects the zone carried by a referral link', function (): void {
    $zone = Zone::create(['name' => 'Referral Zone', 'code' => 'REFZON', 'is_active' => true]);

    $this->withSession([
        ReferralLandingController::SESSION_CODE => $zone->code,
        ReferralLandingController::SESSION_NAME => $zone->name,
    ]);

    Livewire::test(JoinRegistry::class)
        ->assertSet('ownerChurchZone', 'Referral Zone');
});

it('validates step one before advancing', function (): void {
    Livewire::test(JoinRegistry::class)
        ->call('businessNextStep')
        ->assertHasErrors(['bizName', 'bizLegalStructure', 'bizAddress'])
        ->assertSet('businessStep', 1);
});

it('registers a business and creates the SBO from the page', function (): void {
    $zone = Zone::create(['name' => 'Join Zone', 'code' => 'JOINZN', 'is_active' => true]);
    $country = Country::first() ?? Country::create(['name' => 'Nigeria', 'iso2' => 'NG']);
    $category = Category::first() ?? Category::create(['name' => 'Retail', 'slug' => 'retail']);

    Livewire::test(JoinRegistry::class)
        ->set('bizName', 'Page Registered Co')
        ->set('bizLegalStructure', 'Private Limited Company (Ltd)')
        ->set('bizAddress', '10 Example Road')
        ->set('bizCountry', $country->name)
        ->set('bizState', 'Lagos')
        ->set('bizPhone', '+2348012345678')
        ->set('bizEmail', 'biz@example.com')
        ->set('bizNature', $category->name)
        ->set('ownerSurname', 'Doe')
        ->set('ownerFirstName', 'Jane')
        ->set('ownerCountry', $country->name)
        ->set('ownerResidentialAddress', '11 Example Road')
        ->set('ownerPhone', '+2348012345679')
        ->set('ownerEmail', 'owner@example.com')
        ->set('ownerChurchZone', $zone->name)
        ->set('ownerLocalAssembly', 'Local Assembly')
        ->set('bizPassword', 'Password123')
        ->set('bizPasswordConfirmation', 'Password123')
        ->set('bizAgreeTerms', true)
        ->set('bizAgreeCode', true)
        ->call('registerBusiness')
        ->assertHasNoErrors()
        ->assertRedirect(route('post-login'));

    $sbo = Sbo::where('name', 'Page Registered Co')->first();

    expect($sbo)->not->toBeNull()
        ->and($sbo->zone_id)->toBe($zone->id)
        ->and(User::where('email', 'owner@example.com')->exists())->toBeTrue();
});

it('serves the home page as a Vue screen rather than a signup modal', function (): void {
    /*
     * These asserted Bootstrap modal hooks (`data-bs-target="#signupModal"`)
     * on the home page. Home is a Vue screen and those modals are gone; the
     * reachable contract is that home renders its Inertia component.
     */
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('public/HomeView'));
});

it('serves sign-in as its own page too', function (): void {
    // Login was a modal (`#loginModal`); it is a screen with a real POST now.
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/LoginView'));
});
