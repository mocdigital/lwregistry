<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\Sbo;
use App\Models\Testimonial;
use App\Models\User;
use App\Notifications\SubmitTestimonialNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Won-bid testimonials: the winner is asked, chooses whether to be shown, and
 * the awarded-bids page and home carousel show only what was consented.
 */

function tmBuyer(): User
{
    return paidMember([], ['name' => 'PO ' . Str::random(5), 'category' => 'Legal Consultants']);
}

function tmRfq(Sbo $sbo, array $overrides = []): PurchaseOrder
{
    $category = Category::query()->firstOrCreate(
        ['slug' => 'technology-services'],
        ['name' => 'Technology Services', 'business_count' => 0],
    );

    return PurchaseOrder::create(array_merge([
        'sbo_id' => $sbo->id,
        'category_id' => $category->id,
        'title' => 'Request ' . Str::random(6),
        'description' => 'Work to be done.',
        'budget' => '5000',
        'order_deadline' => now()->addMonths(6)->toDateString(),
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
        'request_type' => 'service',
    ], $overrides));
}

function winningBidFor(PurchaseOrder $order, User $winner): PurchaseOrderBid
{
    return PurchaseOrderBid::create([
        'purchase_order_id' => $order->id,
        'user_id' => $winner->id,
        'bidder_sbo_id' => $winner->sbos()->first()->id,
        'proposal' => 'We can deliver this.',
        'status' => 'submitted',
        'submitted_at' => now(),
    ]);
}

function awardTo(PurchaseOrder $order, PurchaseOrderBid $bid): void
{
    $order->forceFill([
        'awarded_bid_id' => $bid->id,
        'status' => 'awarded',
        'completed_at' => now(),
    ])->save();
    $bid->forceFill(['status' => 'accepted'])->save();
}

test('winning a bid prompts the winner for a testimonial', function (): void {
    Notification::fake();

    $buyer = tmBuyer();
    $winner = tmBuyer();
    $order = tmRfq($buyer->sbos()->first());
    $bid = winningBidFor($order, $winner);

    $this->actingAs($buyer)
        ->post(route('procurement.award', $order), ['bid_id' => $bid->id])
        ->assertRedirect();

    Notification::assertSentTo($winner, SubmitTestimonialNotification::class);
});

test('only the winning business can leave a testimonial', function (): void {
    $buyer = tmBuyer();
    $winner = tmBuyer();
    $order = tmRfq($buyer->sbos()->first());
    $bid = winningBidFor($order, $winner);
    awardTo($order, $bid);

    // A stranger cannot.
    $this->actingAs(tmBuyer())
        ->post(route('procurement.testimonial.store', $order), [
            'rating' => 5, 'headline' => 'x', 'body' => 'y', 'display_publicly' => true,
        ])->assertForbidden();

    // The winner can, and it is stored against their business.
    $this->actingAs($winner)
        ->post(route('procurement.testimonial.store', $order), [
            'rating' => 5,
            'headline' => 'A smooth engagement',
            'body' => 'Clear scope and prompt payment.',
            'display_publicly' => true,
        ])->assertRedirect();

    $testimonial = Testimonial::firstOrFail();
    expect($testimonial->sbo_id)->toBe($winner->sbos()->first()->id)
        ->and($testimonial->rating)->toBe(5)
        ->and($testimonial->display_publicly)->toBeTrue();
});

test('re-submitting updates the one testimonial rather than adding another', function (): void {
    $winner = tmBuyer();
    $order = tmRfq(tmBuyer()->sbos()->first());
    awardTo($order, winningBidFor($order, $winner));

    foreach (['First take', 'Second take'] as $headline) {
        $this->actingAs($winner)->post(route('procurement.testimonial.store', $order), [
            'rating' => 4, 'headline' => $headline, 'body' => 'Good.', 'display_publicly' => false,
        ])->assertRedirect();
    }

    expect(Testimonial::count())->toBe(1)
        ->and(Testimonial::first()->headline)->toBe('Second take');
});

test('the awarded page lists public awards but not private ones', function (): void {
    $winner = tmBuyer();
    $public = tmRfq(tmBuyer()->sbos()->first(), ['title' => 'Public RFQ']);
    awardTo($public, winningBidFor($public, $winner));

    $private = tmRfq(tmBuyer()->sbos()->first(), [
        'title' => 'Confidential RFQ',
        'visibility' => PurchaseOrder::VISIBILITY_INVITE_ONLY,
    ]);
    awardTo($private, winningBidFor($private, tmBuyer()));

    $this->actingAs($winner)->get(route('procurement.awarded'))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $titles = collect($page->toArray()['props']['awards'])->pluck('title');
            expect($titles)->toContain('Public RFQ')
                ->and($titles)->not->toContain('Confidential RFQ');
        });
});

test('the awarded page shows a consented quote but hides a declined one', function (): void {
    $shown = tmBuyer();
    $shownOrder = tmRfq(tmBuyer()->sbos()->first(), ['title' => 'Shown']);
    awardTo($shownOrder, winningBidFor($shownOrder, $shown));
    $this->actingAs($shown)->post(route('procurement.testimonial.store', $shownOrder), [
        'rating' => 5, 'headline' => 'Public words', 'body' => 'Great.', 'display_publicly' => true,
    ]);

    $hidden = tmBuyer();
    $hiddenOrder = tmRfq(tmBuyer()->sbos()->first(), ['title' => 'Hidden']);
    awardTo($hiddenOrder, winningBidFor($hiddenOrder, $hidden));
    $this->actingAs($hidden)->post(route('procurement.testimonial.store', $hiddenOrder), [
        'rating' => 5, 'headline' => 'Private words', 'body' => 'Also great.', 'display_publicly' => false,
    ]);

    $this->actingAs($shown)->get(route('procurement.awarded'))
        ->assertInertia(function ($page): void {
            $awards = collect($page->toArray()['props']['awards'])->keyBy('title');
            expect($awards['Shown']['testimonial'])->not->toBeNull()
                ->and($awards['Shown']['testimonial']['headline'])->toBe('Public words')
                // Declined: the award is listed, but the quote is withheld.
                ->and($awards['Hidden']['testimonial'])->toBeNull();
        });
});

test('the home page carousel shows only consented testimonials', function (): void {
    $shown = tmBuyer();
    $shownOrder = tmRfq(tmBuyer()->sbos()->first());
    awardTo($shownOrder, winningBidFor($shownOrder, $shown));
    $this->actingAs($shown)->post(route('procurement.testimonial.store', $shownOrder), [
        'rating' => 5, 'headline' => 'On the home page', 'body' => 'Yes.', 'display_publicly' => true,
    ]);

    $hidden = tmBuyer();
    $hiddenOrder = tmRfq(tmBuyer()->sbos()->first());
    awardTo($hiddenOrder, winningBidFor($hiddenOrder, $hidden));
    $this->actingAs($hidden)->post(route('procurement.testimonial.store', $hiddenOrder), [
        'rating' => 5, 'headline' => 'Not on the home page', 'body' => 'No.', 'display_publicly' => false,
    ]);

    $this->get('/')->assertOk()->assertInertia(function ($page): void {
        $headlines = collect($page->toArray()['props']['testimonials'])->pluck('headline');
        expect($headlines)->toContain('On the home page')
            ->and($headlines)->not->toContain('Not on the home page');
    });
});
