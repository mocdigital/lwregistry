<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SboApplicationProgressBar;
use App\Support\SboFeePayload;
use App\Support\SboNetworkAccess;
use App\Support\SboWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class SboPaymentRequiredController extends Controller
{
    public function __invoke(SboWorkspaceAccess $access): InertiaResponse|RedirectResponse
    {
        $user = auth()->user();

        /*
         * The wall is the status screen for a member with an application still
         * in progress — one who still owes the fee, or has paid and is awaiting
         * approval (documents/verification). A member with nothing left to show
         * (nothing owed and already fully verified) is bounced to their
         * dashboard rather than kept staring at a screen that no longer applies.
         *
         * `blockedOnPayment` also words the screen: still owes the fee → "pay
         * it"; paid and waiting → "under review".
         */
        $blockedOnPayment = $user && $access->requiresPayment($user);
        $blockedOnVerification = $user && ! app(SboNetworkAccess::class)->userCanUseNetwork($user);

        if ( ! $user || ( ! $blockedOnPayment && ! $blockedOnVerification)) {
            return redirect()->route('sbo.dashboard');
        }

        $targetSbo = $access->paymentTarget($user);

        /*
         * The screen's "Application" panel was four hardcoded rows (a company
         * called Kemefy LLC, a fixed reference and date). They come from the
         * SBO now; the step list, benefits and timeline stay as written,
         * because they describe the flow rather than this application.
         */
        return Inertia::render('account/PaymentRequiredView', [
            // Both payment methods are scoped to the application being paid for.
            'sboId' => $targetSbo?->id,
            /*
             * The same fee schedule and gateway state as /application/fees —
             * this screen is the same step drawn a second time (384:17211),
             * so it reads the same payload rather than assembling its own.
             */
            'fees' => SboFeePayload::for($targetSbo, $user),
            'application' => $targetSbo ? [
                ['key' => 'company', 'label' => __('sbo_progress.company_name'), 'value' => $targetSbo->name],
                ['key' => 'application_id', 'label' => __('sbo_progress.application_id'), 'value' => $targetSbo->application_id
                    ?? $targetSbo->moc_registration_number ?? '—'],
                ['key' => 'submitted', 'label' => __('sbo_progress.submitted_date'), 'value' => ($targetSbo->date_received ?? $targetSbo->created_at)?->format('F j, Y') ?? '—'],
                ['key' => 'entity_type', 'label' => __('sbo_progress.entity_type'), 'value' => $targetSbo->entityTypeLabel()],
            ] : [],
            'progressUrl' => $targetSbo
                ? route('sbo.applications.progress', $targetSbo)
                : route('sbo.listings.create'),
            'applicationsUrl' => $targetSbo
                ? route('sbo.applications.index')
                : route('sbo.listings.create'),
            // Payment is taken only on the add-business page's final step, so a
            // member who still owes the fee is sent there from the wall rather
            // than being shown a payment form here.
            'payUrl' => $targetSbo
                ? route('sbo.listings.create', ['pay' => $targetSbo->id])
                : route('sbo.listings.create'),
            /*
             * A gated member who owns nothing yet. The wall still explains the
             * gate, but there is no fee to show and nothing to pay — the way
             * in is to register a business first, so the screen says that
             * instead of drawing an empty application table.
             */
            'needsRegistration' => null === $targetSbo,
            /*
             * Which gate sent them here, so the screen says the true thing:
             * "pay your fee" vs "your documents are still under review".
             */
            'reason' => $blockedOnPayment ? 'payment' : 'verification',
            'registerUrl' => route('sbo.listings.create'),
            /*
             * The hero tracker was a fixed 25% with "Step 2: Fees Payment"
             * always active, so the wall told every owner the same thing
             * however far along they were — including one blocked on
             * verification with the fee long settled.
             */
            'progress' => [
                'percent' => SboApplicationProgressBar::percent($targetSbo),
                // Where to draw the fill — see barPercent().
                'barPercent' => SboApplicationProgressBar::barPercent($targetSbo),
                'steps' => SboApplicationProgressBar::steps($targetSbo),
            ],
        ]);
    }
}
