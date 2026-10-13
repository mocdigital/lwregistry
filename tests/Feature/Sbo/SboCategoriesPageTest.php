<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

function sboCategoriesUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ], $overrides));
}

function sboCategoriesSbo(User $manager, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Categories SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $manager->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('the categories page needs approval but not full network verification', function (): void {
    $manager = sboCategoriesUser();
    $own = sboCategoriesSbo($manager, [
        'name' => 'Incomplete Categories SBO',
        'verification_status' => 'pending',
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
    ]);

    // Awaiting approval: the route carries `sbo_payment_approved`, so an owner
    // whose business is not yet approved gets the wall, not the directory.
    $this->actingAs($manager)
        ->get(route('sbo.categories.index'))
        ->assertRedirect(route('sbo.payment-required'));

    /*
     * But approval is the only bar. Approve the business — with documents and
     * fees still outstanding it has no network access of its own, yet the page
     * still opens — the distinction this test exists to hold: categories is a
     * directory, not a network workspace.
     */
    $own->update(['verification_status' => 'approved']);

    sboCategoriesSbo($manager, [
        'name' => 'Verified Categories SBO',
        'category' => 'Technology',
    ]);

    $response = $this->actingAs($manager)
        ->get(route('sbo.categories.index'))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('public/CategoriesView')
        ->has('listings.data')
        ->has('quickFilters'));

    expect(directoryNames($response))->toContain('Verified Categories SBO');
});

test('sbo owners can filter category directory without a full page reload', function (): void {
    Category::factory()->create(['name' => 'Healthcare', 'slug' => 'healthcare']);
    Category::factory()->create(['name' => 'Creative Arts', 'slug' => 'creative-arts']);

    $owner = sboCategoriesUser();
    $mediaOwner = sboCategoriesUser();
    $logisticsOwner = sboCategoriesUser();
    $pendingOwner = sboCategoriesUser();
    $unverifiedOwner = sboCategoriesUser();

    $activeSbo = sboCategoriesSbo($owner, ['name' => 'Owner Verified SBO']);
    $mediaSbo = sboCategoriesSbo($mediaOwner, [
        'name' => 'Kingdom Media Logistics',
        'category' => 'Media',
        'business_type' => 'Service Provider',
        'country' => 'Nigeria',
        'city' => 'Lagos',
        'church_zone' => 'Lagos Zone 1',
        'description' => 'Broadcast production and live media coverage.',
    ]);
    $logisticsSbo = sboCategoriesSbo($logisticsOwner, [
        'name' => 'Excellent Freight Company',
        'category' => 'Logistics',
        'business_type' => 'Product Supplier',
        'country' => 'Ghana',
        'city' => 'Accra',
        'church_zone' => 'Accra Ghana Zone',
    ]);
    $pendingSbo = sboCategoriesSbo($pendingOwner, [
        'name' => 'Pending Media Partner',
        'category' => 'Media',
        'business_type' => 'Service Provider',
        'country' => 'Nigeria',
        'church_zone' => 'Lagos Zone 1',
    ]);
    $unverifiedSbo = sboCategoriesSbo($unverifiedOwner, [
        'name' => 'Pending Agriculture Supplier',
        'category' => 'Agriculture',
        'business_type' => 'Producer',
        'country' => 'Nigeria',
        'church_zone' => 'Ibadan Zone 1',
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'documents_compliant_at' => null,
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
        'status' => 'pending',
        'is_active' => false,
    ]);

    SboConnection::create([
        'requester_sbo_id' => $activeSbo->id,
        'recipient_sbo_id' => $pendingSbo->id,
        'status' => SboConnection::STATUS_PENDING,
    ]);

    Livewire::actingAs($owner)
        ->test('sbo.categories.index')
        ->assertSee('Healthcare')
        ->assertSee('Creative Arts')
        ->assertSee('Kingdom Media Logistics')
        ->assertSee('Excellent Freight Company')
        ->assertSee('Pending Agriculture Supplier')
        ->set('categoryFilter', 'Media')
        ->assertSee('Kingdom Media Logistics')
        ->assertDontSee('Excellent Freight Company')
        ->set('search', 'broadcast')
        ->assertSee('Kingdom Media Logistics')
        ->assertDontSee('Pending Media Partner')
        ->set('search', '')
        ->set('categoryFilter', 'all')
        ->set('verificationFilter', 'not_verified')
        ->assertSee('Pending Agriculture Supplier')
        ->assertDontSee('Kingdom Media Logistics')
        ->set('verificationFilter', 'verified')
        ->assertSee('Kingdom Media Logistics')
        ->assertDontSee('Pending Agriculture Supplier')
        ->set('verificationFilter', 'all')
        ->set('connectionFilter', 'pending')
        ->assertSee('Pending Media Partner')
        ->assertDontSee('Kingdom Media Logistics')
        ->set('connectionFilter', 'not_connected')
        ->assertSee('Kingdom Media Logistics')
        ->assertDontSee('Pending Media Partner')
        ->set('countryFilter', 'Nigeria')
        ->set('zoneFilter', 'Lagos Zone 1')
        ->set('businessTypeFilter', 'Service Provider')
        ->set('sort', 'recent')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('categoryFilter', 'all')
        ->assertSet('countryFilter', 'all')
        ->assertSet('zoneFilter', 'all')
        ->assertSet('businessTypeFilter', 'all')
        ->assertSet('verificationFilter', 'all')
        ->assertSet('connectionFilter', 'all')
        ->assertSet('sort', 'name')
        ->assertSee('Kingdom Media Logistics')
        ->assertSee('Excellent Freight Company')
        ->assertSee('Pending Agriculture Supplier');

    expect($mediaSbo->exists)->toBeTrue()
        ->and($logisticsSbo->exists)->toBeTrue()
        ->and($unverifiedSbo->exists)->toBeTrue();
});

test('category pagination scrolls back to the sbo results list', function (): void {
    $owner = sboCategoriesUser();
    sboCategoriesSbo($owner, ['name' => 'Owner Pagination SBO']);

    foreach (range(1, 13) as $index) {
        $directoryOwner = sboCategoriesUser();

        sboCategoriesSbo($directoryOwner, [
            'name' => sprintf('Directory Pagination SBO %02d', $index),
            'category' => 'Professional Services',
        ]);
    }

    Livewire::actingAs($owner)
        ->test('sbo.categories.index')
        ->assertSee('id="sbo-category-results"', false)
        ->assertSee('#sbo-category-results', false)
        ->assertSee('scrollIntoView', false)
        ->assertSee('Next');
});
