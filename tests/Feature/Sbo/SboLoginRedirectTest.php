<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\AuthModal;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;

function sboLoginRedirectApplication(User $manager, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Login Redirect SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $manager->id,
        'verification_status' => 'approved',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('verified unpaid sbo manager login routes to the registration fees step', function (): void {
    $manager = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email' => 'sbo-manager@example.test',
        'email_verified_at' => now(),
        'password' => Hash::make('password123'),
    ]);
    $sbo = sboLoginRedirectApplication($manager);

    Livewire::test(AuthModal::class)
        ->set('loginEmail', $manager->email)
        ->set('loginPassword', 'password123')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('post-login', absolute: false));

    $this->assertAuthenticatedAs($manager);

    // Payment lives on the add-business page's final step now, so an unpaid
    // owner is returned there rather than to a separate fees screen.
    $this->actingAs($manager)
        ->get(route('post-login'))
        ->assertRedirect(route('sbo.listings.create', ['pay' => $sbo->id]));
});

test('verified unpaid sbo manager logging in from advertise lands on ad builder', function (): void {
    $manager = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email' => 'advertise-sbo-manager@example.test',
        'email_verified_at' => now(),
        'password' => Hash::make('password123'),
    ]);
    sboLoginRedirectApplication($manager);

    Livewire::test(AuthModal::class)
        ->set('loginEmail', $manager->email)
        ->set('loginPassword', 'password123')
        ->set('redirectAfterLogin', route('advertise') . '#advertise-campaign-builder')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('advertise') . '#advertise-campaign-builder');

    $this->assertAuthenticatedAs($manager);
});

test('unverified sbo manager still lands on the email verification notice', function (): void {
    $manager = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => null,
    ]);

    sboLoginRedirectApplication($manager);

    $this->actingAs($manager)
        ->get(route('post-login'))
        ->assertRedirect(route('verification.notice'));
});

test('email verification redirects unpaid sbo owners to the registration fees step', function (): void {
    $manager = User::factory()->unverified()->create([
        'role' => UserRole::SBO_MANAGER,
    ]);
    $sbo = sboLoginRedirectApplication($manager);

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(10),
        [
            'id' => $manager->id,
            'hash' => sha1($manager->getEmailForVerification()),
        ]
    );

    $this->actingAs($manager)
        ->get($verificationUrl)
        ->assertRedirect(
            route('sbo.applications.progress', ['sbo' => $sbo, 'step' => 'fees'], false) . '&verified=1'
        );

    expect($manager->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('email verification sends a member with no business to add one', function (): void {
    // Registration now creates a member account with no business; verifying the
    // email takes them straight to "Add your business".
    $manager = User::factory()->unverified()->create([
        'role' => UserRole::SBO_MANAGER,
    ]);

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(10),
        [
            'id' => $manager->id,
            'hash' => sha1($manager->getEmailForVerification()),
        ]
    );

    $this->actingAs($manager)
        ->get($verificationUrl)
        ->assertRedirect(route('sbo.listings.create', absolute: false) . '?verified=1');

    expect($manager->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('an admin lands on the admin dashboard, as a full page load not an Inertia visit', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);

    // A plain request degrades to a 302, so the destination is still asserted.
    $this->actingAs($admin)
        ->get(route('post-login'))
        ->assertRedirect(route('admin.dashboard'));

    // An Inertia request gets 409 + an X-Inertia-Location header — which the
    // client turns into a real browser navigation instead of an XHR that lands
    // on the Blade admin page and is shown in an error modal. (The header may
    // point back at post-login, which then 302s to the admin page as a full
    // load; forcing the 409 is the point, not its exact target.)
    $this->actingAs($admin)
        ->get(route('post-login'), ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

test('verified paid sbo manager login falls back to the sbo dashboard', function (): void {
    $manager = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    sboLoginRedirectApplication($manager, [
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('post-login'))
        ->assertRedirect(route('sbo.dashboard'));
});
