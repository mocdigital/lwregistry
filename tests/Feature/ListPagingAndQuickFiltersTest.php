<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Two controls that were drawn and never wired.
 *
 * The Quick Filters on /categories rendered eight buttons with real counts and
 * no @click: the sidebar looked like a filter and behaved like a caption. The
 * pagination on /procurement was literally `v-for="p in [1, 2, 3]"` with page
 * one hardcoded as current — it said "1 2 3" whether there were two pages or
 * eleven, and clicking any of them did nothing.
 *
 * Both filter on the STORED value, not the label the reader sees: the label is
 * translated, so a French visitor filtering on "Services technologiques" would
 * match no rows at all.
 */

/*
 * Local helpers rather than the ones in ProcurementFiltersAndDocumentsTest:
 * Pest only loads the files it is running, so borrowing a global from a
 * sibling file works for the whole suite and fatals when this file runs alone.
 */
function pagingSbo(string $name, string $category): Sbo
{
    // The row has to exist for the facet to be offered; SboObserver keeps
    // business_count in step as listings are written.
    Category::query()->firstOrCreate(
        ['slug' => Str::slug($category)],
        ['name' => $category, 'business_count' => 0],
    );

    return Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => User::factory()->create(['email_verified_at' => now()])->id,
        'category' => $category,
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
    ]);
}

/*
 * The registry is members-only and paid, so /categories 302s a guest. The
 * viewer's own listing carries no category, so it never lands in a facet.
 */
function pagingViewer(): User
{
    return paidMember();
}

/* -------------------------------------------------- categories filters --- */

test('a quick filter carries the stored name, not only the label', function (): void {
    pagingSbo('Alpha Tech', 'Technology Services');

    $this->actingAs(pagingViewer())->get('/categories')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quickFilters.0.value', 'Technology Services')
            ->where('quickFilters.0.label', 'Technology Services')
            ->where('quickFilters.0.count', 1));
});

test('the label is translated and the value it filters on is not', function (): void {
    pagingSbo('Alpha Tech', 'Technology Services');

    $this->actingAs(pagingViewer())
        ->withSession(['locale' => 'fr'])
        ->get('/categories')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Whatever French calls it, the query still has to say this.
            ->where('quickFilters.0.value', 'Technology Services'));
});

test('clicking a quick filter narrows the list to that category', function (): void {
    pagingSbo('Alpha Tech', 'Technology Services');
    pagingSbo('Bravo Haulage', 'Transport & Logistics');

    $names = inertiaPluck(
        $this->actingAs(pagingViewer())->get('/categories?category=' . urlencode('Technology Services'))->assertOk(),
        'listings.data',
        'name',
    );

    expect($names->all())->toBe(['Alpha Tech']);
});

test('the applied category comes back so the button can show as pressed', function (): void {
    pagingSbo('Alpha Tech', 'Technology Services');

    $this->actingAs(pagingViewer())->get('/categories?category=' . urlencode('Technology Services'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.category', 'Technology Services'));
});

/* ------------------------------------------------ procurement paging ----- */

function pagingBuyer(): User
{
    return paidMember([], ['name' => 'Buyer SBO ' . Str::random(4), 'category' => 'Legal Consultants']);
}

function pagingRfq(Sbo $sbo, string $title, string $category = 'Technology Services'): PurchaseOrder
{
    $row = Category::query()->firstOrCreate(
        ['slug' => Str::slug($category)],
        ['name' => $category, 'business_count' => 0],
    );

    return PurchaseOrder::create([
        'sbo_id' => $sbo->id,
        'category_id' => $row->id,
        'title' => $title,
        'description' => 'Work to be done.',
        'budget' => '5000',
        'order_deadline' => now()->addMonths(6)->toDateString(),
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
        'request_type' => 'service',
    ]);
}

function pagingTitles($response): Illuminate\Support\Collection
{
    return inertiaPluck($response, 'requests.data', 'title');
}

test('the procurement paginator sends links that reach a second page', function (): void {
    $buyer = pagingBuyer();

    // Eleven against a page size of ten, so there is a second page holding one.
    foreach (range(1, 11) as $i) {
        pagingRfq($buyer->sbos()->first(), sprintf('Request %02d', $i));
    }

    $first = $this->actingAs($buyer)->get('/procurement')->assertOk();

    $first->assertInertia(fn ($page) => $page
        ->where('requests.meta.last_page', 2)
        ->where('requests.meta.total', 11));

    expect(pagingTitles($first))->toHaveCount(10);

    $second = $this->actingAs($buyer)->get('/procurement?page=2')->assertOk();

    expect(pagingTitles($second))->toHaveCount(1)
        // A real second page, not the first one served again.
        ->and(pagingTitles($second)->first())->not->toBeIn(pagingTitles($first)->all());
});

test('a page link keeps the filters that produced the list', function (): void {
    $buyer = pagingBuyer();

    foreach (range(1, 11) as $i) {
        pagingRfq($buyer->sbos()->first(), sprintf('Catering %02d', $i), 'Catering Services');
    }

    pagingRfq($buyer->sbos()->first(), 'Unrelated', 'Technology Services');

    $response = $this->actingAs($buyer)
        ->get('/procurement?category=' . urlencode('Catering Services'))
        ->assertOk();

    $links = collect($response->viewData('page')['props']['requests']['links'])
        ->pluck('url')
        ->filter();

    // withQueryString(): drop the filter from the link and page two shows the
    // unfiltered set, which is the bug this guards.
    expect($links)->not->toBeEmpty()
        ->and($links->every(fn (string $url): bool => str_contains($url, 'category=')))->toBeTrue();
});

test('the page one paginator has no link to page zero', function (): void {
    $buyer = pagingBuyer();

    foreach (range(1, 11) as $i) {
        pagingRfq($buyer->sbos()->first(), sprintf('Request %02d', $i));
    }

    $links = collect(
        $this->actingAs($buyer)->get('/procurement')->viewData('page')['props']['requests']['links'],
    );

    // "Previous" on page one is drawn and dead — the screen renders a span for
    // a null url rather than a link that goes nowhere.
    expect($links->first()['url'])->toBeNull()
        ->and($links->last()['url'])->not->toBeNull();
});
