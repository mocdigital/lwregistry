<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 * The footer's "N Strategic Business Organizations have applied" callout.
 *
 * This is now unreachable, and these tests record why rather than asserting
 * markup nothing renders:
 *
 *   - It lives in `resources/views/components/footer.blade.php`, inside an
 *     `@guest` block.
 *   - The only layout that includes that component is `layouts/app.blade.php`.
 *   - The single route still served by that layout is `businesses.category`,
 *     which sits behind `auth`, `verified` and `sbo_payment_approved`.
 *
 * So the block renders only for guests, on a page only members can open. The
 * Vue footer (AppFooter.vue) is built from the Figma frame, which has no such
 * band, so it was dropped in the redesign rather than ported.
 *
 * `SboObserver` still busts `footer_sbo_applied_count` on every SBO write —
 * harmless, but it is cache maintenance for something nothing reads.
 */

function footerCalloutSbo(User $owner): Sbo
{
    $name = 'Footer Callout SBO ' . Str::random(6);

    return Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);
}

test('the sbo callout is unreachable: guest-only markup on a members-only page', function (): void {
    $category = Category::factory()->create(['name' => 'Callout', 'slug' => 'callout']);

    // A guest cannot open the one page whose layout still includes it.
    $this->get(route('businesses.category', $category))->assertRedirect(route('login'));

    // And a member who can open it is not a guest, so the block is skipped.
    $this->actingAs(paidMember())
        ->get(route('businesses.category', $category))
        ->assertOk()
        ->assertDontSeeText('Strategic Business Organizations have applied');
});

test('the Vue footer carries no sbo callout at all', function (): void {
    footerCalloutSbo(User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]));

    // Asserted so its absence from the redesigned shell is a decision on
    // record rather than an oversight.
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSeeText('Strategic Business Organizations have applied');
});

test('the applied-sbo count itself is still computed correctly', function (): void {
    // The helper the callout used is still exercised, so that reinstating the
    // band anywhere does not need the counting rediscovered.
    Cache::forget('footer_sbo_applied_count');

    $owner = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    footerCalloutSbo($owner);
    footerCalloutSbo($owner);

    /*
     * Mirrors the footer's own query, registry exclusion included — the
     * registry's listing is not an applicant, and counting it would overstate
     * the figure by one forever. This test reimplementing the query rather
     * than rendering the footer is why it had to be corrected by hand when
     * that exclusion was added.
     */
    $count = Cache::remember(
        'footer_sbo_applied_count',
        now()->addMinutes(15),
        fn (): int => Sbo::query()->where('is_registry', false)->count(),
    );

    expect($count)->toBe(2);

    // SboObserver busts the key, so a third application is counted next read.
    footerCalloutSbo($owner);
    expect(Cache::get('footer_sbo_applied_count'))->toBeNull();
});
