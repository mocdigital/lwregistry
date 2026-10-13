<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Anything a member would open the app to check for has to be in the app.
 *
 * Twenty-two of the forty-two notification classes were mail only. "Your bid
 * was accepted" existed nowhere in the interface — and only arrived at all if
 * the mail queue happened to be running, which mail:doctor reported it was
 * not.
 *
 * The Admin* classes stay on mail deliberately: staff work from the admin
 * panel, and the member's bell is not where an administrator looks.
 */

/**
 * The member-facing classes that must reach the bell.
 *
 * Named rather than discovered, so adding a class does not silently opt out of
 * this — the list is a decision about who each notification is for, and that
 * decision should be written down.
 *
 * @var array<int, string>
 */
const MEMBER_FACING = [
    App\Notifications\BidStatusNotification::class,
    App\Notifications\NewBidReceivedNotification::class,
    App\Notifications\NewProcurementRequestNotification::class,
    App\Notifications\NewReviewNotification::class,
    App\Notifications\SboManagerAssignedNotification::class,
    App\Notifications\AdCampaignApprovedNotification::class,
    App\Notifications\AdCampaignRejectedNotification::class,
];

test('every member-facing notification is stored, not only posted', function (): void {
    $notifiable = User::factory()->create();

    foreach (MEMBER_FACING as $class) {
        $via = (new ReflectionClass($class))->newInstanceWithoutConstructor()->via($notifiable);

        expect($via)->toContain('database')
            ->and($via)->toContain('mail');
    }
});

test('every member-facing notification carries a payload the card can render', function (): void {
    /*
     * A row with no `type` falls back to the "system" category and a generic
     * icon, which is how a notification ends up in the wrong tab looking like
     * something else.
     */
    foreach (MEMBER_FACING as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->hasMethod('toArray'))->toBeTrue($class . ' has no toArray()');
    }
});

test('the new slugs are classified rather than falling through to system', function (): void {
    $slugs = [
        'bid_accepted' => Notification::CATEGORY_NETWORK,
        'bid_declined' => Notification::CATEGORY_NETWORK,
        'bid_received' => Notification::CATEGORY_NETWORK,
        'procurement_posted' => Notification::CATEGORY_NETWORK,
        'review_received' => Notification::CATEGORY_NETWORK,
        'sbo_manager_assigned' => Notification::CATEGORY_NETWORK,
        'ad_campaign_approved' => Notification::CATEGORY_PAYMENTS,
        'ad_campaign_rejected' => Notification::CATEGORY_PAYMENTS,
    ];

    foreach ($slugs as $slug => $expected) {
        $notification = new Notification();
        $notification->data = ['type' => $slug, 'title' => 'x', 'message' => 'y'];

        expect($notification->category())->toBe($expected, $slug . ' is uncategorised')
            // An unmapped slug silently gets the fallback icon, which is how a
            // card ends up looking like something it is not.
            ->and($notification->icon())->not->toBe('chart');
    }
});

test('the two that need the member to act are flagged as such', function (): void {
    foreach (['bid_accepted', 'ad_campaign_rejected'] as $slug) {
        $notification = new Notification();
        $notification->data = ['type' => $slug];

        // Accept the work, or fix the advert and resubmit. Both are the
        // member's move.
        expect($notification->isActionRequired())->toBeTrue();
    }
});

test('an admin notification stays out of the member bell', function (): void {
    $notifiable = User::factory()->create();

    /*
     * Staff work from the admin panel. Putting these in the database channel
     * would mean an administrator who also owns a listing found registry
     * business in their own member notifications.
     */
    foreach ([
        App\Notifications\AdminNewSboNotification::class,
        App\Notifications\AdminFeePaymentSubmittedNotification::class,
        App\Notifications\AdminNewReportNotification::class,
    ] as $class) {
        $via = (new ReflectionClass($class))->newInstanceWithoutConstructor()->via($notifiable);

        expect($via)->not->toContain('database');
    }
});
