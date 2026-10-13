<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;

test('business directory renders sbo listings without logo media support', function (): void {
    Sbo::create([
        'name' => 'Directory Ready SBO',
        'slug' => 'directory-ready-sbo',
        'category' => 'Technology',
        'city' => 'Lagos',
        'address' => 'Central Business District',
        'description' => 'Technology services',
        'created_by' => User::factory()->create()->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    $names = directoryNames(
        $this->actingAs(paidMember())->get(route('businesses.index'))->assertOk()
    );

    expect($names)->toContain('Directory Ready SBO');
});

test('business category directory renders matching sbo listings', function (): void {
    $category = Category::factory()->create([
        'name' => 'Technology',
        'slug' => 'technology',
    ]);

    Sbo::create([
        'name' => 'Category Ready SBO',
        'slug' => 'category-ready-sbo',
        'category' => 'Technology',
        'city' => 'Lagos',
        'address' => 'Central Business District',
        'description' => 'Technology services',
        'created_by' => User::factory()->create()->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    // `businesses.category` is still the legacy Blade directory
    // (BusinessController::indexByCategory returns a View), so assertSee is
    // the right assertion here.
    $this->actingAs(paidMember())
        ->get(route('businesses.category', $category))
        ->assertOk()
        ->assertSee('Category Ready SBO');
});

test('business detail page renders an sbo listing', function (): void {
    Category::factory()->create([
        'name' => 'Technology',
        'slug' => 'technology',
    ]);

    $sbo = Sbo::create([
        'name' => 'Detail Ready SBO',
        'slug' => 'detail-ready-sbo',
        'category' => 'Technology',
        'city' => 'Lagos',
        'country' => 'Nigeria',
        'address' => 'Central Business District',
        'description' => 'Technology services',
        'created_by' => User::factory()->create()->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    $this->actingAs(paidMember())
        ->get(route('businesses.show', $sbo))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/BusinessProfileView')
            ->where('business.name', 'Detail Ready SBO')
            ->where('business.category', 'Technology')
            ->where('business.verified', true));
});

test('unpaid sbo manager sees payment gate popup when opening business directory', function (): void {
    Sbo::create([
        'name' => 'Directory Listing Behind Gate',
        'slug' => 'directory-listing-behind-gate',
        'category' => 'Technology',
        'city' => 'Lagos',
        'address' => 'Central Business District',
        'description' => 'Technology services',
        'created_by' => User::factory()->create()->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    $manager = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
    $name = 'Unpaid Directory Gate SBO ' . Str::random(6);
    $sbo = Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $manager->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);

    /*
     * The gate was an in-page popup on the Blade directory. It is a redirect to
     * the designed payment wall now — the same screen every other gated route
     * sends people to — so the assertion is where they land, not what the
     * popup said. (It was also a hard 500 for a while: the gated branch
     * returned a Blade View from a method typed `: InertiaResponse`.)
     */
    $this->actingAs($manager)
        ->get(route('businesses.index'))
        ->assertRedirect(route('sbo.payment-required'));

    $this->actingAs($manager)
        ->get(route('sbo.payment-required'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('account/PaymentRequiredView')
            ->where('reason', 'payment')
            ->where('sboId', $sbo->id));
});

test('paid sbo manager can open business directory normally', function (): void {
    $manager = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
    Sbo::create([
        'name' => 'Paid Manager Directory SBO',
        'slug' => 'paid-manager-directory-sbo',
        'category' => 'Technology',
        'city' => 'Lagos',
        'address' => 'Central Business District',
        'description' => 'Technology services',
        'created_by' => $manager->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    $names = directoryNames(
        $this->actingAs($manager)->get(route('businesses.index'))->assertOk()
    );

    expect($names)->toContain('Paid Manager Directory SBO');
});
