<?php

declare(strict_types=1);

use App\Models\AdvertisingInquiry;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
 * The advertising inquiry, end to end.
 *
 * The endpoint and the table existed; what did not was any way to SEE the
 * result. The confirmation was flashed under a key the Inertia middleware did
 * not share, so a visitor who filled the form in correctly watched it do
 * nothing — and the only copy of their inquiry was an email.
 */

beforeEach(function (): void {
    Notification::fake();
    config()->set('turnstile.secret_key', null);
});

function inquiryPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ada Okafor',
        'kc_handle' => 'ada.okafor',
        'email' => 'ada@example.test',
        'ad_location' => array_key_first(config('ads.slots')),
        'message' => 'We would like the hero billboard for Q4.',
    ], $overrides);
}

test('a member sees their inquiry confirmed rather than silently filed', function (): void {
    $user = paidMember();

    $this->actingAs($user)
        ->post(route('advertise.inquiry'), inquiryPayload())
        ->assertRedirect();

    // The row lands...
    expect(AdvertisingInquiry::where('email', 'ada@example.test')->exists())->toBeTrue();

    // ...and the sender is told so, through a key the middleware actually shares.
    $this->actingAs($user)
        ->get(route('advertise'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'flash.advertisingInquiry',
            'Your advertising inquiry has been sent. Our team will contact you shortly.',
        ));
});

test('a guest is told to register instead of having the inquiry dropped', function (): void {
    $this->post(route('advertise.inquiry'), inquiryPayload())->assertRedirect();

    expect(AdvertisingInquiry::count())->toBe(0);

    $this->get(route('advertise'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'flash.advertisingAuthMessage',
            'Please register your business or log in before sending an advertising inquiry. Payment is not required.',
        ));
});

test('an inquiry for a placement that is not configured is rejected', function (): void {
    $this->actingAs(paidMember())
        ->post(route('advertise.inquiry'), inquiryPayload(['ad_location' => 'roof-of-the-building']))
        ->assertSessionHasErrors('ad_location');

    expect(AdvertisingInquiry::count())->toBe(0);
});

test('the admin queue lists inquiries and can triage one', function (): void {
    // Super admin: the page sits behind MANAGE_ADS, same as Ad Placements.
    $admin = User::factory()->create([
        'role' => App\Enums\UserRole::SUPER_ADMIN->value,
        'email_verified_at' => now(),
    ]);

    $inquiry = AdvertisingInquiry::create([
        'user_id' => $admin->id,
        'name' => 'Ada Okafor',
        'kc_handle' => 'ada.okafor',
        'email' => 'ada@example.test',
        'ad_location' => array_key_first(config('ads.slots')),
        'message' => 'We would like the hero billboard for Q4.',
        'status' => AdvertisingInquiry::STATUS_NEW,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.ads.inquiries'))
        ->assertOk()
        ->assertSee('Ada Okafor')
        ->assertSee('Advertising inquiries');

    Livewire\Volt\Volt::actingAs($admin)
        ->test('admin.ads.inquiries')
        ->call('select', $inquiry->id)
        ->set('adminNotes', 'Called back, quoting Q4 rate card.')
        ->call('saveNotes')
        ->call('markReviewed');

    $inquiry->refresh();

    expect($inquiry->status)->toBe(AdvertisingInquiry::STATUS_REVIEWED)
        ->and($inquiry->admin_notes)->toBe('Called back, quoting Q4 rate card.')
        ->and($inquiry->reviewed_at)->not->toBeNull();
});
