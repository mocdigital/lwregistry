<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\User;
use App\Support\SboApplicationProgressBar;
use Illuminate\Support\Str;

/*
 * The hero progress tracker — three steps: (1) business + payment,
 * (2) documents, (3) verification. Step 1 is done only once the fee is
 * APPROVED, so a business that exists but has not paid earns nothing.
 *
 * `percent()` counts the steps independently, which is right — an admin can
 * clear documents while the fee is still outstanding. But the tracker draws its
 * markers evenly across the bar (0 / 50 / 100 for three), so the fill lands on
 * the ACTIVE marker rather than on the completion count.
 */

function barSbo(array $overrides = []): Sbo
{
    $owner = User::factory()->create(['email_verified_at' => now()]);

    return Sbo::create(array_merge([
        'name' => 'Bar Co',
        'slug' => 'bar-co-' . Str::random(6),
        'created_by' => $owner->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('the fill lands on the active marker, not on the completion count', function (): void {
    /*
     * Documents cleared, fee still due. One of three steps is done (33%), but
     * the member is still on Step 1 — whose marker sits at the very start.
     */
    $sbo = barSbo([
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
    ]);

    $steps = SboApplicationProgressBar::steps($sbo);

    expect($steps[0]['state'])->toBe('active')
        ->and($steps[1]['state'])->toBe('done')
        // The count is still honest and still shown as text.
        ->and(SboApplicationProgressBar::percent($sbo))->toBe(33)
        // The fill stops at the Step 1 marker rather than overshooting it.
        ->and(SboApplicationProgressBar::barPercent($sbo))->toBe(0);
});

test('a brand-new application sits on step 1 until the payment is approved', function (): void {
    $sbo = barSbo();

    /*
     * Adding the business earns nothing on its own: Step 1 covers the business
     * AND its payment, so it stays active — and the bar empty — until the fee
     * is approved.
     */
    expect(SboApplicationProgressBar::steps($sbo)[0]['state'])->toBe('active')
        ->and(SboApplicationProgressBar::percent($sbo))->toBe(0)
        ->and(SboApplicationProgressBar::barPercent($sbo))->toBe(0);
});

test('step 1 completes once the payment is approved', function (): void {
    $sbo = barSbo(['payment_status' => 'paid', 'payment_confirmed_at' => now()]);

    expect(SboApplicationProgressBar::steps($sbo)[0]['state'])->toBe('done')
        ->and(SboApplicationProgressBar::percent($sbo))->toBe(33);
});

test('a finished application fills the track', function (): void {
    $sbo = barSbo([
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    expect(SboApplicationProgressBar::percent($sbo))->toBe(100)
        ->and(SboApplicationProgressBar::barPercent($sbo))->toBe(100);
});

test('the fill tracks the active step as the application advances', function (): void {
    $sbo = barSbo([
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    // Fee approved, documents outstanding: active is Step 2, marker at 50%.
    expect(SboApplicationProgressBar::steps($sbo)[1]['state'])->toBe('active')
        ->and(SboApplicationProgressBar::barPercent($sbo))->toBe(50);
});

test('no application at all draws an empty bar rather than erroring', function (): void {
    expect(SboApplicationProgressBar::barPercent(null))->toBe(0)
        ->and(SboApplicationProgressBar::percent(null))->toBe(0);
});
