<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Posting a procurement request: knowing whether it worked.
 *
 * Twelve fields were validated and ten of them had nowhere on the form to show
 * a failure — visibility, request type, budget, the whole RFP schedule and the
 * document list. A request rejected on any of those looked like a button that
 * did nothing, which is exactly what was reported. And a request that DID post
 * redirected to a page with no confirmation on it, so a buyer who had just
 * filled in a long form could not tell either way.
 */

function postingBuyer(): App\Models\User
{
    $owner = paidMember([], ['name' => 'Buyer Ltd']);

    Category::query()->firstOrCreate(
        ['slug' => 'technology-services'],
        ['name' => 'Technology Services', 'business_count' => 0],
    );

    return $owner;
}

/** @return array<string, mixed> */
function postingPayload(App\Models\User $owner, array $overrides = []): array
{
    return array_merge([
        'sbo_id' => $owner->sbos()->first()->id,
        'title' => 'Supply of laptops',
        'description' => '<p>We need twenty laptops.</p>',
        'category' => 'Technology Services',
        'request_type' => 'product',
        'budget' => '500000',
        'budget_currency' => 'NGN',
        'order_deadline' => now()->addMonth()->toDateString(),
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
    ], $overrides);
}

test('a posted request confirms itself on the page it lands on', function (): void {
    $owner = postingBuyer();

    $this->actingAs($owner)
        ->post(route('procurement.store'), postingPayload($owner))
        ->assertRedirect()
        // The flash the detail screen renders. It was being set and nothing
        // read it.
        ->assertSessionHas('status', 'rfq-created');
});

test('a rejection says which field and why', function (): void {
    $owner = postingBuyer();

    /*
     * `visibility` had no error slot on the form, so a request rejected on it
     * failed silently. The summary at the top of the form renders whatever is
     * in the bag, which is why what matters is that the bag is filled.
     */
    $this->actingAs($owner)
        ->post(route('procurement.store'), postingPayload($owner, ['visibility' => 'nonsense']))
        ->assertSessionHasErrors('visibility');

    expect(PurchaseOrder::query()->count())->toBe(0);
});

test('a schedule that runs backwards is refused with a reason', function (): void {
    $owner = postingBuyer();

    $this->actingAs($owner)
        ->post(route('procurement.store'), postingPayload($owner, [
            'inquiry_opens_at' => now()->addDays(10)->toDateString(),
            'inquiry_closes_at' => now()->addDays(5)->toDateString(),
        ]))
        // A schedule that answers questions before they can be asked is worse
        // than no schedule at all.
        ->assertSessionHasErrors('inquiry_closes_at');
});

/* ------------------------------------------------------------ currency --- */

test('a budget must say what currency it is in', function (): void {
    $owner = postingBuyer();

    // 500000 with no currency was published beside a fixed "NGN" label, so a
    // Kenyan buyer's shillings were read as Naira.
    $this->actingAs($owner)
        ->post(route('procurement.store'), postingPayload($owner, ['budget_currency' => null]))
        ->assertSessionHasErrors('budget_currency');
});

test('a currency outside the list is refused', function (): void {
    $owner = postingBuyer();

    $this->actingAs($owner)
        ->post(route('procurement.store'), postingPayload($owner, ['budget_currency' => 'DOGE']))
        ->assertSessionHasErrors('budget_currency');
});

test('no budget needs no currency', function (): void {
    $owner = postingBuyer();

    $this->actingAs($owner)
        ->post(route('procurement.store'), postingPayload($owner, [
            'budget' => null,
            'budget_currency' => null,
        ]))
        ->assertSessionHasNoErrors();
});

test('the currency is shown with the amount', function (): void {
    $owner = postingBuyer();

    $this->actingAs($owner)->post(route('procurement.store'), postingPayload($owner, [
        'budget' => '500000',
        'budget_currency' => 'KES',
    ]));

    $order = PurchaseOrder::query()->firstOrFail();

    $this->actingAs($owner)
        ->get(route('procurement.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('request.budget', fn ($b): bool => str_contains((string) $b, 'KES')));
});

/* ----------------------------------------------------------- rich text --- */

test('markup from the editor is sanitised before it is stored', function (): void {
    $owner = postingBuyer();

    /*
     * An editor restricts what a person can TYPE, not what can be posted to
     * the endpoint — and this description is read by every other business.
     */
    $this->actingAs($owner)->post(route('procurement.store'), postingPayload($owner, [
        'description' => '<p>Twenty laptops</p><script>alert(1)</script><p onclick="steal()">now</p>',
    ]));

    $stored = PurchaseOrder::query()->value('description');

    expect($stored)->not->toContain('script')
        ->and($stored)->not->toContain('onclick')
        ->and($stored)->toContain('Twenty laptops')
        // Unwrapped, not deleted: a description that quietly loses a paragraph
        // is worse than one that loses its formatting.
        ->and($stored)->toContain('now');
});

test('formatting the editor does produce is kept', function (): void {
    $owner = postingBuyer();

    $this->actingAs($owner)->post(route('procurement.store'), postingPayload($owner, [
        'description' => '<p>We need:</p><ul><li><strong>Twenty</strong> laptops</li></ul>',
    ]));

    $stored = PurchaseOrder::query()->value('description');

    expect($stored)->toContain('<ul>')
        ->and($stored)->toContain('<strong>');
});

test('markup with no words in it is not a description', function (): void {
    $owner = postingBuyer();

    $this->actingAs($owner)
        ->post(route('procurement.store'), postingPayload($owner, ['description' => '<p><br></p>']))
        ->assertSessionHasErrors('description');
});

test('the sanitiser leaves the editor\'s own output alone', function (): void {
    /*
     * The editor's formats and the server's allowlist are kept aligned so that
     * what a writer sees is what is saved. If they drift, formatting silently
     * disappears on save and nobody finds out until a buyer notices their list
     * is gone.
     *
     * Everything below is what Quill's configured toolbar produces — note that
     * a BULLETED list is <ol><li data-list="bullet">, which is why that
     * attribute has to survive.
     */
    $fromEditor = '<h3>Scope</h3>'
        . '<p>We need <strong>twenty</strong> <em>laptops</em>, <u>delivered</u> and <s>installed</s>.</p>'
        . '<ol><li data-list="bullet">16GB RAM</li><li data-list="bullet">SSD only</li></ol>'
        . '<ol><li data-list="ordered">Quote</li><li data-list="ordered">Deliver</li></ol>'
        . '<p class="ql-align-center">Centred note</p>'
        . '<p class="ql-indent-1">Indented note</p>'
        . '<blockquote>Net 30 terms.</blockquote>'
        . '<p><a href="https://example.com/spec">Full spec</a></p>';

    $clean = RichText::clean($fromEditor);

    foreach ([
        '<h3>', '<strong>', '<em>', '<u>', '<s>', '<blockquote>',
        'data-list="bullet"', 'data-list="ordered"',
        'class="ql-align-center"', 'class="ql-indent-1"',
        'href="https://example.com/spec"',
    ] as $fragment) {
        expect($clean)->toContain($fragment);
    }

    // Idempotent: cleaning already-clean markup must change nothing, or an
    // edit-and-resave would erode a description a little each time.
    expect(RichText::clean($clean))->toBe($clean);
});

test('a class attribute cannot borrow the application\'s own styles', function (): void {
    /*
     * Alignment and indentation arrive as classes, so `class` has to be
     * allowed — but only for those two families. A free class attribute would
     * let whoever writes a description pull in any style on the page,
     * including the ones that position things over it.
     */
    $clean = RichText::clean('<p class="ql-align-center btn-primary fixed inset-0 z-50">text</p>');

    expect($clean)->toContain('ql-align-center')
        ->and($clean)->not->toContain('btn-primary')
        ->and($clean)->not->toContain('fixed');
});

test('an inline style keeps only what it is allowed to say', function (): void {
    // Parsed rather than pattern-matched: "position:fixed" hidden behind a
    // legitimate declaration is the case a regex walks straight past.
    $clean = RichText::clean('<p style="text-align:center;position:fixed;top:0">text</p>');

    expect($clean)->toContain('text-align: center')
        ->and($clean)->not->toContain('position')
        ->and($clean)->not->toContain('top');
});

test('a link is made safe rather than dropped', function (): void {
    expect(RichText::clean('<a href="https://example.com">terms</a>'))
        ->toContain('rel="noopener noreferrer nofollow"')
        ->and(RichText::clean('<a href="javascript:alert(1)">bad</a>'))
        ->not->toContain('javascript');
});

test('the card gets plain text and the detail page gets the markup', function (): void {
    $owner = postingBuyer();

    $this->actingAs($owner)->post(route('procurement.store'), postingPayload($owner, [
        'description' => '<p>We need <strong>twenty</strong> laptops.</p>',
    ]));

    $order = PurchaseOrder::query()->firstOrFail();

    $this->actingAs($owner)
        ->get(route('procurement.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Rendering markup on a card would show the tags.
            ->where('request.body', 'We need twenty laptops.')
            ->where('request.bodyHtml', fn ($html): bool => str_contains((string) $html, '<strong>')));
});
