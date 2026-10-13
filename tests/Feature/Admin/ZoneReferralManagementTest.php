<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\ZoneReferralLinkNotification;
use App\Support\AdminPages;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN->value]);
    $this->zone = Zone::create(['name' => 'Admin Zone', 'code' => 'ADMZ01', 'is_active' => true]);
});

describe('authorization', function (): void {
    it('forbids non-admin users', function (): void {
        $user = User::factory()->create(['role' => UserRole::SBO_MANAGER->value]);

        Volt::actingAs($user)->test('admin.zones.referrals')->assertForbidden();
        Volt::actingAs($user)->test('admin.zones.momentum')->assertForbidden();
    });

    it('lets any admin open both growth pages', function (): void {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN->value,
            'admin_page_permissions' => [AdminPages::ZONE_REFERRALS, AdminPages::ZONE_MOMENTUM],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.zones.referrals.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.zones.momentum.index'))
            ->assertOk();

        Volt::actingAs($admin)->test('admin.zones.referrals')->assertOk();
        Volt::actingAs($admin)->test('admin.zones.momentum')->assertOk();
    });

    it('blocks admins who have not been granted growth pages', function (): void {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN->value,
            'admin_page_permissions' => [AdminPages::SUPPORT],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.zones.referrals.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.zones.momentum.index'))
            ->assertForbidden();

        Volt::actingAs($admin)->test('admin.zones.referrals')->assertForbidden();
        Volt::actingAs($admin)->test('admin.zones.momentum')->assertForbidden();
    });

    it('lets a super admin open both pages', function (): void {
        Volt::actingAs($this->admin)->test('admin.zones.referrals')->assertOk();
        Volt::actingAs($this->admin)->test('admin.zones.momentum')->assertOk();
    });

    it('lists zones in a table with a copyable referral link per zone', function (): void {
        Volt::actingAs($this->admin)->test('admin.zones.referrals')
            ->assertSee('Referral link')
            ->assertSee($this->zone->name)
            ->assertSee($this->zone->referralUrl())
            ->assertSeeHtml('copyLink(');
    });

    it('filters referral rows through live search state', function (): void {
        $this->zone->update([
            'pastor_name' => 'Pastor Admin',
            'pastor_email' => 'admin@zone.test',
        ]);

        Zone::create([
            'name' => 'Beta Zone',
            'code' => 'BETA01',
            'pastor_name' => 'Pastor Beta',
            'pastor_email' => 'beta@zone.test',
            'is_active' => true,
        ]);

        Volt::actingAs($this->admin)->test('admin.zones.referrals')
            ->assertSeeHtml('wire:submit.prevent')
            ->assertSeeHtml('wire:keydown.enter.prevent')
            ->assertSee('Admin Zone')
            ->set('search', 'admin@zone.test')
            ->assertSet('search', 'admin@zone.test')
            ->assertSee('Admin Zone')
            ->assertDontSee('Beta Zone')
            ->set('search', 'BETA01')
            ->assertSee('Beta Zone')
            ->assertDontSee('Admin Zone')
            ->call('clearSearch')
            ->assertSet('search', '')
            ->assertSee('Referral link');
    });
});

describe('pastor contact + sending', function (): void {
    it('saves a pastor name and email', function (): void {
        Volt::actingAs($this->admin)->test('admin.zones.referrals')
            ->call('editPastor', $this->zone->id)
            ->set('editPastorName', 'Pastor Joe')
            ->set('editPastorEmail', 'joe@zone.test')
            ->call('savePastor')
            ->assertHasNoErrors();

        $this->zone->refresh();
        expect($this->zone->pastor_name)->toBe('Pastor Joe')
            ->and($this->zone->pastor_email)->toBe('joe@zone.test');
    });

    it('sends the referral link to a typed email and stamps sent_at', function (): void {
        Notification::fake();

        Volt::actingAs($this->admin)->test('admin.zones.referrals')
            ->call('startSend', $this->zone->id)
            ->set('sendEmail', 'pastor@zone.test')
            ->call('sendLink')
            ->assertHasNoErrors();

        Notification::assertSentOnDemand(ZoneReferralLinkNotification::class);
        expect($this->zone->fresh()->sent_at)->not->toBeNull();
    });

    it('bulk-sends only to active zones that have a pastor email', function (): void {
        Notification::fake();

        $this->zone->update(['pastor_email' => 'a@zone.test']);
        Zone::create(['name' => 'No Email Zone', 'code' => 'NOEML1', 'is_active' => true]);
        Zone::create(['name' => 'Inactive', 'code' => 'INACT1', 'is_active' => false, 'pastor_email' => 'x@zone.test']);

        Volt::actingAs($this->admin)->test('admin.zones.referrals')
            ->call('sendToAll')
            ->assertHasNoErrors();

        // Only the one active zone with an email should be notified.
        Notification::assertSentOnDemandTimes(ZoneReferralLinkNotification::class, 1);
    });
});

describe('code + status', function (): void {
    it('regenerates the referral code', function (): void {
        $old = $this->zone->code;

        Volt::actingAs($this->admin)->test('admin.zones.referrals')
            ->call('regenerateCode', $this->zone->id);

        expect($this->zone->fresh()->code)->not->toBe($old);
    });

    it('toggles active state', function (): void {
        Volt::actingAs($this->admin)->test('admin.zones.referrals')
            ->call('toggleActive', $this->zone->id);

        expect($this->zone->fresh()->is_active)->toBeFalse();
    });
});
