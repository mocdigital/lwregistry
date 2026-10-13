<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDocument;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * /procurement — the filters sidebar, the RFP schedule and the Documents tab.
 *
 * All three were drawn and inert: the keyword box had no model, the category
 * and status rows were display-only spans, "Clear All" did nothing, the sort
 * select drove a local ref, the Timeline was six fixed dates and the Documents
 * tab listed the same two invented files behind a button with no route.
 */

function rfqBuyer(): User
{
    return paidMember([], ['name' => 'Buyer SBO ' . Str::random(4), 'category' => 'Legal Consultants']);
}

function rfq(Sbo $sbo, array $overrides = []): PurchaseOrder
{
    $category = Category::query()->firstOrCreate(
        ['slug' => Str::slug($overrides['category_name'] ?? 'Technology Services')],
        ['name' => $overrides['category_name'] ?? 'Technology Services', 'business_count' => 0],
    );

    unset($overrides['category_name']);

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

function rfqTitles($response): Illuminate\Support\Collection
{
    return inertiaPluck($response, 'requests.data', 'title');
}

/* ------------------------------------------------------------- filters --- */

test('the keyword box searches the request and the business asking', function (): void {
    $buyer = rfqBuyer();
    $sbo = $buyer->sbos()->firstOrFail();

    rfq($sbo, ['title' => 'Solar Panel Installation']);
    rfq($sbo, ['title' => 'Catering Contract', 'description' => 'Solar-powered kitchen equipment.']);
    rfq($sbo, ['title' => 'Office Cleaning']);

    $names = rfqTitles(
        $this->actingAs($buyer)->get(route('procurement.index', ['search' => 'Solar']))->assertOk()
    );

    expect($names)->toContain('Solar Panel Installation')
        ->toContain('Catering Contract')
        ->not->toContain('Office Cleaning');
});

test('the category rows filter, and the counts are real', function (): void {
    $buyer = rfqBuyer();
    $sbo = $buyer->sbos()->firstOrFail();

    rfq($sbo, ['title' => 'Tech Request', 'category_name' => 'Technology Services']);
    rfq($sbo, ['title' => 'Legal Request', 'category_name' => 'Legal Consultants']);

    $response = $this->actingAs($buyer)
        ->get(route('procurement.index', ['category' => 'Technology Services']))
        ->assertOk();

    expect(rfqTitles($response))->toContain('Tech Request')->not->toContain('Legal Request');

    $facets = collect(inertiaProp($response, 'facets'))->pluck('count', 'label');
    expect($facets['Technology Services'])->toBe(1);
});

test('Closing Soon filters by deadline and counts the whole open set', function (): void {
    $buyer = rfqBuyer();
    $sbo = $buyer->sbos()->firstOrFail();

    rfq($sbo, ['title' => 'Closing Next Week', 'order_deadline' => now()->addDays(5)->toDateString()]);
    rfq($sbo, ['title' => 'Closing Next Year', 'order_deadline' => now()->addMonths(8)->toDateString()]);

    $response = $this->actingAs($buyer)
        ->get(route('procurement.index', ['status' => 'closing']))
        ->assertOk();

    expect(rfqTitles($response))->toContain('Closing Next Week')->not->toContain('Closing Next Year');

    /*
     * The count came from the current page before, so "Closing Soon 3" meant
     * three on this page rather than three in the registry.
     */
    $statuses = collect(inertiaProp($response, 'statusFacets'))->pluck('count', 'value');
    expect($statuses['all'])->toBe(2)->and($statuses['closing'])->toBe(1);
});

test('sorting is real, including Highest Value', function (): void {
    $buyer = rfqBuyer();
    $sbo = $buyer->sbos()->firstOrFail();

    rfq($sbo, ['title' => 'Cheap', 'budget' => '100', 'order_deadline' => now()->addDays(20)->toDateString()]);
    rfq($sbo, ['title' => 'Pricey', 'budget' => '90000', 'order_deadline' => now()->addDays(60)->toDateString()]);
    rfq($sbo, ['title' => 'Urgent', 'budget' => '500', 'order_deadline' => now()->addDays(2)->toDateString()]);

    $byValue = rfqTitles($this->actingAs($buyer)->get(route('procurement.index', ['sort' => 'value'])));
    expect($byValue->first())->toBe('Pricey');

    $byClosing = rfqTitles($this->actingAs($buyer)->get(route('procurement.index', ['sort' => 'closing'])));
    expect($byClosing->first())->toBe('Urgent');
});

test('an unknown sort falls back rather than erroring', function (): void {
    $buyer = rfqBuyer();
    rfq($buyer->sbos()->firstOrFail());

    $response = $this->actingAs($buyer)
        ->get(route('procurement.index', ['sort' => 'whatever']))
        ->assertOk();

    expect(inertiaProp($response, 'sort'))->toBe('recent');
});

test('filters compose and survive into the payload', function (): void {
    $buyer = rfqBuyer();
    $sbo = $buyer->sbos()->firstOrFail();

    rfq($sbo, [
        'title' => 'Solar Tech Urgent',
        'category_name' => 'Technology Services',
        'order_deadline' => now()->addDays(3)->toDateString(),
    ]);
    rfq($sbo, ['title' => 'Solar Tech Later', 'category_name' => 'Technology Services']);

    $response = $this->actingAs($buyer)
        ->get(route('procurement.index', [
            'search' => 'Solar',
            'category' => 'Technology Services',
            'status' => 'closing',
        ]))
        ->assertOk();

    expect(rfqTitles($response))->toContain('Solar Tech Urgent')->not->toContain('Solar Tech Later');

    // "Clear All" needs to know what is set.
    $filters = (array) inertiaProp($response, 'filters');
    expect($filters['search'])->toBe('Solar')->and($filters['status'])->toBe('closing');
});

/* ------------------------------------------------------------ schedule --- */

test('the rfp schedule is captured and shown in order', function (): void {
    $buyer = rfqBuyer();

    $this->actingAs($buyer)
        ->post(route('procurement.store'), [
            'sbo_id' => $buyer->sbos()->firstOrFail()->id,
            'title' => 'Scheduled Request',
            'description' => 'Full RFP.',
            'category' => 'Technology Services',
            'request_type' => 'service',
            'visibility' => 'public',
            'advertised_at' => now()->addDay()->toDateString(),
            'briefing_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'briefing_link' => 'https://teams.microsoft.com/meet/246355743945917',
            'briefing_location' => 'Registry House, Lekki',
            'briefing_mandatory' => true,
            'inquiry_opens_at' => now()->addDays(6)->toDateString(),
            'inquiry_closes_at' => now()->addDays(12)->toDateString(),
            'inquiry_responses_at' => now()->addDays(15)->toDateString(),
            'order_deadline' => now()->addDays(20)->toDateString(),
            'final_selection_at' => now()->addDays(30)->toDateString(),
        ])
        ->assertRedirect();

    $order = PurchaseOrder::where('title', 'Scheduled Request')->firstOrFail();

    expect($order->briefing_mandatory)->toBeTrue()
        ->and($order->briefing_link)->toBe('https://teams.microsoft.com/meet/246355743945917')
        ->and($order->inquiry_closes_at)->not->toBeNull();

    $response = $this->actingAs($buyer)->get(route('procurement.show', $order))->assertOk();

    $timeline = collect(inertiaProp($response, 'timeline'));
    $labels = $timeline->pluck('label');

    // Was six fixed rows, identical on every request.
    expect($labels)->toContain('RFP advertised:')
        ->toContain('Pre-proposal briefing (Mandatory):')
        ->toContain('Inquiry Period:')
        ->toContain('Responses to written inquiries sent:')
        ->toContain('Proposals due:')
        ->toContain('Final selection anticipated:');

    // The inquiry period reads as a range, because a duration is not a deadline.
    expect($timeline->firstWhere('label', 'Inquiry Period:')['value'])->toContain('–')
        ->and($timeline->firstWhere('label', 'Pre-proposal briefing (Mandatory):')['link'])
        ->toBe('https://teams.microsoft.com/meet/246355743945917');
});

test('a request with no schedule shows no invented dates', function (): void {
    $buyer = rfqBuyer();
    $order = rfq($buyer->sbos()->firstOrFail(), ['order_deadline' => null]);

    $response = $this->actingAs($buyer)->get(route('procurement.show', $order))->assertOk();

    expect(inertiaProp($response, 'timeline'))->toBe([]);
});

test('a schedule that runs backwards is refused', function (): void {
    $buyer = rfqBuyer();

    $base = [
        'sbo_id' => $buyer->sbos()->firstOrFail()->id,
        'title' => 'Backwards Request',
        'description' => 'Nope.',
        'category' => 'Technology Services',
        'request_type' => 'service',
        'visibility' => 'public',
    ];

    // Questions closing before they open.
    $this->actingAs($buyer)->post(route('procurement.store'), [
        ...$base,
        'inquiry_opens_at' => now()->addDays(10)->toDateString(),
        'inquiry_closes_at' => now()->addDays(5)->toDateString(),
    ])->assertSessionHasErrors('inquiry_closes_at');

    // Proposals due before the answers are sent.
    $this->actingAs($buyer)->post(route('procurement.store'), [
        ...$base,
        'inquiry_closes_at' => now()->addDays(5)->toDateString(),
        'inquiry_responses_at' => now()->addDays(20)->toDateString(),
        'order_deadline' => now()->addDays(10)->toDateString(),
    ])->assertSessionHasErrors('order_deadline');

    expect(PurchaseOrder::where('title', 'Backwards Request')->exists())->toBeFalse();
});

/* ----------------------------------------------------------- documents --- */

test('documents are listed from what was actually attached', function (): void {
    Storage::fake('public');

    $buyer = rfqBuyer();
    $order = rfq($buyer->sbos()->firstOrFail());

    $this->actingAs($buyer)
        ->post(route('procurement.documents.store', $order), [
            'documents' => [
                UploadedFile::fake()->create('scope-of-work.pdf', 120, 'application/pdf'),
                UploadedFile::fake()->create('form-of-contract.pdf', 80, 'application/pdf'),
            ],
        ])
        ->assertRedirect();

    $response = $this->actingAs($buyer)->get(route('procurement.show', $order, ['tab' => 'documents']))->assertOk();

    $sections = collect(inertiaProp($response, 'documents'));
    $items = collect($sections->firstWhere('key', 'project')['items']);

    expect($items->pluck('name'))->toContain('scope-of-work.pdf')->toContain('form-of-contract.pdf')
        // Was the same invented security-plan PDF on every request.
        ->and($items->pluck('name'))->not->toContain(
            'Emergency_Management_System_Security_Plan_&_Threat_Risk_Assessment_(Addendum_#1_Revision).pdf'
        );

    expect($items->first()['size'])->not->toBeNull();
});

test('downloading one selected document serves that file', function (): void {
    Storage::fake('public');

    $buyer = rfqBuyer();
    $order = rfq($buyer->sbos()->firstOrFail());

    $this->actingAs($buyer)->post(route('procurement.documents.store', $order), [
        'documents' => [UploadedFile::fake()->create('single.pdf', 40, 'application/pdf')],
    ]);

    $document = PurchaseOrderDocument::where('purchase_order_id', $order->id)->firstOrFail();

    $response = $this->actingAs($buyer)
        ->get(route('procurement.documents.download', $order) . '?ids[]=' . $document->id)
        ->assertOk();

    // One tick means one file, not a one-entry archive.
    expect($response->headers->get('content-disposition'))->toContain('single.pdf');
});

test('downloading several selected documents serves a zip', function (): void {
    Storage::fake('public');

    $buyer = rfqBuyer();
    $order = rfq($buyer->sbos()->firstOrFail(), ['title' => 'Bundle Request']);

    $this->actingAs($buyer)->post(route('procurement.documents.store', $order), [
        'documents' => [
            UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->create('b.pdf', 20, 'application/pdf'),
        ],
    ]);

    $ids = PurchaseOrderDocument::where('purchase_order_id', $order->id)->pluck('id');

    $response = $this->actingAs($buyer)
        ->get(route('procurement.documents.download', $order) . '?' . http_build_query(['ids' => $ids->all()]))
        ->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('bundle-request-documents.zip');
});

test('a document from another request cannot be selected into this download', function (): void {
    Storage::fake('public');

    $buyer = rfqBuyer();
    $mine = rfq($buyer->sbos()->firstOrFail(), ['title' => 'Mine']);
    $theirs = rfq(rfqBuyer()->sbos()->firstOrFail(), ['title' => 'Theirs']);

    $this->actingAs($buyer)->post(route('procurement.documents.store', $mine), [
        'documents' => [UploadedFile::fake()->create('mine.pdf', 10, 'application/pdf')],
    ]);

    $foreign = PurchaseOrderDocument::create([
        'purchase_order_id' => $theirs->id,
        'section' => 'project',
        'name' => 'theirs.pdf',
        'path' => 'procurement-documents/theirs.pdf',
    ]);
    Storage::disk('public')->put('procurement-documents/theirs.pdf', 'secret');

    // Scoped to this request, so a foreign id selects nothing.
    $this->actingAs($buyer)
        ->get(route('procurement.documents.download', $mine) . '?ids[]=' . $foreign->id)
        ->assertRedirect();
});

test('only the buyer can attach or remove documents', function (): void {
    Storage::fake('public');

    $buyer = rfqBuyer();
    $order = rfq($buyer->sbos()->firstOrFail());

    $this->actingAs(rfqBuyer())
        ->post(route('procurement.documents.store', $order), [
            'documents' => [UploadedFile::fake()->create('intruder.pdf', 10, 'application/pdf')],
        ])
        ->assertForbidden();

    $this->actingAs($buyer)->post(route('procurement.documents.store', $order), [
        'documents' => [UploadedFile::fake()->create('ok.pdf', 10, 'application/pdf')],
    ]);
    $document = PurchaseOrderDocument::where('purchase_order_id', $order->id)->firstOrFail();

    $this->actingAs(rfqBuyer())
        ->delete(route('procurement.documents.destroy', [$order, $document]))
        ->assertForbidden();

    $this->actingAs($buyer)
        ->delete(route('procurement.documents.destroy', [$order, $document]))
        ->assertRedirect();

    expect(PurchaseOrderDocument::find($document->id))->toBeNull();
});

test('creating a request can attach several documents at once', function (): void {
    Storage::fake('public');

    $buyer = rfqBuyer();

    $this->actingAs($buyer)
        ->post(route('procurement.store'), [
            'sbo_id' => $buyer->sbos()->firstOrFail()->id,
            'title' => 'Documented Request',
            'description' => 'With files.',
            'category' => 'Technology Services',
            'request_type' => 'service',
            'visibility' => 'public',
            'document' => UploadedFile::fake()->create('primary.pdf', 30, 'application/pdf'),
            'documents' => [
                UploadedFile::fake()->create('extra-a.pdf', 20, 'application/pdf'),
                UploadedFile::fake()->create('extra-b.pdf', 20, 'application/pdf'),
            ],
        ])
        ->assertRedirect();

    $order = PurchaseOrder::where('title', 'Documented Request')->firstOrFail();

    // The first file keeps using document_path, where a single upload has
    // always gone; the rest become document rows.
    expect($order->document_path)->not->toBeNull()
        ->and(PurchaseOrderDocument::where('purchase_order_id', $order->id)->count())->toBe(2);

    $response = $this->actingAs($buyer)->get(route('procurement.show', $order))->assertOk();
    $items = collect(collect(inertiaProp($response, 'documents'))->firstWhere('key', 'project')['items']);

    // Three files listed together, all selectable — the legacy single upload
    // is offered as the sentinel id 0 rather than a non-selectable link.
    expect($items)->toHaveCount(3)
        ->and($items->whereNull('id'))->toHaveCount(0)
        ->and($items->firstWhere('id', 0))->not->toBeNull();
});

test('the legacy single upload can be downloaded by selecting it', function (): void {
    Storage::fake('public');

    $buyer = rfqBuyer();

    $this->actingAs($buyer)
        ->post(route('procurement.store'), [
            'sbo_id' => $buyer->sbos()->firstOrFail()->id,
            'title' => 'Legacy Doc Request',
            'description' => 'One primary file.',
            'category' => 'Technology Services',
            'request_type' => 'service',
            'visibility' => 'public',
            'document' => UploadedFile::fake()->create('primary.pdf', 30, 'application/pdf'),
        ])
        ->assertRedirect();

    $order = PurchaseOrder::where('title', 'Legacy Doc Request')->firstOrFail();

    // Selecting id 0 downloads the request's document_path file.
    $this->actingAs($buyer)
        ->get(route('procurement.documents.download', ['procurement' => $order->id, 'ids' => [0]]))
        ->assertOk()
        ->assertDownload();
});

/* ------------------------------------------- verified bidders only --- */

test('an unverified business cannot bid', function (): void {
    $buyer = rfqBuyer();
    $order = rfq($buyer->sbos()->firstOrFail());

    // Paid, so past the paywall — but not verified.
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $unverified = Sbo::create([
        'name' => 'Unverified Bidder',
        'slug' => 'unverified-bidder-' . Str::random(5),
        'created_by' => $owner->id,
        'category' => 'Legal Consultants',
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->post(route('procurement.bid.store', $order), [
            'bidder_sbo_id' => $unverified->id,
            'proposal' => 'We would like to do this work.',
        ])
        ->assertForbidden();

    expect(PurchaseOrder::find($order->id)->bids()->count())->toBe(0);

    // And the same bar applies to the Q&A thread, which is published to every
    // bidder.
    $this->actingAs($owner)
        ->post(route('procurement.questions.store', $order), ['body' => 'When does this close?'])
        ->assertForbidden();
});

test('a verified business can bid; an unverified one is walled from the wizard', function (): void {
    $buyer = rfqBuyer();
    $order = rfq($buyer->sbos()->firstOrFail());

    $bidder = rfqBuyer();

    $this->actingAs($bidder)
        ->post(route('procurement.bid.store', $order), [
            'bidder_sbo_id' => $bidder->sbos()->firstOrFail()->id,
            'proposal' => 'We would like to do this work.',
        ])
        ->assertRedirect();

    expect(PurchaseOrder::find($order->id)->bids()->count())->toBe(1);

    // A paid-but-unverified owner is now walled from the bid wizard entirely —
    // platform access waits for admin verification, not just payment.
    $unverifiedOwner = User::factory()->create(['email_verified_at' => now()]);
    Sbo::create([
        'name' => 'Wizard Unverified',
        'slug' => 'wizard-unverified-' . Str::random(5),
        'created_by' => $unverifiedOwner->id,
        'category' => 'Legal Consultants',
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($unverifiedOwner)
        ->get(route('procurement.bid', $order))
        ->assertRedirect(route('sbo.payment-required'));
});

test('an owner can edit their submitted procurement request', function (): void {
    Storage::fake('public');
    Category::query()->firstOrCreate(['slug' => 'technology-services'], ['name' => 'Technology Services', 'business_count' => 0]);

    $buyer = rfqBuyer();

    $this->actingAs($buyer)->post(route('procurement.store'), [
        'sbo_id' => $buyer->sbos()->firstOrFail()->id,
        'title' => 'Original Title',
        'description' => 'Original description of the need.',
        'category' => 'Technology Services',
        'request_type' => 'service',
        'visibility' => 'public',
    ])->assertRedirect();

    $order = PurchaseOrder::where('title', 'Original Title')->firstOrFail();

    // The edit screen prefills from the stored values.
    $edit = $this->actingAs($buyer)->get(route('procurement.edit', $order))->assertOk();
    expect(inertiaProp($edit, 'editing.values.title'))->toBe('Original Title')
        ->and(inertiaProp($edit, 'editing.values.visibility'))->toBe('public');

    // And the update takes.
    $this->actingAs($buyer)->put(route('procurement.update', $order), [
        'sbo_id' => $order->sbo_id,
        'title' => 'Updated Title',
        'description' => 'Updated description of the need.',
        'category' => 'Technology Services',
        'request_type' => 'product',
        'visibility' => 'invite_only',
    ])->assertRedirect(route('procurement.show', $order));

    $order->refresh();
    expect($order->title)->toBe('Updated Title')
        ->and($order->request_type)->toBe('product')
        ->and($order->visibility)->toBe('invite_only');
});

test('a non-owner cannot edit or update another buyers request', function (): void {
    Category::query()->firstOrCreate(['slug' => 'technology-services'], ['name' => 'Technology Services', 'business_count' => 0]);

    $buyer = rfqBuyer();
    $stranger = rfqBuyer();

    $this->actingAs($buyer)->post(route('procurement.store'), [
        'sbo_id' => $buyer->sbos()->firstOrFail()->id,
        'title' => 'Not Yours',
        'description' => 'Private to the owner.',
        'category' => 'Technology Services',
        'request_type' => 'service',
        'visibility' => 'public',
    ])->assertRedirect();

    $order = PurchaseOrder::where('title', 'Not Yours')->firstOrFail();

    $this->actingAs($stranger)->get(route('procurement.edit', $order))->assertForbidden();

    $this->actingAs($stranger)->put(route('procurement.update', $order), [
        'sbo_id' => $stranger->sbos()->firstOrFail()->id,
        'title' => 'Hijacked',
        'description' => 'Should not save.',
        'category' => 'Technology Services',
        'request_type' => 'service',
        'visibility' => 'public',
    ])->assertForbidden();

    expect($order->fresh()->title)->toBe('Not Yours');
});

/* ------------------------------------------------- invite-only sharing --- */

function inviteOnlyRfq(App\Models\User $owner, string $title = 'Private RFQ'): PurchaseOrder
{
    $category = Category::query()->firstOrCreate(
        ['slug' => 'technology-services'],
        ['name' => 'Technology Services', 'business_count' => 0],
    );

    return PurchaseOrder::create([
        'sbo_id' => $owner->sbos()->firstOrFail()->id,
        'category_id' => $category->id,
        'title' => $title,
        'description' => 'Confidential scope.',
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_INVITE_ONLY,
        'request_type' => 'service',
    ]);
}

test('a private request is hidden from a non-invited member, shown to invited ones and the owner', function (): void {
    $owner = rfqBuyer();
    $stranger = rfqBuyer();
    $invitedByEmail = rfqBuyer();
    $invitedBySbo = rfqBuyer();

    $rfq = inviteOnlyRfq($owner);
    $ownerSboId = $owner->sbos()->firstOrFail()->id;

    $rfq->invites()->create(['invited_email' => $invitedByEmail->email, 'invited_by_sbo_id' => $ownerSboId, 'status' => 'pending']);
    $rfq->invites()->create(['invited_sbo_id' => $invitedBySbo->sbos()->firstOrFail()->id, 'invited_by_sbo_id' => $ownerSboId, 'status' => 'pending']);

    // Stranger: not in the list, and the detail page reveals nothing.
    expect(rfqTitles($this->actingAs($stranger)->get(route('procurement.index'))->assertOk()))->not->toContain('Private RFQ');
    $this->actingAs($stranger)->get(route('procurement.show', $rfq))->assertOk()
        ->assertInertia(fn ($page) => $page->component('procurement/ProcurementLockedView'));

    // Owner and both invited members see the real request.
    foreach ([$owner, $invitedByEmail, $invitedBySbo] as $viewer) {
        $this->actingAs($viewer)->get(route('procurement.show', $rfq))->assertOk()
            ->assertInertia(fn ($page) => $page->component('procurement/ProcurementDetailView'));
    }

    expect(rfqTitles($this->actingAs($invitedBySbo)->get(route('procurement.index'))->assertOk()))->toContain('Private RFQ');
});

test('an admin sees a private request and its bids without asking for access', function (): void {
    $owner = rfqBuyer();
    $bidder = rfqBuyer();
    $rfq = inviteOnlyRfq($owner);

    $this->actingAs($bidder)
        ->post(route('procurement.bid.store', $rfq), [
            'bidder_sbo_id' => $bidder->sbos()->firstOrFail()->id,
            'proposal' => 'Our proposal for the confidential scope.',
        ])->assertRedirect();

    $admin = User::factory()->create([
        'role' => App\Enums\UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);

    // Straight to the request — no locked view, no access request — with the
    // bids in hand for review, but not as the owner (so no award controls).
    $this->actingAs($admin)->get(route('procurement.show', $rfq))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('procurement/ProcurementDetailView')
            ->where('isOwner', false)
            ->where('isAdmin', true)
            ->has('bids', 1));

    expect(App\Models\PurchaseOrderAccessRequest::query()
        ->where('purchase_order_id', $rfq->id)->where('user_id', $admin->id)->exists())->toBeFalse();
});

test('requesting access notifies the owner, and approving lets the member in', function (): void {
    Illuminate\Support\Facades\Notification::fake();

    $owner = rfqBuyer();
    $stranger = rfqBuyer();
    $rfq = inviteOnlyRfq($owner);

    // The stranger asks to be let in.
    $this->actingAs($stranger)->post(route('procurement.access.request', $rfq))->assertRedirect();

    expect(App\Models\PurchaseOrderAccessRequest::query()
        ->where('purchase_order_id', $rfq->id)->where('user_id', $stranger->id)->where('status', 'pending')->exists())->toBeTrue();
    Illuminate\Support\Facades\Notification::assertSentTo($owner, App\Notifications\ProcurementAccessRequestedNotification::class);

    // Still locked until the owner approves.
    $this->actingAs($stranger)->get(route('procurement.show', $rfq))
        ->assertInertia(fn ($page) => $page->component('procurement/ProcurementLockedView'));

    $accessRequest = App\Models\PurchaseOrderAccessRequest::firstOrFail();

    // A stranger cannot approve; only the owner.
    $this->actingAs($stranger)->post(route('procurement.access.approve', [$rfq, $accessRequest]))->assertForbidden();

    $this->actingAs($owner)->post(route('procurement.access.approve', [$rfq, $accessRequest]))->assertRedirect();

    Illuminate\Support\Facades\Notification::assertSentTo($stranger, App\Notifications\ProcurementAccessGrantedNotification::class);

    // Now viewable.
    $this->actingAs($stranger)->get(route('procurement.show', $rfq))
        ->assertInertia(fn ($page) => $page->component('procurement/ProcurementDetailView'));
});

test('creating an invite-only request stores the email and business invites', function (): void {
    Illuminate\Support\Facades\Notification::fake();
    Storage::fake('public');

    $owner = rfqBuyer();
    $invitee = rfqBuyer();
    $emailInvitee = rfqBuyer();
    Category::query()->firstOrCreate(['slug' => 'technology-services'], ['name' => 'Technology Services', 'business_count' => 0]);

    $this->actingAs($owner)->post(route('procurement.store'), [
        'sbo_id' => $owner->sbos()->firstOrFail()->id,
        'title' => 'Invited Build',
        'description' => 'Scope of the work.',
        'category' => 'Technology Services',
        'request_type' => 'service',
        'visibility' => 'invite_only',
        // A verified member's address — only they can be invited (they can bid).
        'invites_emails' => [$emailInvitee->email],
        'invites_sbo_ids' => [$invitee->sbos()->firstOrFail()->id],
    ])->assertRedirect();

    $rfq = PurchaseOrder::where('title', 'Invited Build')->firstOrFail();

    expect($rfq->invites()->whereNotNull('invited_email')->count())->toBe(1)
        ->and($rfq->invites()->whereNotNull('invited_sbo_id')->count())->toBe(1);
});

test('an invite-only request rejects an email that is not a verified member', function (): void {
    $owner = rfqBuyer();
    // Email-verified, but owns no verified business — cannot bid, so cannot be invited.
    $notVerified = App\Models\User::factory()->create(['email_verified_at' => now()]);
    Category::query()->firstOrCreate(['slug' => 'technology-services'], ['name' => 'Technology Services', 'business_count' => 0]);

    $this->actingAs($owner)->from(route('procurement.create'))->post(route('procurement.store'), [
        'sbo_id' => $owner->sbos()->firstOrFail()->id,
        'title' => 'Bad Invite',
        'description' => 'Scope of the work.',
        'category' => 'Technology Services',
        'request_type' => 'service',
        'visibility' => 'invite_only',
        'invites_emails' => [$notVerified->email],
    ])->assertSessionHasErrors('invites_emails.0');

    expect(PurchaseOrder::where('title', 'Bad Invite')->exists())->toBeFalse();
});

/* -------------------------------------------------------------- drafts --- */

test('a request can be saved as a draft with only a title, then published', function (): void {
    Storage::fake('public');
    Category::query()->firstOrCreate(['slug' => 'technology-services'], ['name' => 'Technology Services', 'business_count' => 0]);

    $owner = rfqBuyer();

    // Only a title needed to save a draft.
    $this->actingAs($owner)->post(route('procurement.draft'), [
        'sbo_id' => $owner->sbos()->firstOrFail()->id,
        'title' => 'Work in progress',
    ])->assertRedirect();

    $draft = PurchaseOrder::where('title', 'Work in progress')->firstOrFail();
    expect($draft->status)->toBe('draft');

    // Hidden from the public listing, and only the owner may open it.
    expect(rfqTitles($this->actingAs(rfqBuyer())->get(route('procurement.index'))->assertOk()))->not->toContain('Work in progress');

    // The edit screen marks it a draft.
    $this->actingAs($owner)->get(route('procurement.edit', $draft))->assertOk()
        ->assertInertia(fn ($page) => $page->where('editing.isDraft', true));

    // Publishing through the full form opens it.
    $this->actingAs($owner)->put(route('procurement.update', $draft), [
        'sbo_id' => $draft->sbo_id,
        'title' => 'Work in progress',
        'description' => 'Now complete.',
        'category' => 'Technology Services',
        'request_type' => 'service',
        'visibility' => 'public',
    ])->assertRedirect(route('procurement.show', $draft));

    expect($draft->fresh()->status)->toBe('open');
});

test('a draft cannot be seen by anyone but its owner', function (): void {
    Category::query()->firstOrCreate(['slug' => 'technology-services'], ['name' => 'Technology Services', 'business_count' => 0]);

    $owner = rfqBuyer();
    $this->actingAs($owner)->post(route('procurement.draft'), [
        'sbo_id' => $owner->sbos()->firstOrFail()->id,
        'title' => 'Secret draft',
    ])->assertRedirect();

    $draft = PurchaseOrder::where('title', 'Secret draft')->firstOrFail();

    $this->actingAs(rfqBuyer())->get(route('procurement.show', $draft))->assertNotFound();
});

test('the detail page offers to edit a bid once the member has placed one', function (): void {
    Category::query()->firstOrCreate(['slug' => 'technology-services'], ['name' => 'Technology Services', 'business_count' => 0]);

    $owner = rfqBuyer();
    $bidder = rfqBuyer();
    $order = rfq($owner->sbos()->firstOrFail());

    // Before bidding: the button places a bid.
    $this->actingAs($bidder)->get(route('procurement.show', $order))->assertOk()
        ->assertInertia(fn ($page) => $page->where('hasBid', false));

    $this->actingAs($bidder)->post(route('procurement.bid.store', ['procurement' => $order->id]), [
        'bidder_sbo_id' => $bidder->sbos()->firstOrFail()->id,
        'proposal' => 'Our proposal for the work.',
    ])->assertRedirect();

    // After: the button edits it instead.
    $this->actingAs($bidder)->get(route('procurement.show', $order))->assertOk()
        ->assertInertia(fn ($page) => $page->where('hasBid', true));
});
