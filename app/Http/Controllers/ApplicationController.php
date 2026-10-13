<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ComplianceDocumentType;
use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Support\SboApplicationProgressBar;
use App\Support\SboComplianceRequirements;
use App\Support\SboFeePayload;
use App\Support\SboSealPanel;
use App\Support\SboWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * `/application/{step}` — the Vue application-progress screens.
 *
 * The SPA links here without an SBO id, while Laravel's equivalent
 * (`sbo/applications/{sbo}/progress`) requires one. Rather than invent a
 * parameter for the links to carry, the SBO is resolved the same way
 * `post-login` already resolves it: the owner's payment target, which is their
 * oldest unpaid SBO, falling back to their oldest. An owner working through
 * registration has exactly one application in flight, so this is the same SBO
 * the rest of the flow means.
 *
 * Owners with several SBOs should use `sbo/applications/{sbo}/progress`, which
 * is explicit. This route is the "my current application" shortcut.
 */
final class ApplicationController extends Controller
{
    private const STEPS = ['submitted', 'fees', 'documents', 'verification'];

    public function show(SboWorkspaceAccess $access, ?string $step = null): InertiaResponse|RedirectResponse
    {
        $sbo = $access->paymentTarget(auth()->user());

        if (null === $sbo) {
            return redirect()->route('sbo.applications.index');
        }

        /*
         * No step in the URL means "take me to my application", so resolve the
         * one the applicant is actually on. It used to default to `fees` for
         * everyone, which sent an owner who had just paid back to the payment
         * screen to find Step 3 themselves. An explicit step is still honoured,
         * so a completed stage stays reviewable.
         */
        $resolved = in_array($step, self::STEPS, true)
            ? $step
            : SboApplicationProgressBar::currentStep($sbo);

        /*
         * Payment now lives on the add-business page's final step
         * (the add-business page), so this flow no longer has its own fee screen —
         * that was the duplicate payment page. Any route to the fee step, and
         * the document gate's fee fallback, send the owner to that single
         * payment page instead.
         *
         * Step 3 (document uploads) is gated on the registration fee having been
         * PAID — either settled, or submitted and awaiting an admin's
         * confirmation. The owner does not wait for that confirmation to upload
         * their documents; the admin reviews everything once the steps are done.
         */
        if ('fees' === $resolved || ('documents' === $resolved && ! $sbo->feesPaid())) {
            return redirect()->route('sbo.listings.create', ['pay' => $sbo->id]);
        }

        return Inertia::render('account/ApplicationView', [
            // 'SBO' or 'COB', for this application's business. See useEntityTerm.
            'entityTerm' => $sbo->entity_type ?: Sbo::ENTITY_SBO,
            'step' => $resolved,
            'sbo' => [
                'id' => $sbo->id,
                'name' => $sbo->name,
                'slug' => $sbo->slug,
                'applicationId' => $sbo->application_id ?? $sbo->moc_registration_number,
                'progressUrl' => route('sbo.applications.progress', $sbo),
            ],
            /*
             * Step 3 drew an "Upload Documents" call to action and no upload.
             * The requirement list is country-specific (see
             * SboComplianceRequirements), so it has to come from the server —
             * a Nigerian SBO and a UK one are asked for different things.
             */
            'documents' => $this->documents($sbo),
            // The TIN NUMBER is typed here (the certificate is uploaded like any
            // other compliance document); stored on the SBO.
            'taxNumber' => $sbo->tax_number,
            /*
             * Step 2's calculator showed three invented share structures and a
             * hardcoded "50 Espees". The real schedule is country-scoped and
             * admin-managed; see SboFeePayload.
             */
            'fees' => SboFeePayload::for($sbo, auth()->user()),
            /*
             * The company-details card was a fixed array — "Kemefy LLC",
             * "MRN-2026-0004", "July 25, 2025" — so every applicant was shown
             * somebody else's registration. Same four rows as the payment wall,
             * which reads them from the same columns.
             */
            'application' => [
                ['key' => 'company', 'label' => __('sbo_progress.company_name'), 'value' => $sbo->name],
                ['key' => 'application_id', 'label' => __('sbo_progress.application_id'), 'value' => $sbo->application_id
                    ?? $sbo->moc_registration_number ?? '—'],
                ['key' => 'submitted', 'label' => __('sbo_progress.submitted_date'), 'value' => ($sbo->date_received ?? $sbo->created_at)?->format('F j, Y') ?? '—'],
                ['key' => 'entity_type', 'label' => __('sbo_progress.entity_type'), 'value' => $sbo->entityTypeLabel()],
            ],
            /*
             * Completion is derived, not stored. The screen showed a fixed
             * 25/50/100% per step regardless of the application's real state,
             * so a paid application still read "25% complete".
             */
            'progress' => [
                'percent' => SboApplicationProgressBar::percent($sbo),
                // Where to draw the fill — see barPercent(); not the same
                // number as `percent`, and deliberately so.
                'barPercent' => SboApplicationProgressBar::barPercent($sbo),
                'feesPaid' => $sbo->feesPaid(),
                'feeSettled' => $sbo->feesPaid(),
                'documentsCompliant' => 'compliant' === $sbo->documents_status,
                'documentsSubmitted' => $sbo->documentsSubmitted(),
                'verified' => 'approved' === $sbo->verification_status,
            ],
        ]);
    }

    /**
     * `/sbo/progress` — the journey's final screen. Same resolution as above.
     */
    public function progress(SboWorkspaceAccess $access): InertiaResponse|RedirectResponse
    {
        $sbo = $access->paymentTarget(auth()->user());

        if (null === $sbo) {
            return redirect()->route('sbo.applications.index');
        }

        return $this->renderProgress($sbo);
    }

    /**
     * `sbo/applications` — the owner's applications. With one application in
     * flight this is the same screen as the progress view, so it defers rather
     * than duplicating it; owners with several land on their current one.
     */
    public function index(SboWorkspaceAccess $access): InertiaResponse|RedirectResponse
    {
        $sbo = $access->paymentTarget(auth()->user());

        if (null === $sbo) {
            return redirect()->route('sbo.dashboard');
        }

        return $this->renderProgress($sbo);
    }

    /**
     * The same screen for an explicitly named SBO. `post-login` sends unpaid
     * owners here, so this is the first thing a new registrant sees.
     */
    public function forSbo(Sbo $sbo): InertiaResponse
    {
        abort_unless($sbo->created_by === auth()->id(), 403);

        return $this->renderProgress($sbo);
    }

    /**
     * Resubmit after the review team asked for more information.
     *
     * The Vue progress screen relayed the info request but offered no way to
     * act on it, so an applicant told to update their details was left staring
     * at the message — the Blade page had a "Resubmit for review" button and it
     * was not carried over. Same rules as that button: the owner only, and only
     * while the application is actually in the info-requested state.
     */
    public function resubmit(Sbo $sbo): RedirectResponse
    {
        abort_unless($sbo->created_by === auth()->id(), 403);
        abort_unless($sbo->isInfoRequested(), 422, 'This application is not awaiting more information.');

        $sbo->update([
            'verification_status' => 'pending',
            'resubmitted_at' => now(),
        ]);

        // The admin queue is driven by this feed, so a resubmission that is not
        // logged is a resubmission no reviewer sees.
        ActivityLog::create([
            'type' => 'sbo_resubmitted',
            'title' => $sbo->name,
            'description' => 'Applicant resubmitted <strong>' . e($sbo->name) . '</strong> after an info request.',
            'status' => 'pending',
            'user_id' => auth()->id(),
        ]);

        return back()->with('status', 'application-resubmitted');
    }

    /**
     * Save the typed Tax Identification Number onto the current application.
     * The TIN certificate is a separate compliance-document upload.
     */
    public function storeTin(Request $request, SboWorkspaceAccess $access): RedirectResponse
    {
        $sbo = $access->paymentTarget($request->user());

        if (null === $sbo) {
            return redirect()->route('sbo.applications.index');
        }

        // Same gate as the documents step: the payment must be approved.
        abort_unless(
            $sbo->feesPaid(),
            403,
            'Your registration fee payment must be approved before adding your TIN.',
        );

        $data = $request->validate([
            'tax_number' => ['required', 'string', 'max:50', 'regex:/^[0-9][0-9\- ]*$/'],
        ], [
            'tax_number.regex' => 'Enter a valid TIN — digits only.',
        ]);

        $sbo->update(['tax_number' => mb_trim($data['tax_number'])]);

        return back()->with('status', 'tin-saved');
    }

    private function renderProgress(Sbo $sbo): InertiaResponse
    {
        return Inertia::render('sbo/SboProgressView', [
            // The word this page (and the chrome around it) uses for the
            // business — 'SBO' or 'COB'. See useEntityTerm.
            'entityTerm' => $sbo->entity_type ?: Sbo::ENTITY_SBO,
            'sbo' => [
                'name' => $sbo->name,
                'slug' => $sbo->slug,
                'applicationId' => $sbo->application_id ?? $sbo->moc_registration_number,
                'submittedOn' => ($sbo->date_received ?? $sbo->created_at)?->format('F j, Y'),
                'progressUrl' => route('sbo.applications.progress', $sbo),
                'entityType' => $sbo->entityTypeLabel(),
            ],
            'progress' => [
                'percent' => SboApplicationProgressBar::percent($sbo),
                // Where to draw the fill — see barPercent(); not the same
                // number as `percent`, and deliberately so.
                'barPercent' => SboApplicationProgressBar::barPercent($sbo),
                'feesPaid' => $sbo->feesPaid(),
                'feeSettled' => $sbo->feesPaid(),
                'documentsCompliant' => 'compliant' === $sbo->documents_status,
                'documentsSubmitted' => $sbo->documentsSubmitted(),
                'verified' => 'approved' === $sbo->verification_status,
                /*
                 * Per-step state. The progress screen listed all four steps
                 * with a green tick regardless, and its hero congratulated
                 * every applicant on completing a verification they might not
                 * have reached.
                 */
                'steps' => SboApplicationProgressBar::steps($sbo),
            ],
            /*
             * The real seal and certificate, the same panel the profile
             * dashboard shows. This screen drew a static "LBN Certified Member
             * 2026" card with a VETTED pill and a dead Download button, so a
             * member with a genuinely issued seal saw a fake one and could not
             * reach the certificate that existed.
             */
            'seal' => SboSealPanel::forSbo($sbo),
            /*
             * Why the application is where it is.
             *
             * The Blade progress page told an applicant that more information
             * had been requested, or that they had been declined and why. The
             * Vue screen received neither, so those states were invisible —
             * an applicant could be blocked on a question nobody showed them.
             */
            'status' => [
                'verification' => $sbo->verification_status,
                'infoRequest' => $sbo->info_request,
                'declineReason' => $sbo->decline_reason,
            ],
            // Drives the "Resubmit for review" button: shown only when there is
            // something to resubmit, and only to the applicant themselves.
            'canResubmit' => $sbo->isInfoRequested() && $sbo->created_by === auth()->id(),
            'resubmitUrl' => route('sbo.applications.resubmit', $sbo),
            // The same country-specific requirement list as step 3, so the
            // progress page can name what is outstanding and why.
            'documents' => $this->documents($sbo),
        ]);
    }

    /**
     * Every document type this SBO has to supply, with whatever it has already
     * submitted against each one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documents(Sbo $sbo): array
    {
        $submitted = $sbo->complianceDocuments()->get()->keyBy('compliance_document_type_id');

        return SboComplianceRequirements::activeFor($sbo)
            ->map(function (ComplianceDocumentType $type) use ($submitted): array {
                /** @var SboComplianceDocument|null $document */
                $document = $submitted->get($type->id);

                return [
                    'typeId' => $type->id,
                    'name' => $type->name,
                    'description' => $type->description,
                    'required' => (bool) $type->is_required,
                    // null = nothing uploaded yet; otherwise pending/approved/rejected.
                    'status' => $document?->status,
                    'rejectionReason' => $document?->rejection_reason,
                    'fileName' => $document?->file()?->file_name,
                    'downloadUrl' => $document && $document->file()
                        ? route('compliance-documents.download', $document)
                        : null,
                ];
            })
            ->values()
            ->all();
    }
}
