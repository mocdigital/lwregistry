<?php

use App\Livewire\SboComplianceDocuments;
use App\Models\ActivityLog;
use App\Models\Sbo;
use App\Support\SboApplicationProgress;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class() extends Component
{
    public Sbo $sbo;

    #[Url(as: 'step', history: true, keep: true)]
    public string $activeStep = '';

    public function mount(Sbo $sbo): void
    {
        $user = auth()->user();
        $isOwnerViewing = $sbo->created_by === $user->id;

        abort_unless($isOwnerViewing || $user->isAdmin(), 403);

        $this->sbo = $sbo;

        $progress = SboApplicationProgress::for($this->sbo);
        $this->activeStep = $progress['current_key'];

        // Allow deep-linking to a specific step (e.g. ?step=fees from the
        // dashboard "Make Payment" button). Honour it only when that step is
        // actually accessible; otherwise fall back to the current step.
        $requestedStep = $this->activeStep ?: (string) request()->query('step', '');

        if ('' !== $requestedStep && $this->canAccessStep($requestedStep, $progress)) {
            $this->activeStep = $requestedStep;
        }
    }

    public function showStep(string $step): void
    {
        if ( ! $this->canAccessStep($step)) {
            return;
        }

        $this->activeStep = $step;
    }

    public function nextStep(): void
    {
        $progress = $this->progress();
        $keys = $this->stepKeys();
        $index = array_search($this->activeStep, $keys, true);

        if (false === $index || ! $this->canAccessStep($this->activeStep, $progress)) {
            $this->activeStep = $progress['current_key'];

            return;
        }

        $active = collect($progress['steps'])->firstWhere('key', $this->activeStep);

        if ( ! is_array($active) || ! $active['done']) {
            return;
        }

        if (isset($keys[$index + 1]) && $this->canAccessStep($keys[$index + 1], $progress)) {
            $this->activeStep = $keys[$index + 1];
        }
    }

    public function submitDocuments(): void
    {
        if (
            $this->activeStep !== 'documents'
            || $this->sbo->created_by !== auth()->id()
            || $this->sbo->isDeclined()
            || $this->sbo->documentsCompliant()
        ) {
            return;
        }

        $this->dispatch('submit-compliance-documents', sboId: $this->sbo->id)
            ->to(SboComplianceDocuments::class);
    }

    #[On('compliance-documents-saved')]
    public function continueAfterDocumentsSaved(int $sboId): void
    {
        if ($sboId !== $this->sbo->id || $this->activeStep !== 'documents') {
            return;
        }

        $this->nextStep();
    }

    public function previousStep(): void
    {
        $progress = $this->progress();
        $keys = $this->stepKeys();
        $index = array_search($this->activeStep, $keys, true);

        if (false !== $index && isset($keys[$index - 1]) && $this->canAccessStep($keys[$index - 1], $progress)) {
            $this->activeStep = $keys[$index - 1];
        }
    }

    public function resubmit(): void
    {
        $user = auth()->user();
        $isOwnerViewing = $this->sbo->created_by === $user->id;

        if (! $isOwnerViewing || ! $this->sbo->isInfoRequested()) {
            return;
        }

        $this->sbo->update([
            'verification_status' => 'pending',
            'resubmitted_at' => now(),
        ]);

        ActivityLog::create([
            'type' => 'sbo_resubmitted',
            'title' => $this->sbo->name,
            'description' => 'Applicant resubmitted <strong>' . e($this->sbo->name) . '</strong> after an info request.',
            'status' => 'pending',
            'user_id' => $user->id,
        ]);

        $this->sbo->refresh();
        $this->activeStep = SboApplicationProgress::for($this->sbo)['current_key'];

        session()->flash('success', 'Your SBO has been resubmitted for review.');
    }

    public function with(): array
    {
        $progress = $this->progress();

        if ( ! $this->canAccessStep($this->activeStep, $progress)) {
            $this->activeStep = $progress['current_key'];
        }

        return [
            'progress' => $progress,
            'isAdminViewing' => auth()->user()->isAdmin() && $this->sbo->created_by !== auth()->id(),
            'accessibleSteps' => $this->accessibleStepKeys($progress),
            'ownedSbos' => Sbo::query()
                ->where('created_by', auth()->id())
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stepKeys(): array
    {
        return ['submitted', 'fees', 'documents', 'verified'];
    }

    /**
     * @return array{
     *     steps: array<int, array{key: string, label: string, done: bool, status: string, description: string}>,
     *     current_key: string,
     *     percent: int,
     *     badge: array{class: string, label: string},
     *     outstanding: array<int, string>,
     *     required_documents_count: int,
     *     approved_required_documents_count: int
     * }
     */
    private function progress(): array
    {
        $this->sbo->refresh();

        return SboApplicationProgress::for($this->sbo);
    }

    /**
     * @param array{
     *     steps: array<int, array{key: string, label: string, done: bool, status: string, description: string}>,
     *     current_key: string
     * }|null $progress
     */
    private function canAccessStep(string $step, ?array $progress = null): bool
    {
        $progress ??= $this->progress();

        if ( ! in_array($step, $this->stepKeys(), true)) {
            return false;
        }

        $keys = array_column($progress['steps'], 'key');
        $targetIndex = array_search($step, $keys, true);
        $currentIndex = array_search($progress['current_key'], $keys, true);
        $target = collect($progress['steps'])->firstWhere('key', $step);

        if (false === $targetIndex || false === $currentIndex || ! is_array($target)) {
            return false;
        }

        return $target['done'] || $targetIndex <= $currentIndex;
    }

    /**
     * @param array{
     *     steps: array<int, array{key: string, label: string, done: bool, status: string, description: string}>,
     *     current_key: string
     * } $progress
     * @return array<int, string>
     */
    private function accessibleStepKeys(array $progress): array
    {
        return collect($progress['steps'])
            ->pluck('key')
            ->filter(fn (string $step): bool => $this->canAccessStep($step, $progress))
            ->values()
            ->all();
    }
};
?>

<div class="sbo-progress-remake sbo-remake">
    <x-ad-slot name="dashboard_top" />

    @php
        $badge = $progress['badge'];
        $steps = collect($progress['steps']);
        $active = $steps->firstWhere('key', $activeStep) ?? $progress['steps'][0];
        $activeIndex = array_search($activeStep, $steps->pluck('key')->all(), true);
        $nextKey = $activeIndex !== false ? ($progress['steps'][$activeIndex + 1]['key'] ?? null) : null;
        $previousKey = $activeIndex !== false ? ($progress['steps'][$activeIndex - 1]['key'] ?? null) : null;
        $canGoNext = $nextKey !== null && $active['done'] && in_array($nextKey, $accessibleSteps, true);
        $canSubmitDocuments = $activeStep === 'documents'
            && ! $isAdminViewing
            && ! $sbo->isDeclined()
            && ! $sbo->documentsCompliant()
            && $progress['required_documents_count'] > 0;
        $documentContinueLabel = $nextKey === null ? 'Submit' : 'Next';
        $canGoPrevious = $previousKey !== null && in_array($previousKey, $accessibleSteps, true);
        $activeTone = $active['done'] ? 'success' : ($active['status'] === 'blocked' ? 'danger' : ($active['status'] === 'action' ? 'info' : 'warning'));
        $activeStatus = $active['done'] ? 'Complete' : ($active['status'] === 'blocked' ? 'Stopped' : ($active['status'] === 'action' ? 'Action needed' : 'In progress'));
        $breadcrumbs = [
            ['label' => 'SBO Workspace', 'href' => route('sbo.dashboard'), 'wireNavigate' => true],
            ['label' => 'Applications', 'href' => route('sbo.applications.index'), 'wireNavigate' => true],
            ['label' => $sbo->name, 'href' => route('sbo.applications.progress', $sbo), 'wireNavigate' => true],
            ['label' => $active['label'], 'current' => true],
        ];
    @endphp

    <x-ui.page-header
        :title="$sbo->name"
        :status="$badge['label']"
        :status-tone="$sbo->isFullyVerified() ? 'success' : ($sbo->isDeclined() ? 'danger' : 'info')"
        :breadcrumbs="$breadcrumbs"
    >
        <x-slot:meta>
            <span>Application ID: <strong>{{ $sbo->application_id ?? '—' }}</strong></span>
            @if($sbo->moc_registration_number)
                <span>MOC #: <strong>{{ $sbo->moc_registration_number }}</strong></span>
            @endif
            <span>{{ $progress['percent'] }}% complete</span>
        </x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('sbo.dashboard') }}" class="btn btn-outline-secondary rounded-pill" wire:navigate>
                SBO Dashboard
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.sbo-context
        :sbo="$sbo"
        :options="$ownedSbos"
        :step="$activeStep"
        :show-switcher="!$isAdminViewing"
    />

    <x-ui.flash />

    <div class="sbo-progress-layout">
        <aside class="sbo-progress-sidebar">
            <section class="sbo-progress-steps-panel" x-data="{ open: false }" aria-labelledby="sbo-progress-steps-title">
                <div class="sbo-progress-steps-panel__body">
                    {{-- Header doubles as the mobile toggle; inert on desktop. --}}
                    <button type="button"
                        class="sbo-progress-steps-panel__toggle"
                        @click="open = ! open"
                        :aria-expanded="open ? 'true' : 'false'"
                        aria-controls="sbo-progress-steps">
                        <span class="d-flex justify-content-between align-items-center gap-2">
                            <span>
                                <span class="sbo-progress-steps-panel__eyebrow">Registration journey</span>
                                <span id="sbo-progress-steps-title" class="sbo-progress-steps-panel__title">Overall progress</span>
                            </span>
                            <span class="d-flex align-items-center gap-2">
                                <span class="sbo-progress-steps-panel__percent">{{ $progress['percent'] }}%</span>
                                <i class="bi bi-chevron-down sbo-progress-card__chevron d-lg-none"
                                   :class="{ 'sbo-progress-card__chevron--open': open }" aria-hidden="true"></i>
                            </span>
                        </span>
                    </button>

                    <div class="sbo-progress-track mt-3 mb-lg-4" role="progressbar" aria-label="Overall application progress" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" role="progressbar" style="width: {{ $progress['percent'] }}%;" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>

                    {{-- Steps: always shown lg+ (CSS), toggle-gated below lg (Alpine class). --}}
                    <div id="sbo-progress-steps"
                         class="sbo-progress-card__steps sbo-progress-step-list mt-3 mt-lg-0"
                         :class="{ 'sbo-progress-card__steps--open': open }">
                        @foreach($progress['steps'] as $index => $step)
                            @php
                                $isAccessible = in_array($step['key'], $accessibleSteps, true);
                                $isActive = $activeStep === $step['key'];
                            @endphp
                            <button type="button"
                                class="sbo-progress-step d-flex align-items-start gap-2 {{ $isActive ? 'is-active' : '' }} {{ ! $isAccessible ? 'is-disabled' : '' }}"
                                wire:click="showStep('{{ $step['key'] }}')"
                                @disabled(! $isAccessible)
                                aria-disabled="{{ $isAccessible ? 'false' : 'true' }}">
                                @if($step['done'])
                                    <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                                @elseif(! $isAccessible)
                                    <i class="bi bi-lock text-muted" aria-hidden="true"></i>
                                @elseif($step['status'] === 'blocked')
                                    <i class="bi bi-x-circle-fill text-danger" aria-hidden="true"></i>
                                @elseif($step['status'] === 'action')
                                    <i class="bi bi-exclamation-circle-fill text-info" aria-hidden="true"></i>
                                @else
                                    <i class="bi bi-circle {{ $isActive ? 'text-white' : 'text-muted' }}" aria-hidden="true"></i>
                                @endif
                                <span>
                                    <span class="d-block fw-semibold">{{ $index + 1 }}. {{ $step['label'] }}</span>
                                    <span class="small {{ $isActive ? 'text-white-50' : 'text-muted' }}">
                                        {{ $step['description'] }}
                                        @unless($isAccessible)
                                            <span class="d-block">Complete the previous mandatory step first.</span>
                                        @endunless
                                    </span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </section>
        </aside>

        <main class="sbo-progress-main">
            <section class="sbo-progress-content" aria-labelledby="sbo-active-step-title">
                <div class="sbo-progress-content__body">
                    <div class="sbo-progress-content__header">
                        <div>
                            <div class="sbo-progress-content__eyebrow">Step {{ ($activeIndex === false ? 0 : $activeIndex) + 1 }} of {{ count($progress['steps']) }}</div>
                            <h2 id="sbo-active-step-title" class="sbo-progress-content__title">{{ $active['label'] }}</h2>
                            <p class="sbo-progress-content__description">{{ $active['description'] }}</p>
                        </div>
                        <span class="ui-status ui-status--{{ $activeTone }}">{{ $activeStatus }}</span>
                    </div>

                    @if($activeStep === 'submitted')
                        <div class="sbo-progress-detail">
                            <div class="fw-semibold mb-1">Submission received</div>
                            <div class="small text-muted">Your application was received{{ $sbo->date_received ? ' on ' . $sbo->date_received->format('M j, Y') : '' }} and assigned Application ID <strong>{{ $sbo->application_id ?? '—' }}</strong>.</div>
                        </div>

                        @if($sbo->isDeclined())
                            <div class="alert alert-danger mt-3">
                                <div class="fw-semibold">Application declined</div>
                                <div>{{ $sbo->decline_reason ?: 'No decline reason was provided.' }}</div>
                            </div>
                        @elseif($sbo->isInfoRequested())
                            <div class="alert alert-info mt-3">
                                <div class="fw-semibold">More information requested</div>
                                <div>{{ $sbo->info_request ?: 'The review team requested more information.' }}</div>
                            </div>
                            @unless($isAdminViewing)
                                <button type="button" class="btn btn-info rounded-pill"
                                    wire:click="resubmit"
                                    wire:confirm="Confirm you have updated the requested information. This resubmits your SBO for review.">
                                    Resubmit for review
                                </button>
                            @endunless
                        @endif
                    @elseif($activeStep === 'fees')
                        @if($sbo->feesPaid())
                            <div class="alert alert-success mb-0">Registration fees have been confirmed by the registry team.</div>
                        @elseif($sbo->isDeclined())
                            <div class="alert alert-danger mb-0">This application was declined, so fee confirmation is not currently active.</div>
                        @elseif($isAdminViewing)
                            <div class="alert alert-info mb-0">Payment proof upload is available to the assigned SBO manager.</div>
                        @else
                            {{-- Fee selection (share-capital-based) + proof upload; the
                                 SboFees component computes the fee and stores the proof
                                 via SboPaymentProof, superseding the plain uploader. --}}
                            <livewire:sbo-fees :sbo-id="$sbo->id" :key="'fees-'.$sbo->id" />
                        @endif
                    @elseif($activeStep === 'documents')
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div class="small text-muted">
                                Approved required documents:
                                <strong>{{ $progress['approved_required_documents_count'] }}</strong> /
                                <strong>{{ $progress['required_documents_count'] }}</strong>
                            </div>
                            @if($sbo->documentsCompliant())
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Documents compliant</span>
                            @endif
                        </div>

                        @if($sbo->isDeclined())
                            <div class="alert alert-danger mb-0">This application was declined, so document upload is not currently available.</div>
                        @elseif($sbo->documentsCompliant())
                            <div class="alert alert-success mb-0">All required documents have been approved.</div>
                        @elseif($isAdminViewing)
                            <div class="alert alert-info mb-0">Document upload is available to the assigned SBO manager.</div>
                        @else
                            <livewire:sbo-compliance-documents :sbo-id="$sbo->id" :key="'progress-compliance-'.$sbo->id" />
                        @endif
                    @elseif($activeStep === 'verified')
                        @if($sbo->isFullyVerified())
                            <div class="alert alert-success">
                                Your SBO is fully verified and can access verified SBO features.
                            </div>
                            @if($sbo->moc_registration_number)
                                <div class="border rounded p-3">
                                    <div class="small text-muted">Ministry of Commerce Registration Number</div>
                                    <div class="fw-semibold mb-2">{{ $sbo->moc_registration_number }}</div>
                                    <a href="{{ route('sbo.verify', $sbo->moc_registration_number) }}" class="btn btn-sm btn-outline-success rounded-pill" target="_blank">
                                        <i class="bi bi-patch-check me-1"></i> Validate registration
                                    </a>
                                </div>
                            @endif
                            @if($sbo->seal?->existsOnDisk())
                                <div class="border rounded p-3 mt-3 d-flex flex-column flex-md-row align-items-md-center gap-3">
                                    <img src="{{ $sbo->seal->url() }}" alt="{{ $sbo->name }} digital SBO seal" style="width:120px;height:120px;object-fit:contain;">
                                    <div class="flex-grow-1">
                                        <div class="small text-muted">Personalized Digital Seal</div>
                                        <div class="fw-semibold mb-2">{{ $sbo->seal->issued_number }}</div>
                                        <a href="{{ route('sbo.seals.download', $sbo->seal) }}" class="btn btn-sm btn-primary rounded-pill" target="_blank">
                                            <i class="bi bi-download me-1"></i> Download seal
                                        </a>
                                    </div>
                                </div>
                            @endif
                        @else
                            <div class="alert alert-secondary mb-0">Final verification is pending registry approval after registration fees and required documents are complete.</div>
                        @endif
                    @endif

                    <hr class="sbo-progress-divider">

                    <div class="ui-action-bar">
                        <button type="button" class="btn btn-outline-secondary rounded-pill"
                            wire:click="previousStep"
                            @disabled(! $canGoPrevious)>
                            <i class="bi bi-arrow-left me-1"></i> Back
                        </button>
                        @if($canSubmitDocuments)
                            <button type="button" class="btn btn-primary rounded-pill"
                                wire:click="submitDocuments"
                                wire:loading.attr="disabled"
                                wire:target="submitDocuments">
                                <span wire:loading.remove wire:target="submitDocuments">{{ $documentContinueLabel }} <i class="bi bi-arrow-right ms-1"></i></span>
                                <span wire:loading wire:target="submitDocuments">Submitting…</span>
                            </button>
                        @else
                            <button type="button" class="btn btn-primary rounded-pill"
                                wire:click="nextStep"
                                @disabled(! $canGoNext)>
                                Next <i class="bi bi-arrow-right ms-1"></i>
                            </button>
                        @endif
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>
