<?php

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\Sbo;
use App\Support\SboApplicationProgress;
use Livewire\Volt\Component;

new class() extends Component
{
    public function resubmit(int $sboId): void
    {
        // Only the owner can resubmit, and only when info was requested.
        $sbo = Sbo::where('id', $sboId)
            ->where('created_by', auth()->id())
            ->where('verification_status', 'info_requested')
            ->first();

        if ( ! $sbo) {
            return;
        }

        $sbo->update([
            'verification_status' => 'pending',
            'resubmitted_at' => now(),
        ]);

        App\Models\ActivityLog::create([
            'type' => 'sbo_resubmitted',
            'title' => $sbo->name,
            'description' => 'Applicant resubmitted <strong>' . $sbo->name . '</strong> after an info request.',
            'status' => 'pending',
            'user_id' => auth()->id(),
        ]);

        session()->flash('success', 'Your SBO has been resubmitted for review.');
    }

    public function with(): array
    {
        $userId = auth()->id();

        $sbos = Sbo::where('created_by', $userId)->orderBy('name')->get();
        $sboIds = $sbos->pluck('id');

        // Precompute each SBO's application progress here (keyed by id) so the
        // template doesn't call into the support class directly.
        $progressBySbo = $sbos->mapWithKeys(
            fn (Sbo $s): array => [$s->id => SboApplicationProgress::for($s)],
        );

        $rfqQuery = PurchaseOrder::whereIn('sbo_id', $sboIds);

        return [
            'sbos' => $sbos,
            'progressBySbo' => $progressBySbo,
            'hasVerifiedSbo' => $sbos->contains(fn (Sbo $s) => $s->isFullyVerified()),
            'totalRfqs' => (clone $rfqQuery)->count(),
            'openRfqs' => (clone $rfqQuery)->where('status', 'open')->count(),
            'awardedRfqs' => (clone $rfqQuery)->where('status', 'awarded')->count(),
            'totalBids' => PurchaseOrderBid::whereIn('purchase_order_id', (clone $rfqQuery)->pluck('id'))->count(),
        ];
    }
};
?>
<div class="sbo-remake">
    <x-ad-slot name="dashboard_top" />

    <x-ui.page-header
        title="SBO workspace"
        eyebrow="Business account"
        :description="'Welcome back, ' . auth()->user()->firstname . '. Keep your registrations moving and see exactly what needs attention.'"
        :back-href="route('dashboard')"
        back-label="Business dashboard"
        wire:navigate
    >
        <x-slot:meta>
            <span><strong>{{ $sbos->count() }}</strong> {{ \Illuminate\Support\Str::plural('SBO', $sbos->count()) }}</span>
            <span>{{ $hasVerifiedSbo ? 'Procurement access available' : 'Validation in progress' }}</span>
        </x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('sbo.applications.index') }}" class="btn btn-outline-secondary rounded-pill" wire:navigate>
                <i class="bi bi-list-check me-1" aria-hidden="true"></i>View applications
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    @php
        $nextSbo = $sbos->first(fn (Sbo $sbo): bool => ! $sbo->isFullyVerified() && ! $sbo->isDeclined());
        $nextProgress = $nextSbo ? $progressBySbo[$nextSbo->id] : null;
        $nextStep = $nextSbo
            ? ($nextSbo->feesPaid() ? 'documents' : 'fees')
            : null;
        $nextLabel = $nextSbo
            ? ($nextSbo->feesPaid() ? 'Upload required documents' : 'Complete registration payment')
            : null;
    @endphp

    @if($nextSbo)
        <x-ui.sbo-context :sbo="$nextSbo" :options="$sbos" :step="$nextStep" />
    @endif

    @if($nextSbo && $nextProgress)
        <section class="sbo-remake__next" aria-labelledby="sbo-next-action-title">
            <div class="sbo-remake__next-copy">
                <div class="sbo-remake__eyebrow">Next best action</div>
                <h2 id="sbo-next-action-title" class="sbo-remake__next-title">{{ $nextLabel }}</h2>
                <p class="sbo-remake__next-description">
                    {{ $nextSbo->name }} is {{ $nextProgress['percent'] }}% complete.
                    {{ $nextSbo->feesPaid()
                        ? 'Upload the remaining compliance documents so the Registry team can continue verification.'
                        : 'Complete the registration fee step to unlock document review.' }}
                </p>
            </div>
            <a href="{{ route('sbo.applications.progress', ['sbo' => $nextSbo, 'step' => $nextStep]) }}"
               class="btn btn-primary rounded-pill sbo-remake__next-action"
               wire:navigate>
                {{ $nextLabel }} <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
            </a>
        </section>
    @elseif($sbos->isEmpty())
        <section class="sbo-remake__empty" aria-labelledby="sbo-empty-title">
            <h2 id="sbo-empty-title" class="sbo-remake__next-title">No SBO applications yet</h2>
            <p class="sbo-remake__next-description">Your SBO applications will appear here as soon as one is created.</p>
            <a href="{{ route('support.index') }}" class="btn btn-outline-primary rounded-pill" wire:navigate>
                Contact support <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
            </a>
        </section>
    @endif

    <div class="sbo-overview-grid">
        <main class="sbo-overview-main">
            <section aria-labelledby="sbo-applications-title">
                <div class="sbo-section-heading">
                    <div>
                        <div class="sbo-section-heading__eyebrow">Your registrations</div>
                        <h2 id="sbo-applications-title" class="sbo-section-heading__title">Application progress</h2>
                        <p class="sbo-section-heading__copy">Each application shows its current status, required action, and the next step toward verification.</p>
                    </div>
                    <a href="{{ route('sbo.applications.index') }}" class="btn btn-sm btn-outline-secondary rounded-pill" wire:navigate>View all</a>
                </div>

                @if($sbos->isNotEmpty())
                    <div class="sbo-application-list">
                        @foreach($sbos as $sbo)
                            @php
                                $progress = $progressBySbo[$sbo->id];
                                $badge = $progress['badge'];
                                $isVerified = $sbo->isFullyVerified();
                                $hasPaid = $sbo->feesPaid();
                                $ctaStep = $isVerified ? 'verified' : ($hasPaid ? 'documents' : 'fees');
                                $ctaLabel = $isVerified ? 'View verification' : ($hasPaid ? 'Upload documents' : 'Continue payment');
                                $statusTone = $isVerified ? 'success' : ($sbo->isDeclined() ? 'danger' : ($sbo->isInfoRequested() ? 'warning' : 'info'));
                                $currentStep = collect($progress['steps'])->firstWhere('key', $progress['current_key']);
                            @endphp

                            <article class="sbo-application">
                                <header class="sbo-application__header">
                                    <div>
                                        <h3 class="sbo-application__name">{{ $sbo->name }}</h3>
                                        <div class="sbo-application__meta">
                                            Application ID: <strong>{{ $sbo->application_id ?? '—' }}</strong>
                                            @if($sbo->moc_registration_number)
                                                <span aria-hidden="true">·</span> MOC #: <strong>{{ $sbo->moc_registration_number }}</strong>
                                            @endif
                                        </div>
                                    </div>
                                    <span class="ui-status ui-status--{{ $statusTone }}">{{ $badge['label'] }}</span>
                                </header>

                                @if($sbo->isInfoRequested())
                                    <div class="sbo-application__callout">
                                        <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                                        <span><strong>Action needed:</strong> {{ $sbo->info_request ?: 'Review the requested information and resubmit your application.' }}</span>
                                    </div>
                                @elseif($sbo->isDeclined())
                                    <div class="sbo-application__callout sbo-application__callout--danger">
                                        <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
                                        <span><strong>Application declined:</strong> {{ $sbo->decline_reason ?: 'Contact support if you need clarification.' }}</span>
                                    </div>
                                @endif

                                <div class="sbo-application__progress">
                                    <div class="sbo-progress-track" role="progressbar" aria-label="{{ $sbo->name }} application progress" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                                        <div class="sbo-progress-track__fill" style="width: {{ $progress['percent'] }}%;"></div>
                                    </div>
                                    <span class="sbo-application__percent">{{ $progress['percent'] }}%</span>
                                </div>

                                <ol class="sbo-step-list" aria-label="{{ $sbo->name }} application stages">
                                    @foreach($progress['steps'] as $step)
                                        <li class="sbo-step-list__item {{ $step['done'] ? 'is-complete' : ($step['key'] === $progress['current_key'] ? 'is-current' : '') }}">
                                            <i class="sbo-step-list__icon bi {{ $step['done'] ? 'bi-check-circle-fill' : ($step['key'] === $progress['current_key'] ? 'bi-arrow-right-circle-fill' : 'bi-circle') }}" aria-hidden="true"></i>
                                            <span>{{ $step['label'] }}</span>
                                        </li>
                                    @endforeach
                                </ol>

                                <footer class="sbo-application__footer">
                                    <span class="small text-muted">
                                        {{ $isVerified ? 'Your SBO is fully verified.' : ($currentStep['description'] ?? 'Continue your application.') }}
                                    </span>
                                    <a href="{{ route('sbo.applications.progress', ['sbo' => $sbo, 'step' => $ctaStep]) }}"
                                       class="btn btn-sm {{ $isVerified ? 'btn-outline-success' : 'btn-primary' }} rounded-pill"
                                       wire:navigate>
                                        {{ $ctaLabel }} <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                                    </a>
                                </footer>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            @php
                $rfqProgressSbo = $sbos->first(fn (Sbo $sbo): bool => ! $sbo->isFullyVerified()) ?? $sbos->first();
                $rfqCtaUrl = $hasVerifiedSbo
                    ? route('sbo.rfqs.index', ['create' => 1])
                    : ($rfqProgressSbo ? route('sbo.applications.progress', $rfqProgressSbo) : route('sbo.applications.index'));
            @endphp

            <section class="sbo-procurement-panel" aria-labelledby="sbo-procurement-title">
                <div class="sbo-procurement-panel__header">
                    <div>
                        <div class="sbo-section-heading__eyebrow">Next workspace</div>
                        <h2 id="sbo-procurement-title" class="sbo-procurement-panel__title">Procurement and network access</h2>
                        <p class="sbo-procurement-panel__copy">
                            {{ $hasVerifiedSbo
                                ? 'Create requests, connect with verified SBOs, and manage bids from one workspace.'
                                : 'Complete registration approval, documents, and payment to unlock procurement and network tools.' }}
                        </p>
                    </div>
                    <span class="ui-status ui-status--{{ $hasVerifiedSbo ? 'success' : 'warning' }}">
                        <i class="bi {{ $hasVerifiedSbo ? 'bi-unlock' : 'bi-lock' }}" aria-hidden="true"></i>
                        {{ $hasVerifiedSbo ? 'Available' : 'Locked' }}
                    </span>
                </div>
                <div class="sbo-procurement-panel__actions">
                    <a href="{{ $rfqCtaUrl }}" class="btn {{ $hasVerifiedSbo ? 'btn-primary' : 'btn-outline-primary' }} rounded-pill" wire:navigate>
                        <i class="bi {{ $hasVerifiedSbo ? 'bi-plus-circle' : 'bi-list-check' }} me-1" aria-hidden="true"></i>
                        {{ $hasVerifiedSbo ? 'Post a procurement request' : 'Review validation steps' }}
                    </a>
                    @if($hasVerifiedSbo)
                        <a href="{{ route('sbo.network.index') }}" class="btn btn-outline-primary rounded-pill" wire:navigate>
                            <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Open SBO network
                        </a>
                    @endif
                </div>
            </section>
        </main>

        <aside class="sbo-overview-aside" aria-label="Workspace summary">
            <section class="sbo-workspace-summary">
                <h2 class="sbo-workspace-summary__title">Workspace summary</h2>
                <p class="sbo-workspace-summary__copy">A quick view of your activity across the Registry.</p>
                <dl class="sbo-metric-list">
                    <div class="sbo-metric-list__row">
                        <dt class="sbo-metric-list__label">Total requests</dt>
                        <dd class="sbo-metric-list__value mb-0">{{ $totalRfqs }}</dd>
                    </div>
                    <div class="sbo-metric-list__row">
                        <dt class="sbo-metric-list__label">Open requests</dt>
                        <dd class="sbo-metric-list__value mb-0">{{ $openRfqs }}</dd>
                    </div>
                    <div class="sbo-metric-list__row">
                        <dt class="sbo-metric-list__label">Awarded requests</dt>
                        <dd class="sbo-metric-list__value mb-0">{{ $awardedRfqs }}</dd>
                    </div>
                    <div class="sbo-metric-list__row">
                        <dt class="sbo-metric-list__label">Bids received</dt>
                        <dd class="sbo-metric-list__value mb-0">{{ $totalBids }}</dd>
                    </div>
                </dl>
            </section>

            <section class="sbo-help-panel">
                <h2 class="sbo-help-panel__title">Need help?</h2>
                <p class="sbo-help-panel__copy">The Registry team can help with application steps, payments, documents, and account access.</p>
                <a href="{{ route('support.index') }}" class="sbo-help-panel__link" wire:navigate>
                    Contact support <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                </a>
            </section>
        </aside>
    </div>
</div>
