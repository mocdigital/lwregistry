<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use App\Support\AdminPages;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

function dualRoleSbo(User $owner, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Dual Role SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
        'status' => 'pending',
        'is_active' => false,
    ], $overrides));
}

test('an sbo owner can be promoted to admin without losing ownership', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $owner = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
    $sbo = dualRoleSbo($owner, ['name' => 'Promoted Owner SBO']);

    Volt::actingAs($superAdmin)
        ->test('admin.admins.index')
        ->set('promoteExistingUserId', $owner->id)
        ->set('promotePagePermissions', [
            AdminPages::ORGANIZATIONS,
            AdminPages::APPROVAL_WORKFLOW,
        ])
        ->call('promoteExistingUser')
        ->assertHasNoErrors();

    $owner->refresh();

    expect($owner->role)->toBe(UserRole::ADMIN)
        ->and($owner->admin_page_permissions)->toBe([
            AdminPages::ORGANIZATIONS,
            AdminPages::APPROVAL_WORKFLOW,
        ])
        ->and($owner->permissions)->toBe(AdminPages::capabilitiesForPages($owner->admin_page_permissions))
        ->and($sbo->fresh()->created_by)->toBe($owner->id);
});

test('add admin modal supports creating or promoting admins', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();

    Volt::actingAs($superAdmin)
        ->test('admin.admins.index')
        ->assertSee('Create New Admin')
        ->assertSee('Promote Existing User')
        ->assertSee('Promote to Admin');
});

test('duplicate admin email points admins to the promote existing user flow', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $existingUser = User::factory()->create([
        'email' => 'existing-promote@example.com',
    ]);

    Volt::actingAs($superAdmin)
        ->test('admin.admins.index')
        ->set('firstname', 'Existing')
        ->set('lastname', 'Promote')
        ->set('email', $existingUser->email)
        ->set('phone', '+14155552671')
        ->set('password', 'Password123')
        ->set('password_confirmation', 'Password123')
        ->set('pagePermissions', [AdminPages::SUPPORT])
        ->call('createAdmin')
        ->assertHasErrors(['email'])
        ->assertSee('This email already belongs to an existing user. Use Promote existing user instead of creating a duplicate admin account.');
});

test('already admin users cannot be promoted again', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'admin_page_permissions' => [AdminPages::SUPPORT],
    ]);

    Volt::actingAs($superAdmin)
        ->test('admin.admins.index')
        ->set('promoteExistingUserId', $admin->id)
        ->set('promotePagePermissions', [AdminPages::SUPPORT])
        ->call('promoteExistingUser')
        ->assertHasErrors(['promoteExistingUserId'])
        ->assertSee('This user is already an administrator.');
});

test('promote existing user preset stores the preset capabilities', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $user = User::factory()->create(['role' => UserRole::USER]);

    Volt::actingAs($superAdmin)
        ->test('admin.admins.index')
        ->set('promoteExistingUserId', $user->id)
        ->set('promotePagePreset', 'growth')
        ->call('applyPromotePagePreset')
        ->call('promoteExistingUser')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->role)->toBe(UserRole::ADMIN)
        ->and($user->admin_page_permissions)->toBe(AdminPages::presets()['growth']['pages'])
        ->and($user->permissions)->toBe(AdminPages::capabilitiesForPages(AdminPages::presets()['growth']['pages']));
});

test('super admins can grant and revoke backend page access for admins', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'admin_page_permissions' => [AdminPages::SUPPORT, AdminPages::ZONE_REFERRALS],
        'permissions' => AdminPages::capabilitiesForPages([AdminPages::SUPPORT, AdminPages::ZONE_REFERRALS]),
        'email_verified_at' => now(),
    ]);

    Volt::actingAs($superAdmin)
        ->test('admin.admins.index')
        ->call('editPageAccess', $admin->id)
        ->set('editPagePermissions', [AdminPages::ADS])
        ->call('savePageAccess')
        ->assertDispatched('close-edit-perms-modal')
        ->assertDispatched('page-access-saved')
        ->assertHasNoErrors();

    $admin->refresh();

    expect($admin->admin_page_permissions)->toBe([AdminPages::ADS])
        ->and($admin->permissions)->toBe(AdminPages::capabilitiesForPages([AdminPages::ADS]));

    $this->actingAs($admin)
        ->get(route('admin.ads.index'))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('admin.support.index'))
        ->assertForbidden();

    Volt::actingAs($superAdmin)
        ->test('admin.admins.index')
        ->call('editPageAccess', $admin->id)
        ->set('editPagePermissions', [])
        ->call('savePageAccess')
        ->assertDispatched('close-edit-perms-modal')
        ->assertDispatched('page-access-saved')
        ->assertHasNoErrors();

    $admin->refresh();

    expect($admin->admin_page_permissions)->toBe([])
        ->and($admin->permissions)->toBe([])
        ->and(AdminPages::firstAccessibleRouteName($admin))->toBeNull();

    $this->actingAs($admin)
        ->get(route('admin.ads.index'))
        ->assertForbidden();
});

test('legacy approval page keys still grant the merged validation workflow', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'admin_page_permissions' => [AdminPages::APPROVAL_PAYMENTS],
        'permissions' => AdminPages::capabilitiesForPages([AdminPages::APPROVAL_PAYMENTS]),
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.approvals.index'))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('admin.approvals.payments'))
        ->assertRedirect(route('admin.approvals.index'));
});

test('promoted admin owner can access permitted admin pages and owner workspace', function (): void {
    $owner = User::factory()->create([
        'role' => UserRole::ADMIN,
        'admin_page_permissions' => [AdminPages::APPROVAL_WORKFLOW],
        'permissions' => AdminPages::capabilitiesForPages([AdminPages::APPROVAL_WORKFLOW]),
        'email_verified_at' => now(),
    ]);
    $ownSbo = dualRoleSbo($owner, [
        'name' => 'Own Admin Owner SBO',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);
    $otherOwner = User::factory()->create(['role' => UserRole::SBO_MANAGER, 'email_verified_at' => now()]);
    dualRoleSbo($otherOwner, ['name' => 'Other Owner SBO']);

    Volt::actingAs($owner)
        ->test('admin.approvals.index')
        ->assertOk();

    $this->actingAs($owner)
        ->get(route('sbo.dashboard'))
        ->assertOk()
        ->assertSee('Own Admin Owner SBO')
        ->assertDontSee('Other Owner SBO');

    $this->actingAs($owner)
        ->get(route('sbo.applications.index'))
        ->assertOk()
        ->assertSee('Own Admin Owner SBO')
        ->assertDontSee('Other Owner SBO');

    $this->actingAs($owner)
        ->get(route('sbo.applications.progress', $ownSbo))
        ->assertOk()
        ->assertSee('Own Admin Owner SBO');
});

test('a promoted admin is staff, so the member paywall does not hold their own unpaid listing against them', function (): void {
    $owner = User::factory()->create([
        'role' => UserRole::ADMIN,
        'admin_page_permissions' => [AdminPages::APPROVAL_WORKFLOW],
        'permissions' => AdminPages::capabilitiesForPages([AdminPages::APPROVAL_WORKFLOW]),
        'email_verified_at' => now(),
    ]);
    $sbo = dualRoleSbo($owner, ['name' => 'Unpaid Admin Owner SBO']);

    /*
     * The registry is paid to use, but the gate is on membership, not on staff:
     * SboWorkspaceAccess::requiresPayment() exempts admins outright. So owning
     * an unpaid SBO does not lock a promoted admin out of the platform — it
     * only leaves that one application unpaid.
     */
    $this->actingAs($owner)->get(route('dashboard'))->assertOk();

    /*
     * The wall is still reachable and still about their application: they are
     * not blocked on payment, but they are blocked on verification (an unpaid,
     * unapproved SBO cannot use the network), which is the second gate that
     * lands here.
     */
    $response = $this->actingAs($owner)
        ->get(route('sbo.payment-required'))
        ->assertOk();

    expect(inertiaProp($response, 'reason'))->toBe('verification');

    $response->assertInertia(fn ($page) => $page->component('account/PaymentRequiredView'));

    expect(inertiaProp($response, 'progressUrl'))->toBe(route('sbo.applications.progress', $sbo))
        ->and(inertiaPluck($response, 'application', 'value'))->toContain('Unpaid Admin Owner SBO');

    Volt::actingAs($owner)
        ->test('admin.approvals.index')
        ->assertOk();
});

test('admin owner sees workspace switchers and submitter progress actions for own sbo', function (): void {
    $owner = User::factory()->create([
        'role' => UserRole::ADMIN,
        'admin_page_permissions' => [AdminPages::ORGANIZATIONS],
        'permissions' => AdminPages::capabilitiesForPages([AdminPages::ORGANIZATIONS]),
        'email_verified_at' => now(),
    ]);
    $sbo = dualRoleSbo($owner, [
        'name' => 'Switcher Admin Owner SBO',
        'verification_status' => 'info_requested',
        'info_request' => 'Please update your contact details.',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('admin.sbos.index'))
        ->assertOk()
        ->assertSee('Admin Workspace')
        ->assertSee('SBO Owner Workspace')
        ->assertSee(route('sbo.dashboard'), false);

    /*
     * Home is Vue now, and the switcher was a Blade component, so this had
     * become a dead end: an admin-owner who landed on any SPA page had no way
     * back to /admin short of typing the URL. Both halves are shared props
     * read by AppHeader's profile menu.
     */
    $response = $this->actingAs($owner)->get(route('home'))->assertOk();

    expect(inertiaProp($response, 'auth.user.ownerWorkspace'))->toBeTrue()
        ->and(inertiaProp($response, 'auth.user.adminUrl'))->toBe(route('admin.sbos.index'));

    $progress = $this->actingAs($owner)
        ->get(route('sbo.applications.progress', $sbo))
        ->assertOk();

    // The submitter's own actions: the info request is relayed verbatim, and
    // the screen is not in the read-only mode shown to a non-submitter.
    expect(inertiaProp($progress, 'status.infoRequest'))->toBe('Please update your contact details.')
        ->and(inertiaProp($progress, 'status.verification'))->toBe('info_requested')
        ->and(inertiaProp($progress, 'canResubmit'))->toBeTrue();

    // And the action behind the button actually moves the application on.
    $this->actingAs($owner)
        ->post(route('sbo.applications.resubmit', $sbo))
        ->assertRedirect();

    expect($sbo->refresh()->verification_status)->toBe('pending')
        ->and($sbo->resubmitted_at)->not->toBeNull();
});

test('regular admins without sbos do not see the workspace switcher', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'admin_page_permissions' => [AdminPages::ORGANIZATIONS],
        'permissions' => AdminPages::capabilitiesForPages([AdminPages::ORGANIZATIONS]),
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.sbos.index'))
        ->assertOk()
        ->assertDontSee('SBO Owner Workspace');
});

test('user details expose promote to admin action for non admin users', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $target = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
    dualRoleSbo($target, ['name' => 'Promote From Users SBO']);

    Volt::actingAs($superAdmin)
        ->test('admin.users.index')
        ->call('viewUser', $target->id)
        ->assertSee('Promote to Admin')
        ->assertSee(route('admin.admins.index', ['promote' => $target->id]), false);
});
