<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\PurchaseOrderQuestion;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * The RFQ detail's last two dead tabs.
 *
 * Q&A was fully designed with no table behind it, and the Interest tab listed
 * six invented vendors with a "View Details" button that did nothing. Both are
 * real now — and View Details can only work because the bid wizard stores its
 * answers as fields rather than flattening them into one text blob.
 */

function qaUser(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

function qaSbo(User $owner, string $name): Sbo
{
    return Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
        'address' => '9 Marina Road',
        'city' => 'Lagos',
        'country' => 'Nigeria',
    ]);
}

/** A buyer with an open RFQ, and a separate bidder. */
function qaScenario(): array
{
    $buyer = qaUser();
    $buyerSbo = qaSbo($buyer, 'Buyer Ltd ' . Str::random(4));
    $category = Category::firstOrCreate(['name' => 'Technology Services'], ['slug' => 'technology-services']);

    $order = PurchaseOrder::create([
        'sbo_id' => $buyerSbo->id,
        'category_id' => $category->id,
        'title' => 'Supply media equipment',
        'description' => 'Spec attached.',
        'request_type' => 'product',
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
    ]);

    $bidder = qaUser();
    $bidderSbo = qaSbo($bidder, 'Bidder Ltd ' . Str::random(4));

    return [$buyer, $buyerSbo, $order, $bidder, $bidderSbo];
}

/* --------------------------------------------------------------- Q&A ----- */

test('a member can ask a question and the buyer can answer it', function (): void {
    [$buyer, , $order, $bidder] = qaScenario();

    $this->actingAs($bidder)
        ->from(route('procurement.show', $order))
        ->post(route('procurement.questions.store', $order), [
            'title' => 'Format clarification',
            'body' => 'Is the appendix inside the page limit?',
        ])
        ->assertRedirect();

    $question = PurchaseOrderQuestion::first();
    expect($question->body)->toBe('Is the appendix inside the page limit?')
        ->and($question->isAnswered())->toBeFalse();

    $this->actingAs($buyer)
        ->from(route('procurement.show', $order))
        ->post(route('procurement.questions.answer', [$order, $question]), [
            'answer' => 'Only the resumes are excluded.',
        ])
        ->assertRedirect();

    expect($question->fresh()->isAnswered())->toBeTrue()
        ->and($question->fresh()->answer)->toBe('Only the resumes are excluded.');
});

test('the buyer cannot ask on their own request, and a rival cannot answer', function (): void {
    [$buyer, , $order, $bidder] = qaScenario();

    $this->actingAs($buyer)
        ->post(route('procurement.questions.store', $order), ['body' => 'Asking myself?'])
        ->assertForbidden();

    $question = PurchaseOrderQuestion::create([
        'purchase_order_id' => $order->id,
        'user_id' => $bidder->id,
        'body' => 'A question',
    ]);

    $this->actingAs($bidder)
        ->post(route('procurement.questions.answer', [$order, $question]), ['answer' => 'Mine'])
        ->assertForbidden();

    expect($question->fresh()->isAnswered())->toBeFalse();
});

test('the asker is named to the buyer and anonymous to everyone else', function (): void {
    [$buyer, , $order, $bidder, $bidderSbo] = qaScenario();

    PurchaseOrderQuestion::create([
        'purchase_order_id' => $order->id,
        'user_id' => $bidder->id,
        'sbo_id' => $bidderSbo->id,
        'body' => 'Who can see me?',
    ]);

    // The design states this rule on the screen itself.
    $this->actingAs($buyer)
        ->get(route('procurement.show', ['procurement' => $order->id, 'tab' => 'qa']))
        ->assertInertia(fn ($page) => $page->where('questions.0.author', $bidderSbo->name));

    $stranger = qaUser();
    qaSbo($stranger, 'Stranger Ltd ' . Str::random(4));

    $this->actingAs($stranger)
        ->get(route('procurement.show', ['procurement' => $order->id, 'tab' => 'qa']))
        ->assertInertia(fn ($page) => $page->where('questions.0.author', 'Anonymous'));

    // The asker still sees their own name on their own row.
    $this->actingAs($bidder)
        ->get(route('procurement.show', ['procurement' => $order->id, 'tab' => 'qa']))
        ->assertInertia(fn ($page) => $page
            ->where('questions.0.author', $bidderSbo->name)
            ->where('questions.0.mine', true));
});

/* ---------------------------------------------------------- Interest ----- */

test('the bid wizard stores its answers as fields, not just a text blob', function (): void {
    [, , $order, $bidder, $bidderSbo] = qaScenario();

    $this->actingAs($bidder)->post(route('procurement.bid.store', $order), [
        'bidder_sbo_id' => $bidderSbo->id,
        'proposal' => 'The composed document.',
        'details' => [
            'contact' => ['primaryName' => 'Ada Obi', 'primaryEmail' => 'ada@example.test'],
            'company' => ['legalName' => 'Bidder Ltd', 'employees' => '100 - 499'],
            'declaration' => ['signature' => 'Ada Obi', 'certified' => true],
        ],
    ])->assertRedirect();

    $bid = PurchaseOrderBid::first();

    expect($bid->details)->toBeArray()
        ->and($bid->details['contact']['primaryName'])->toBe('Ada Obi')
        ->and($bid->details['company']['legalName'])->toBe('Bidder Ltd')
        // The readable document is kept too — it is what a buyer reads.
        ->and($bid->proposal)->toBe('The composed document.');
});

test('View Details shows the submitted bid to the buyer and to its own bidder', function (): void {
    [$buyer, , $order, $bidder, $bidderSbo] = qaScenario();

    PurchaseOrderBid::create([
        'purchase_order_id' => $order->id,
        'user_id' => $bidder->id,
        'bidder_sbo_id' => $bidderSbo->id,
        'proposal' => 'Our proposal.',
        'details' => ['contact' => ['primaryName' => 'Ada Obi']],
        'status' => 'submitted',
        'submitted_at' => now(),
    ]);

    foreach ([$buyer, $bidder] as $viewer) {
        $this->actingAs($viewer)
            ->get(route('procurement.show', ['procurement' => $order->id, 'tab' => 'interest']))
            ->assertInertia(fn ($page) => $page
                ->where('interest.0.vendor', $bidderSbo->name)
                ->where('interest.0.canView', true)
                ->where('interest.0.details.contact.primaryName', 'Ada Obi'));
    }
});

test("a rival bidder cannot read someone else's submission", function (): void {
    [, , $order, $bidder, $bidderSbo] = qaScenario();

    PurchaseOrderBid::create([
        'purchase_order_id' => $order->id,
        'user_id' => $bidder->id,
        'bidder_sbo_id' => $bidderSbo->id,
        'proposal' => 'Our pricing and approach.',
        'details' => ['contact' => ['primaryName' => 'Ada Obi']],
        'status' => 'submitted',
        'submitted_at' => now(),
    ]);

    $rival = qaUser();
    qaSbo($rival, 'Rival Ltd ' . Str::random(4));

    $this->actingAs($rival)
        ->get(route('procurement.show', ['procurement' => $order->id, 'tab' => 'interest']))
        ->assertInertia(fn ($page) => $page
            // They can see that someone bid — that is the point of the tab —
            // but not what was bid, nor the contact address.
            ->where('interest.0.vendor', $bidderSbo->name)
            ->where('interest.0.canView', false)
            ->where('interest.0.details', null)
            ->where('interest.0.proposal', null)
            ->where('interest.0.email', null));
});
