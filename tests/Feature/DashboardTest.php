<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;

function dashboardPaymentGateManager(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ], $overrides));
}

function dashboardPaymentGateSbo(User $manager, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Dashboard Gate SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $manager->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ], $overrides));
}

test('guests are redirected to the login page', function (): void {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated approved members can visit the dashboard', function (): void {
    $user = User::factory()->create();
    // Admin approval is what gets you in now; see the gate test below.
    dashboardPaymentGateSbo($user, [
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertStatus(200);

    // `accountTabs` / `verified` were DOM ids on the Blade dashboard. The tab
    // strip is Vue state now, so what the server owes it is the counts behind
    // each tab.
    $response->assertInertia(fn ($page) => $page
        ->component('account/ProfileDashboardView')
        ->has('stats.listings')
        ->has('stats.verified')
        ->has('stats.pending')
        ->has('listings'));

    expect(inertiaProp($response, 'stats.listings'))->toBe(1)
        ->and(inertiaProp($response, 'user.email'))->toBe($user->email);
});

test('a manager awaiting approval is walled from their dashboard and the platform', function (): void {
    $manager = dashboardPaymentGateManager();
    $sbo = dashboardPaymentGateSbo($manager, ['name' => 'Unpaid Workspace SBO']);

    // The whole workspace is locked until an admin approves — the dashboard
    // included; the wall is their status screen while they progress.
    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertRedirect(route('sbo.payment-required'));

    // The members-only platform is behind the same gate.
    $this->actingAs($manager)
        ->get(route('businesses.index'))
        ->assertRedirect(route('sbo.payment-required'));

    $response = $this->actingAs($manager)
        ->get(route('sbo.payment-required'))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page->component('account/PaymentRequiredView'));

    // The wall is copy plus one data panel; the copy is in the Vue file, so
    // the panel is what is asserted — it names the SBO being paid for, and
    // links back to that application rather than to a generic page.
    expect(inertiaProp($response, 'reason'))->toBe('payment')
        ->and(inertiaProp($response, 'needsRegistration'))->toBeFalse()
        ->and(inertiaProp($response, 'sboId'))->toBe($sbo->id)
        ->and(inertiaProp($response, 'progressUrl'))->toBe(route('sbo.applications.progress', $sbo))
        ->and(inertiaPluck($response, 'application', 'value'))->toContain('Unpaid Workspace SBO');
});

test('admins are not blocked by the sbo payment gate, but a member with no listing is', function (): void {
    $regularUser = User::factory()->create(['email_verified_at' => now()]);
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);

    /*
     * The platform is paid to use. Owning no SBO used to mean "nothing owed",
     * which let anyone without a listing roam the whole platform for free;
     * it now means they are before the gate rather than past it, and the way
     * in is to register a business.
     */
    $this->actingAs($regularUser)
        ->get(route('dashboard'))
        ->assertRedirect(route('sbo.payment-required'));

    // Admins run the registry rather than belong to it — gating them would
    // lock the people who approve payments out of the approval screens.
    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.dashboard'));
});

test('sbo manager dashboard unlocks when any managed sbo is approved', function (): void {
    $manager = dashboardPaymentGateManager();
    dashboardPaymentGateSbo($manager, ['name' => 'Still Pending SBO']);
    dashboardPaymentGateSbo($manager, [
        'name' => 'Approved Workspace SBO',
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk();
});
