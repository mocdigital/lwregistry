<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Support\AdminPages;
use Illuminate\Support\Facades\Storage;

test('admin layout renders mobile sidebar toggle controls', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('profile-photos/admin.png', 'avatar');

    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN->value,
        'email_verified_at' => now(),
        'profile_photo_path' => 'profile-photos/admin.png',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('css/admin.css?v=1.1', false)
        ->assertSee('css/form-help.css?v=3', false)
        ->assertSee('css/notification-bell.css?v=2', false)
        ->assertSee('js/form-help.js?v=4', false)
        ->assertSee('lw-notification-bell', false)
        ->assertSee('Browser alerts')
        ->assertSee('id="sidebarToggle"', false)
        ->assertSee('aria-controls="adminSidebar"', false)
        ->assertSee('aria-expanded="false"', false)
        ->assertSee('aria-label="Open sidebar menu"', false)
        ->assertSee('data-admin-sidebar-toggle-label', false)
        ->assertSee('Menu')
        ->assertSee('id="adminSidebar"', false)
        ->assertSee('data-admin-sidebar-close', false)
        ->assertSee('aria-label="Close sidebar menu"', false)
        ->assertSee('data-admin-sidebar-backdrop', false)
        ->assertSee('Application approvals')
        ->assertSee('Validation approvals')
        ->assertSee('Info requests')
        ->assertSee('Rejected history')
        ->assertSee('Payments')
        ->assertSee('Espees Configuration')
        ->assertSee('Feedback')
        ->assertSee(route('admin.approvals.index'), false)
        ->assertDontSee(route('admin.approvals.payments'), false)
        ->assertDontSee(route('admin.approvals.documents'), false)
        ->assertDontSee(route('admin.approvals.final-verification'), false)
        ->assertSee(route('admin.approvals.info-requests'), false)
        ->assertSee(route('admin.approvals.rejected-history'), false)
        ->assertSee(route('admin.growth.payments'), false)
        ->assertSee(route('admin.fees.index') . '#espees-checkout', false)
        ->assertSee(route('admin.support.feedback'), false)
        ->assertSee('profile-photos/admin.png', false);
});

test('ordinary admins see the growth section in the sidebar', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::ADMIN->value,
        'email_verified_at' => now(),
        'admin_page_permissions' => [AdminPages::ZONE_REFERRALS, AdminPages::ZONE_MOMENTUM],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.zones.referrals.index'))
        ->assertOk()
        ->assertSee('Growth')
        ->assertSee('Zone Referrals')
        ->assertSee('Zone Momentum')
        ->assertSee(route('admin.zones.referrals.index'), false)
        ->assertSee(route('admin.zones.momentum.index'), false);
});
