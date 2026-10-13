<?php

declare(strict_types=1);

use App\Models\ComplianceDocumentType;
use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\SboView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The profile's two Quick Actions cards.
 *
 * "View Analytics" and "Manage Documents" both linked to /dashboard — the
 * profile they sit on — so neither did anything, and there was nothing behind
 * either name.
 */

function analyticsSbo(User $owner, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Analytics SBO ' . Str::random(5);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'category' => 'Software Development',
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

/* ---------------------------------------------------------- analytics --- */

test('opening a profile records a view for its owner', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $sbo = analyticsSbo($owner, ['name' => 'Viewed Listing']);

    $this->actingAs(paidMember())->get(route('businesses.show', $sbo->slug))->assertOk();

    expect(SboView::where('sbo_id', $sbo->id)->count())->toBe(1);
});

test('a refresh is not a second view, and the owner viewing their own is not a view', function (): void {
    $owner = paidMember([], ['name' => 'Self Viewed Listing']);
    $sbo = $owner->sbos()->firstOrFail();

    $visitor = paidMember();

    // Twice in the same day, same visitor.
    $this->actingAs($visitor)->get(route('businesses.show', $sbo->slug))->assertOk();
    $this->actingAs($visitor)->get(route('businesses.show', $sbo->slug))->assertOk();

    expect(SboView::where('sbo_id', $sbo->id)->count())->toBe(1);

    // A listing you keep checking on is not a listing anyone found.
    $this->actingAs($owner)->get(route('businesses.show', $sbo->slug))->assertOk();

    expect(SboView::where('sbo_id', $sbo->id)->count())->toBe(1);
});

test('the analytics page counts views, requests and connections', function (): void {
    $owner = paidMember([], ['name' => 'Measured Listing']);
    $sbo = $owner->sbos()->firstOrFail();

    SboView::create(['sbo_id' => $sbo->id, 'viewer_hash' => 'a', 'viewed_at' => now()->subDays(2)]);
    SboView::create(['sbo_id' => $sbo->id, 'viewer_hash' => 'b', 'viewed_at' => now()->subDay()]);
    // Outside a 30-day window.
    SboView::create(['sbo_id' => $sbo->id, 'viewer_hash' => 'c', 'viewed_at' => now()->subDays(60)]);

    $suitor = paidMember();
    SboConnection::create([
        'requester_sbo_id' => $suitor->sbos()->firstOrFail()->id,
        'recipient_sbo_id' => $sbo->id,
        'status' => SboConnection::STATUS_PENDING,
    ]);

    $response = $this->actingAs($owner)->get(route('account.analytics'))->assertOk();

    $response->assertInertia(fn ($page) => $page->component('account/AnalyticsView'));

    $totals = collect(inertiaProp($response, 'totals'))->pluck('value', 'key');

    expect($totals['views'])->toBe(2)
        ->and($totals['requests'])->toBe(1)
        // A lead is someone asking to connect with you, not one you sent.
        ->and($totals['accepted'])->toBe(0);
});

test('the series covers every day in the window, including empty ones', function (): void {
    $owner = paidMember();
    $sbo = $owner->sbos()->firstOrFail();

    SboView::create(['sbo_id' => $sbo->id, 'viewer_hash' => 'a', 'viewed_at' => now()]);

    $response = $this->actingAs($owner)
        ->get(route('account.analytics', ['days' => 7]))
        ->assertOk();

    $series = collect(inertiaProp($response, 'series'));

    // A chart that skips quiet days makes a quiet week look busy.
    expect($series)->toHaveCount(7)
        ->and($series->sum('views'))->toBe(1)
        ->and(inertiaProp($response, 'days'))->toBe(7);
});

test('an unknown range falls back rather than erroring', function (): void {
    $response = $this->actingAs(paidMember())
        ->get(route('account.analytics', ['days' => 4000]))
        ->assertOk();

    expect(inertiaProp($response, 'days'))->toBe(30);
});

test('analytics can be narrowed to one listing', function (): void {
    $owner = paidMember([], ['name' => 'First Listing']);
    $first = $owner->sbos()->firstOrFail();
    $second = analyticsSbo($owner, ['name' => 'Second Listing']);

    SboView::create(['sbo_id' => $first->id, 'viewer_hash' => 'a', 'viewed_at' => now()]);
    SboView::create(['sbo_id' => $second->id, 'viewer_hash' => 'b', 'viewed_at' => now()]);
    SboView::create(['sbo_id' => $second->id, 'viewer_hash' => 'c', 'viewed_at' => now()]);

    $all = $this->actingAs($owner)->get(route('account.analytics'))->assertOk();
    expect(collect(inertiaProp($all, 'totals'))->pluck('value', 'key')['views'])->toBe(3);

    $one = $this->actingAs($owner)
        ->get(route('account.analytics', ['sbo' => $second->id]))
        ->assertOk();

    expect(collect(inertiaProp($one, 'totals'))->pluck('value', 'key')['views'])->toBe(2)
        ->and(inertiaProp($one, 'selectedSboId'))->toBe($second->id);

    // Per listing, so an owner with several can see which one is working.
    $listings = collect(inertiaProp($all, 'listings'))->pluck('views', 'name');
    expect($listings['First Listing'])->toBe(1)->and($listings['Second Listing'])->toBe(2);
});

test('what is not measured is named rather than shown as zero', function (): void {
    $response = $this->actingAs(paidMember())->get(route('account.analytics'))->assertOk();

    expect(inertiaProp($response, 'notTracked'))->toContain('Website clicks');
});

test('one owner cannot read another listing analytics', function (): void {
    $stranger = paidMember([], ['name' => 'Stranger Listing']);
    $strangerSbo = $stranger->sbos()->firstOrFail();

    SboView::create(['sbo_id' => $strangerSbo->id, 'viewer_hash' => 'x', 'viewed_at' => now()]);

    // Asking for someone else's SBO falls back to your own scope, not theirs.
    $response = $this->actingAs(paidMember())
        ->get(route('account.analytics', ['sbo' => $strangerSbo->id]))
        ->assertOk();

    expect(inertiaProp($response, 'selectedSboId'))->toBeNull();

    $listings = collect(inertiaProp($response, 'listings'))->pluck('name');
    expect($listings)->not->toContain('Stranger Listing');
});

/* ---------------------------------------------------------- documents --- */

/** A requirement every SBO has to meet, regardless of country. */
function documentType(array $overrides = []): ComplianceDocumentType
{
    return ComplianceDocumentType::create(array_merge([
        'name' => 'Certificate of Incorporation',
        'description' => 'Issued by the registrar.',
        'is_required' => true,
        'is_active' => true,
        'sort_order' => 1,
    ], $overrides));
}

test('the documents page lists every required document with its state', function (): void {
    $owner = paidMember([], ['name' => 'Document Listing']);
    $sbo = $owner->sbos()->firstOrFail();

    documentType();
    documentType(['name' => 'Tax Clearance', 'is_required' => false, 'sort_order' => 2]);

    $response = $this->actingAs($owner)->get(route('account.documents'))->assertOk();

    $response->assertInertia(fn ($page) => $page->component('account/DocumentsView'));

    expect(inertiaProp($response, 'selected.id'))->toBe($sbo->id)
        ->and(inertiaProp($response, 'uploadUrl'))->toBe(route('sbo.documents.store'));

    $documents = collect(inertiaProp($response, 'documents'));

    // The same requirements the application's document step uses.
    expect($documents)->not->toBeEmpty()
        ->and($documents->first())->toHaveKeys(['typeId', 'name', 'required', 'status']);
});

test('a document uploaded here is the document the application shows', function (): void {
    Illuminate\Support\Facades\Storage::fake('compliance');

    $owner = paidMember([], ['name' => 'Upload Listing']);
    $sbo = $owner->sbos()->firstOrFail();

    $type = documentType();

    $this->actingAs($owner)
        ->post(route('sbo.documents.store'), [
            'sbo_id' => $sbo->id,
            'compliance_document_type_id' => $type->id,
            // A real image rather than a zero-byte fake: the media collection
            // guards on mime, and `fake()->create()` produces application/x-empty.
            'document' => Illuminate\Http\UploadedFile::fake()->image('cac.png', 600, 400),
        ])
        ->assertRedirect();

    $response = $this->actingAs($owner)->get(route('account.documents'))->assertOk();

    $row = collect(inertiaProp($response, 'documents'))->firstWhere('typeId', $type->id);

    expect($row['status'])->toBe('pending')
        ->and($row['fileName'])->toBe('cac.png')
        ->and($row['downloadUrl'])->not->toBeNull();

    // Same document, same status, on the application progress screen.
    $progress = $this->actingAs($owner)
        ->get(route('sbo.applications.progress', $sbo))
        ->assertOk();

    $onProgress = collect(inertiaProp($progress, 'documents'))->firstWhere('typeId', $type->id);
    expect($onProgress['status'])->toBe('pending');
});

test('the documents page stays reachable for an owner held up on payment', function (): void {
    // A fee held up on paperwork is exactly when an owner needs their
    // documents, so this one page sits outside the payment gate.
    $owner = User::factory()->create(['email_verified_at' => now()]);
    analyticsSbo($owner, [
        'name' => 'Unpaid Listing',
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
    ]);

    $this->actingAs($owner)->get(route('account.documents'))->assertOk();

    // Analytics is behind the gate, like the rest of the workspace.
    $this->actingAs($owner)
        ->get(route('account.analytics'))
        ->assertRedirect(route('sbo.payment-required'));
});

test('one owner cannot open another listing documents', function (): void {
    documentType();

    $stranger = paidMember([], ['name' => 'Stranger Docs']);
    $strangerSbo = $stranger->sbos()->firstOrFail();

    $response = $this->actingAs(paidMember([], ['name' => 'My Docs']))
        ->get(route('account.documents', ['sbo' => $strangerSbo->id]))
        ->assertOk();

    // Falls back to their own listing rather than showing someone else's.
    expect(inertiaProp($response, 'selected.name'))->toBe('My Docs');
});

test('both pages are signed-in only', function (): void {
    $this->get(route('account.analytics'))->assertRedirect(route('login'));
    $this->get(route('account.documents'))->assertRedirect(route('login'));
});
