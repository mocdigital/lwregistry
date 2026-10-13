<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Where a member who is not yet ADMIN-APPROVED can and cannot go.
 *
 * The registry is members-only, and access to the restricted platform (the
 * directory, procurement, network, account, notifications) is granted once an
 * admin APPROVES the business. Paying is NOT enough — a member who has added a
 * business and paid, but is awaiting approval, is still held on their dashboard
 * and the application flow until an admin approves them. Documents-compliance
 * and payment confirmation are separate later gates and are NOT required for
 * general platform access.
 */

function gatedOwner(string $paymentStatus = 'unpaid'): User
{
    $user = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    Sbo::create([
        'name' => 'Gate SBO ' . Str::random(6),
        'slug' => 'gate-sbo-' . Str::random(6),
        'created_by' => $user->id,
        // Awaiting approval: the business is not yet approved, so the member is
        // held back regardless of whether the fee has been paid.
        'verification_status' => 'pending',
        'status' => 'active',
        'is_active' => true,
        'country' => 'Nigeria',
        'payment_status' => $paymentStatus,
        'payment_confirmed_at' => 'paid' === $paymentStatus ? now() : null,
    ]);

    return $user;
}

/** A member whose business an admin has APPROVED, but whose documents and fees are not yet settled. */
function approvedOwner(string $paymentStatus = 'unpaid'): User
{
    $user = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    Sbo::create([
        'name' => 'Approved SBO ' . Str::random(6),
        'slug' => 'approved-sbo-' . Str::random(6),
        'created_by' => $user->id,
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
        'country' => 'Nigeria',
        'payment_status' => $paymentStatus,
        'payment_confirmed_at' => 'paid' === $paymentStatus ? now() : null,
    ]);

    return $user;
}

/** A member whose business is fully verified — approved, documents compliant, fees paid. */
function verifiedOwner(): User
{
    $user = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    Sbo::create([
        'name' => 'Verified SBO ' . Str::random(6),
        'slug' => 'verified-sbo-' . Str::random(6),
        'created_by' => $user->id,
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
        'country' => 'Nigeria',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
    ]);

    return $user;
}

test('the directory walls an unpaid owner instead of throwing', function (): void {
    $this->actingAs(gatedOwner())
        ->get(route('businesses.index'))
        ->assertRedirect(route('sbo.payment-required'));
});

test('a paid but not-yet-approved owner is still walled', function (): void {
    // Paying is no longer a way in — access waits for the admin's verification.
    $this->actingAs(gatedOwner('paid'))
        ->get(route('businesses.index'))
        ->assertRedirect(route('sbo.payment-required'));
});

test('a fully verified owner browses the directory normally', function (): void {
    $this->actingAs(verifiedOwner())
        ->get(route('businesses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('public/DirectoryView'));
});

test('an approved owner browses the platform even before documents and fees are settled', function (): void {
    // Approval is the key: once an admin approves the business, the member is in
    // — they do not have to wait for the documents-compliance or payment gates.
    $this->actingAs(approvedOwner('unpaid'))
        ->get(route('businesses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('public/DirectoryView'));
});

test('a user with no listing at all sees the wall, which asks them to register', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->get(route('businesses.index'))
        ->assertRedirect(route('sbo.payment-required'));

    // Not a manager, so the wall must not sit behind `sbo_manager`.
    $this->actingAs($user)
        ->get(route('sbo.payment-required'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('account/PaymentRequiredView')
            ->where('needsRegistration', true)
            ->where('sboId', null));
});

test('admins are not gated — they approve the applications', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)->get(route('businesses.index'))->assertOk();
});

test('the whole workspace is behind the gate until approval', function (): void {
    // A paid owner, to prove paying is not enough for any of these.
    $owner = gatedOwner('paid');

    // The browsing platform AND the member's own workspace are both locked
    // until an admin approves — the SBO dashboard, member dashboard, profile
    // and account listings included.
    $walled = [
        'businesses.index', 'categories.index', 'procurement.index', 'procurement.create',
        'notifications.index', 'sbo.categories.index', 'feedback',
        'sbo.dashboard', 'dashboard', 'account.profile', 'account.index',
    ];

    foreach ($walled as $route) {
        $this->actingAs($owner)
            ->get(route($route))
            ->assertRedirect(route('sbo.payment-required'));
    }

    // Once approved, the workspace opens.
    $approved = approvedOwner('paid');

    foreach (['sbo.dashboard', 'dashboard', 'account.profile', 'account.index'] as $route) {
        $this->actingAs($approved)->get(route($route))->assertOk();
    }
});

test('an awaiting-approval member is held at the wall', function (): void {
    $owner = gatedOwner('paid');

    // The whole workspace is locked until approval.
    foreach (['dashboard', 'sbo.dashboard', 'account.profile', 'account.index', 'businesses.index'] as $route) {
        $this->actingAs($owner)->get(route($route))
            ->assertRedirect(route('sbo.payment-required'));
    }

    // The wall renders (no redirect loop) — their status screen.
    $this->actingAs($owner)->get(route('sbo.payment-required'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('account/PaymentRequiredView'));
});

test('signing in while awaiting approval lands on the wall, not a dead end', function (): void {
    $owner = gatedOwner();

    // `guest` bounces an authenticated visitor off /login to /dashboard, which
    // now defers to the wall until an admin approves.
    $this->actingAs($owner)->get(route('login'))->assertRedirect(route('dashboard'));
    $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('sbo.payment-required'));

    // post-login sends an unpaid owner to the fee step, the way forward.
    $this->actingAs($owner)->get(route('post-login'))->assertRedirect(); // to the fee step

    // A paid owner still awaiting approval is landed on the wall, not the
    // now-locked SBO dashboard.
    $this->actingAs(gatedOwner('paid'))
        ->get(route('post-login'))
        ->assertRedirect(route('sbo.payment-required'));
});

test('an owner awaiting approval can still reach the pages that let them progress', function (): void {
    $owner = gatedOwner();

    foreach (['home', 'support.index', 'sbo.payment-required'] as $route) {
        $this->actingAs($owner)->get(route($route))->assertOk();
    }

    // Payment is now the final step of the add-business page: the old fee step
    // redirects there, and that pay step (and the listing form) stay open as
    // their way forward while the workspace is locked.
    $target = app(App\Support\SboWorkspaceAccess::class)->paymentTarget($owner);

    $this->actingAs($owner)
        ->get(route('application.show', ['step' => 'fees']))
        ->assertRedirect(route('sbo.listings.create', ['pay' => $target->id]));

    $this->actingAs($owner)->get(route('sbo.listings.create', ['pay' => $target->id]))->assertOk();
    $this->actingAs($owner)->get(route('sbo.listings.create'))->assertOk();
});
