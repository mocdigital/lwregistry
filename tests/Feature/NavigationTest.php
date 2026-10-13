<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * The global header.
 *
 * These asserted on the old Blade navbar — `data-mobile-menu-toggle`,
 * `css/notification-bell.css?v=2`, `lw-notification-bell`, `accountPanel`,
 * `assertSeeInOrder` over the account menu. None of that markup exists: the
 * header is `resources/js/spa/components/shell/AppHeader.vue`, and its nav
 * arrays and menu ordering are client-side constants the server never sends.
 *
 * What the server DOES control is the shared `auth` prop
 * (HandleInertiaRequests::share) — whether there is a signed-in user at all,
 * and the identity the pill renders. That is the contract worth asserting from
 * PHP; the ordering of a hardcoded array belongs in a component test, and this
 * project has no Vue test runner set up.
 */

test('a guest gets no auth identity in the shared props', function (): void {
    $response = $this->get(route('home'))->assertOk();

    expect(inertiaProp($response, 'auth.user'))->toBeNull();
});

test('a signed-in visitor gets their identity for the header pill', function (): void {
    $user = User::factory()->create([
        'firstname' => 'Test',
        'lastname' => 'User',
        'email' => 'test@example.com',
    ]);

    $response = $this->actingAs($user)->get(route('home'))->assertOk();

    // The pill showed a hardcoded name and email to every signed-in user
    // before this prop existed.
    expect(inertiaProp($response, 'auth.user.name'))->toBe('Test User')
        ->and(inertiaProp($response, 'auth.user.email'))->toBe('test@example.com')
        ->and(inertiaProp($response, 'auth.user.initials'))->toBe('TU');
});

test('an sbo owner who has not finished validation is kept out of the gated areas', function (): void {
    $user = User::factory()->create([
        'firstname' => 'Sbo',
        'lastname' => 'Owner',
        'role' => App\Enums\UserRole::SBO_MANAGER,
    ]);

    Sbo::create([
        'name' => 'Navigation Owner SBO',
        'slug' => 'navigation-owner-sbo-' . Str::random(5),
        'created_by' => $user->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);

    // The menu used to say "Unlock after full validation" beside these. The
    // lock is real, so it is asserted where it is enforced rather than by the
    // label that described it.
    $this->actingAs($user)->get(route('sbo.rfqs.index'))
        ->assertRedirect(route('sbo.payment-required'));
    $this->actingAs($user)->get(route('sbo.network.index'))
        ->assertRedirect(route('sbo.payment-required'));
});

test('a fully validated owner reaches bidding and networking', function (): void {
    $user = User::factory()->create([
        'firstname' => 'Validated',
        'lastname' => 'Owner',
        'email_verified_at' => now(),
        'role' => App\Enums\UserRole::SBO_MANAGER,
    ]);

    Sbo::create([
        'name' => 'Validated Navigation SBO',
        'slug' => 'validated-navigation-sbo-' . Str::random(5),
        'created_by' => $user->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($user)->get(route('sbo.rfqs.index'))->assertOk();
    $this->actingAs($user)->get(route('sbo.network.index'))->assertOk();
    $this->actingAs($user)->get(route('sbo.categories.index'))->assertOk();
});

test('the home page renders as an Inertia screen', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('public/HomeView'));
});
