<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\SupportEnquiry;
use App\Models\User;
use App\Notifications\SupportInquiryEscalatedNotification;
use App\Notifications\SupportInquiryRespondedNotification;
use App\Notifications\SupportInquirySubmittedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

function supportUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'firstname' => 'Ada',
        'lastname' => 'Okafor',
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ], $overrides));
}

function supportAdmin(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::ADMIN,
        'email_verified_at' => now(),
    ], $overrides));
}

function supportSuperAdmin(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ], $overrides));
}

function supportSbo(User $owner, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Support SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'application_id' => 'MBN-2026-' . random_int(1000, 9999),
        'moc_registration_number' => null,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ], $overrides));
}

test('the support page is open to guests, with no user prefilled', function (): void {
    // Support is deliberately one of the four pages outside the paywall — a
    // member locked out by a payment problem has to be able to report it.
    $response = $this->get(route('support.index'))->assertOk();

    expect(inertiaProp($response, 'user'))->toBeNull();
});

test('a signed-in member has their contact details prefilled', function (): void {
    $user = supportUser();
    supportSbo($user, ['payment_status' => 'paid', 'payment_confirmed_at' => now()]);

    $response = $this->actingAs($user)->get(route('support.index'))->assertOk();

    expect(inertiaProp($response, 'user.email'))->toBe($user->email)
        ->and(inertiaProp($response, 'user.firstname'))->toBe($user->firstname);
});

test('authenticated user can submit a support enquiry and admins are notified', function (): void {
    Notification::fake();

    $user = supportUser();
    $admin = supportAdmin();
    $sbo = supportSbo($user, ['application_id' => 'MBN-2026-1200']);

    Livewire::actingAs($user)
        ->test('support.index')
        ->set('firstName', 'Ada')
        ->set('lastName', 'Okafor')
        ->set('sboNumber', $sbo->application_id)
        ->set('subject', 'Document upload issue')
        ->set('message', 'The upload button does not respond.')
        ->call('submit')
        ->assertHasNoErrors();

    $enquiry = SupportEnquiry::first();

    expect($enquiry)->not->toBeNull();
    expect($enquiry->sbo_id)->toBe($sbo->id);
    expect($enquiry->status)->toBe(SupportEnquiry::STATUS_OPEN);

    Notification::assertSentTo($admin, SupportInquirySubmittedNotification::class);
});

test('support form accepts moc registration number and selecting owned sbo autofills the number', function (): void {
    $user = supportUser();
    $sbo = supportSbo($user, [
        'application_id' => 'MBN-2026-2200',
        'moc_registration_number' => 'MOC-2026-2200',
    ]);

    Livewire::actingAs($user)
        ->test('support.index')
        ->set('selectedSboId', (string) $sbo->id)
        ->assertSet('sboNumber', 'MOC-2026-2200')
        ->set('subject', 'Verification question')
        ->set('message', 'Please confirm my status.')
        ->call('submit')
        ->assertHasNoErrors();

    expect(SupportEnquiry::first()->sbo_id)->toBe($sbo->id);
    expect(SupportEnquiry::first()->sbo_number)->toBe('MOC-2026-2200');
});

test('user cannot associate another users sbo number', function (): void {
    $owner = supportUser();
    $other = supportUser(['email' => 'other-support@example.com']);
    $sbo = supportSbo($owner, ['application_id' => 'MBN-2026-3300']);

    Livewire::actingAs($other)
        ->test('support.index')
        ->set('firstName', 'Other')
        ->set('lastName', 'User')
        ->set('sboNumber', $sbo->application_id)
        ->set('subject', 'Wrong association')
        ->set('message', 'This should not attach.')
        ->call('submit')
        ->assertHasErrors(['sboNumber']);

    expect(SupportEnquiry::count())->toBe(0);
});

test('support links point to the support page', function (): void {
    $user = supportUser();
    // Approved and live, so their dashboard is reachable (the workspace is
    // locked until approval).
    supportSbo($user, [
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    // The links themselves live in the Vue shell (AppHeader's nav, plus the
    // in-page prompts on the application, payment-wall and RFQ screens), so
    // they cannot be asserted in server-rendered HTML any more. What the
    // server still owes them is the route, unauthenticated and named.
    expect(Route::has('support.index'))->toBeTrue();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('support.index'))->assertOk();

    // Every `href="/support"` in the shell must resolve, so the path is
    // pinned rather than only the route name.
    expect(route('support.index', absolute: false))->toBe('/support');
});

test('admin can respond mark done and escalate support enquiries', function (): void {
    Notification::fake();

    $user = supportUser();
    $admin = supportAdmin();
    $superAdmin = supportSuperAdmin();
    $enquiry = SupportEnquiry::create([
        'user_id' => $user->id,
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'sbo_number' => 'MBN-2026-4400',
        'subject' => 'Need help',
        'message' => 'Please assist.',
        'status' => SupportEnquiry::STATUS_OPEN,
    ]);

    Livewire::actingAs($admin)
        ->test('admin.support.index')
        ->call('select', $enquiry->id)
        ->set('response', 'We have resolved this for you.')
        ->call('respond')
        ->assertHasNoErrors();

    $enquiry->refresh();

    expect($enquiry->status)->toBe(SupportEnquiry::STATUS_RESPONDED);
    expect($enquiry->responded_by)->toBe($admin->id);
    expect($enquiry->admin_response)->toBe('We have resolved this for you.');
    Notification::assertSentTo($user, SupportInquiryRespondedNotification::class);

    Livewire::actingAs($admin)
        ->test('admin.support.index')
        ->call('select', $enquiry->id)
        ->call('markDone')
        ->assertHasNoErrors();

    expect($enquiry->fresh()->status)->toBe(SupportEnquiry::STATUS_DONE);

    Livewire::actingAs($admin)
        ->test('admin.support.index')
        ->call('select', $enquiry->id)
        ->call('escalate')
        ->assertHasNoErrors();

    expect($enquiry->fresh()->status)->toBe(SupportEnquiry::STATUS_ESCALATED);
    Notification::assertSentTo($superAdmin, SupportInquiryEscalatedNotification::class);
});
