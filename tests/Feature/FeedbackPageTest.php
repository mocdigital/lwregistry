<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\AuthModal;
use App\Models\FeedbackSubmission;
use App\Models\User;
use App\Notifications\FeedbackSubmittedNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * The platform is paid to use, so every authed destination sits behind
 * `sbo_payment_approved`. These tests are about the page, not the gate, so
 * their actor is given a paid listing.
 */
function feedbackPagePaidListing(User $user): void
{
    $name = 'Feedback Page SBO ' . Illuminate\Support\Str::random(6);

    App\Models\Sbo::create([
        'name' => $name,
        'slug' => Illuminate\Support\Str::slug($name) . '-' . Illuminate\Support\Str::random(5),
        'created_by' => $user->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);
}

test('feedback is members-only — a guest is sent to sign in', function (): void {
    /*
     * This used to be public with a locked panel. `/feedback` carries `auth`,
     * `verified` and `sbo_payment_approved` now, so a guest never reaches the
     * page to see the panel — they are sent to sign in. (Which also means
     * FeedbackSubmissionController's guest branch is unreachable; see the
     * guest-post test below.)
     */
    $this->get(route('feedback'))->assertRedirect(route('login'));
});

test('authenticated users see the feedback form and category options', function (): void {
    $user = User::factory()->create([
        'firstname' => 'Ada',
        'lastname' => 'User',
        'email' => 'ada.feedback@example.test',
        'kc_handle' => '@ada-feedback',
    ]);

    feedbackPagePaidListing($user);

    $response = $this->actingAs($user)->get(route('feedback'))->assertOk();

    // The endpoint requires kc_handle; the screen was never sent it.
    expect(inertiaProp($response, 'user.name'))->toBe('Ada User')
        ->and(inertiaProp($response, 'user.email'))->toBe('ada.feedback@example.test')
        ->and(inertiaProp($response, 'user.kcHandle'))->toBe('@ada-feedback');

    // And the option lists are the ones the endpoint validates against.
    $types = collect(inertiaProp($response, 'types'));
    expect($types->pluck('value')->all())
        ->toEqualCanonicalizing(array_keys(FeedbackSubmission::typeOptions()))
        ->and($types->pluck('label'))->toContain('Technical issue')
        ->and($types->pluck('label'))->toContain('Business suggestion');

    expect(collect(inertiaProp($response, 'priorities'))->pluck('value')->all())
        ->toEqualCanonicalizing(array_keys(FeedbackSubmission::priorityOptions()));
});

test('guest feedback post redirects back without creating a submission', function (): void {
    Notification::fake();

    $this->post(route('feedback.submit'), [
        'name' => 'Guest User',
        'kc_handle' => '@guest',
        'email' => 'guest@example.test',
        'feedback_type' => FeedbackSubmission::TYPE_TECHNICAL,
        'priority' => FeedbackSubmission::PRIORITY_NORMAL,
        'subject' => 'Guest feedback',
        'message' => 'This should not submit while logged out.',
        // The route requires auth, so a guest is stopped before the controller —
        // its `feedback_auth_required` branch is dead code kept for the Blade era.
    ])->assertRedirect(route('login'));

    expect(FeedbackSubmission::count())->toBe(0);
    Notification::assertNothingSent();
});

test('authenticated feedback submission stores feedback and emails admins', function (): void {
    Notification::fake();

    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'email_verified_at' => now(),
    ]);
    $user = User::factory()->create([
        'firstname' => 'Grace',
        'lastname' => 'Reporter',
        'email' => 'grace@example.test',
        'kc_handle' => '@grace',
    ]);

    $this->actingAs($user)
        ->from(route('feedback') . '#feedback-form')
        ->post(route('feedback.submit'), [
            'name' => 'Grace Reporter',
            'kc_handle' => '@grace',
            'email' => 'grace@example.test',
            'feedback_type' => FeedbackSubmission::TYPE_BUSINESS,
            'priority' => FeedbackSubmission::PRIORITY_HIGH,
            'subject' => 'Improve procurement filters',
            'message' => 'Please add clearer filters for procurement requests.',
            'page_url' => route('sbo.rfqs.index'),
        ])
        ->assertRedirect(route('feedback') . '#feedback-form')
        ->assertSessionHas('feedback_success');

    $feedback = FeedbackSubmission::first();

    expect($feedback)->not->toBeNull();
    expect($feedback->user_id)->toBe($user->id);
    expect($feedback->status)->toBe(FeedbackSubmission::STATUS_NEW);
    expect($feedback->kc_handle)->toBe('@grace');

    Notification::assertSentTo($admin, FeedbackSubmittedNotification::class);
});

test('feedback submission rejects unknown feedback type', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('feedback') . '#feedback-form')
        ->post(route('feedback.submit'), [
            'name' => 'Ada User',
            'kc_handle' => '@ada',
            'email' => 'ada@example.test',
            'feedback_type' => 'unknown',
            'priority' => FeedbackSubmission::PRIORITY_NORMAL,
            'subject' => 'Invalid type',
            'message' => 'This should fail validation.',
        ])
        ->assertRedirect(route('feedback') . '#feedback-form')
        ->assertSessionHasErrors(['feedback_type']);
});

test('admin can view and review feedback submissions', function (): void {
    $admin = User::factory()->superAdmin()->create([
        'email_verified_at' => now(),
    ]);
    $user = User::factory()->create();
    $feedback = FeedbackSubmission::create([
        'user_id' => $user->id,
        'name' => 'Feedback Sender',
        'email' => 'sender@example.test',
        'kc_handle' => '@sender',
        'feedback_type' => FeedbackSubmission::TYPE_TECHNICAL,
        'priority' => FeedbackSubmission::PRIORITY_NORMAL,
        'subject' => 'A page threw an error',
        'message' => 'The directory page showed an error.',
        'status' => FeedbackSubmission::STATUS_NEW,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.support.feedback'))
        ->assertOk()
        ->assertSee('Feedback inbox')
        ->assertSee('A page threw an error');

    Livewire::actingAs($admin)
        ->test('admin.support.feedback')
        ->call('select', $feedback->id)
        ->set('adminNotes', 'Reproduced locally.')
        ->call('saveNotes')
        ->call('markReviewed')
        ->assertHasNoErrors()
        ->assertSee('Feedback marked reviewed.');

    expect($feedback->fresh()->status)->toBe(FeedbackSubmission::STATUS_REVIEWED);
    expect($feedback->fresh()->admin_notes)->toBe('Reproduced locally.');
    expect($feedback->fresh()->reviewed_by)->toBe($admin->id);
});

test('login from feedback redirects back to the feedback form', function (): void {
    $user = User::factory()->create([
        'email' => 'feedback-login@example.test',
        'email_verified_at' => now(),
        'password' => Hash::make('password123'),
    ]);

    Livewire::test(AuthModal::class)
        ->set('loginEmail', $user->email)
        ->set('loginPassword', 'password123')
        ->set('redirectAfterLogin', route('feedback') . '#feedback-form')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('feedback') . '#feedback-form');

    $this->assertAuthenticatedAs($user);
});
