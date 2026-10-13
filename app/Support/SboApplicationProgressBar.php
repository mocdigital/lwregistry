<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;

/**
 * How far an application has come, and which step it is on.
 *
 * Three steps: (1) add business + payment, (2) documents, (3) verification.
 * Step 1 is complete only once the registration fee is APPROVED — creating the
 * business alone earns nothing, so "submitted" never shows done before payment.
 */
final class SboApplicationProgressBar
{
    /** Translation keys — a const cannot know the request's locale. */
    public const STEPS = [
        'payment' => 'steps.business_payment',
        'documents' => 'steps.documents',
        'verification' => 'steps.verification',
    ];

    public static function percent(?Sbo $sbo): int
    {
        if ( ! $sbo instanceof Sbo) {
            return 0;
        }

        $done = ($sbo->feesPaid() ? 1 : 0)
            + ($sbo->documentsSubmitted() ? 1 : 0)
            + ('approved' === $sbo->verification_status ? 1 : 0);

        return (int) round($done / 3 * 100);
    }

    /**
     * How far along the track to draw the FILL, which is not the same number
     * as `percent()`.
     *
     * `percent()` counts the four steps independently, because they really are
     * independent — an admin can clear documents while the fee is outstanding.
     * But the tracker draws its four markers evenly across the bar, so a
     * count-based fill drifts away from the marker it is supposed to be at: an
     * application with the fee unpaid and documents already compliant scores
     * 50%, which lands the fill past the Step 3 marker while the Step 2 dot is
     * the one lit as active. The bar said "documents" and the dot said "fees".
     *
     * The fill therefore ends exactly ON the active marker. Markers sit at
     * 0 / 33 / 67 / 100 (a `justify-between` row of four), so the active index
     * decides the width, and a finished application fills the track.
     */
    public static function barPercent(?Sbo $sbo): int
    {
        if ( ! $sbo instanceof Sbo) {
            return 0;
        }

        $steps = self::steps($sbo);
        $activeIndex = null;

        foreach ($steps as $i => $step) {
            if ('active' === $step['state']) {
                $activeIndex = $i;

                break;
            }
        }

        // Nothing active means every step is done.
        if (null === $activeIndex) {
            return 100;
        }

        /*
         * A brand-new, unpaid application sits on step 1 (index 0), so the fill
         * is legitimately zero-width until the fee is approved.
         */
        return (int) round($activeIndex / max(1, count($steps) - 1) * 100);
    }

    /**
     * The step the applicant should actually be looking at.
     *
     * The route defaulted to `fees` for everyone, so an owner who had just paid
     * was sent back to the payment screen and had to find Step 3 themselves —
     * and one whose documents had been accepted was still shown payment. This
     * is the first step that is not finished, which is where the flow should
     * resume.
     *
     * The keys here are the route's own segments, which differ from the step
     * keys used internally: `submitted` is a separate designed screen, so an
     * application that has only been submitted resumes on `fees`.
     */
    public static function currentStep(?Sbo $sbo): string
    {
        if ( ! $sbo instanceof Sbo) {
            return 'fees';
        }

        // The owner has not paid (nor submitted a payment awaiting confirmation).
        if ( ! $sbo->feesPaid()) {
            return 'fees';
        }

        // Paid/submitted, but the required documents are not all uploaded yet.
        if ( ! $sbo->documentsSubmitted()) {
            return 'documents';
        }

        // Everything the owner can do is done; verification is the admin review.
        return 'verification';
    }

    /**
     * Each step as done / active / todo.
     *
     * "Active" is the first step not yet done rather than a fixed position, so
     * an owner whose fee cleared but whose documents are outstanding is shown
     * on documents, not still on payment.
     *
     * @return array<int, array{label: string, state: string}>
     */
    public static function steps(?Sbo $sbo): array
    {
        $done = [
            // Step 1 (add business + payment) is done only once the fee is
            // approved — the business existing is not enough.
            'payment' => $sbo?->feesPaid() ?? false,
            'documents' => $sbo?->documentsSubmitted() ?? false,
            'verification' => 'approved' === $sbo?->verification_status,
        ];

        $activeFound = false;
        $steps = [];

        foreach (self::STEPS as $key => $label) {
            if ($done[$key]) {
                $steps[] = ['key' => $key, 'label' => __($label), 'state' => 'done'];

                continue;
            }

            $steps[] = ['key' => $key, 'label' => __($label), 'state' => $activeFound ? 'todo' : 'active'];
            $activeFound = true;
        }

        return $steps;
    }
}
