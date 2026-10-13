<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * `/notifications` reads the notifications table.
 *
 * The screen shipped with seven hardcoded cards and fixed counts; these cover
 * the three things that were missing — real rows, the filter tabs, and the
 * mark-as-read / delete writes.
 */
/**
 * A paid member. `/notifications` sits behind the payment wall like the rest
 * of the platform, so a user with no confirmed registration fee never reaches
 * the feed — these tests are about the feed, not about the gate
 * (UnpaidOwnerGateTest covers that).
 */
function notifiedUser(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $name = 'Notified SBO ' . Str::random(6);

    App\Models\Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $user->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);

    /*
     * Creating the SBO fires SboObserver, which notifies the owner. That is
     * correct behaviour but it is not what these tests are counting, so the
     * fixture's own side effects are cleared and each test starts from an
     * empty feed.
     */
    $user->notifications()->delete();

    return $user;
}

/** @param array<string, mixed> $data */
function storeNotification(User $user, array $data, ?string $readAt = null): Notification
{
    return Notification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\SboApprovedNotification',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => $data,
        'read_at' => $readAt,
    ]);
}

test('the notifications page renders the signed-in user\'s own rows', function (): void {
    $user = notifiedUser();
    $other = notifiedUser();

    storeNotification($user, [
        'type' => 'sbo_approved',
        'title' => 'Application approved',
        'message' => 'Kemefy LLC was approved.',
    ]);
    storeNotification($other, [
        'type' => 'sbo_approved',
        'title' => 'Someone else',
        'message' => 'Not yours.',
    ]);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('account/NotificationsView')
            ->where('counts.all', 1)
            ->where('counts.unread', 1)
            ->where('groups.0.cards.0.title', 'Application approved')
            ->where('groups.0.cards.0.icon', 'check')
            ->where('groups.0.cards.0.unread', true));
});

test('categories and the action-required flag are derived from the payload type', function (): void {
    $user = notifiedUser();

    storeNotification($user, ['type' => 'sbo_fees_paid', 'title' => 'Paid', 'message' => '']);
    storeNotification($user, ['type' => 'sbo_upload_documents', 'title' => 'Upload', 'message' => '']);
    storeNotification($user, ['type' => 'sbo_message_received', 'title' => 'Message', 'message' => '']);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertInertia(fn ($page) => $page
            ->where('counts.payments', 1)
            ->where('counts.applications', 1)
            ->where('counts.network', 1)
            ->where('counts.action', 1));
});

test('the filter tabs narrow the feed', function (): void {
    $user = notifiedUser();

    storeNotification($user, ['type' => 'sbo_fees_paid', 'title' => 'Paid', 'message' => '']);
    storeNotification($user, ['type' => 'sbo_message_received', 'title' => 'Message', 'message' => '']);

    $this->actingAs($user)
        ->get(route('notifications.index', ['filter' => 'payments']))
        ->assertInertia(fn ($page) => $page
            ->where('filter', 'payments')
            ->where('pagination.total', 1)
            ->where('groups.0.cards.0.title', 'Paid'));
});

test('search matches the title and the body', function (): void {
    $user = notifiedUser();

    storeNotification($user, ['type' => 'sbo_approved', 'title' => 'Approved', 'message' => 'Kemefy LLC']);
    storeNotification($user, ['type' => 'sbo_approved', 'title' => 'Declined', 'message' => 'Other Ltd']);

    $this->actingAs($user)
        ->get(route('notifications.index', ['search' => 'kemefy']))
        ->assertInertia(fn ($page) => $page->where('pagination.total', 1));
});

test('a notification can be marked read', function (): void {
    $user = notifiedUser();
    $notification = storeNotification($user, ['type' => 'sbo_approved', 'title' => 'A', 'message' => '']);

    $this->actingAs($user)
        ->from(route('notifications.index'))
        ->post(route('notifications.read', $notification->id))
        ->assertRedirect(route('notifications.index'));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('every notification can be marked read at once', function (): void {
    $user = notifiedUser();
    storeNotification($user, ['type' => 'sbo_approved', 'title' => 'A', 'message' => '']);
    storeNotification($user, ['type' => 'sbo_approved', 'title' => 'B', 'message' => '']);

    $this->actingAs($user)
        ->from(route('notifications.index'))
        ->post(route('notifications.read-all'))
        ->assertRedirect(route('notifications.index'));

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('a notification can be deleted', function (): void {
    $user = notifiedUser();
    $notification = storeNotification($user, ['type' => 'sbo_approved', 'title' => 'A', 'message' => '']);

    $this->actingAs($user)
        ->from(route('notifications.index'))
        ->delete(route('notifications.destroy', $notification->id))
        ->assertRedirect(route('notifications.index'));

    expect(Notification::whereKey($notification->id)->exists())->toBeFalse();
});

test('another user\'s notification cannot be read or deleted', function (): void {
    $user = notifiedUser();
    $notification = storeNotification(notifiedUser(), ['type' => 'sbo_approved', 'title' => 'A', 'message' => '']);

    $this->actingAs($user)->post(route('notifications.read', $notification->id))->assertNotFound();
    $this->actingAs($user)->delete(route('notifications.destroy', $notification->id))->assertNotFound();

    expect(Notification::whereKey($notification->id)->exists())->toBeTrue();
});

test('guests are sent to sign in', function (): void {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});
