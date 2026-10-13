<?php

use App\Models\Sbo;
use App\Support\SboApplicationProgress;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $user = auth()->user();

        $query = Sbo::query()
            ->where('created_by', $user->id)
            ->orderBy('name');

        $applications = $query->get()
            ->map(fn (Sbo $sbo): array => [
                'sbo' => $sbo,
                'progress' => SboApplicationProgress::for($sbo),
            ]);

        return [
            'applications' => $applications,
            // Fire the celebration once if any of the user's SBOs is fully verified.
            'anyVerified' => $applications->contains(fn (array $a): bool => $a['sbo']->isFullyVerified()),
        ];
    }
};
?>
<div class="sbo-applications-remake">
    <x-ad-slot name="dashboard_top" />

    <x-ui.page-header
        title="Applications"
        description="Track each registration from submission to full verification."
        :breadcrumbs="[
            ['label' => 'SBO Workspace', 'href' => route('sbo.dashboard'), 'wireNavigate' => true],
            ['label' => 'Applications', 'current' => true],
        ]"
    >
        <x-slot:meta>
            <span><strong>{{ $applications->count() }}</strong> {{ $applications->count() === 1 ? 'application' : 'applications' }}</span>
            <span>Updates appear as the Registry team reviews your information.</span>
        </x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('sbo.dashboard') }}" class="btn btn-outline-secondary rounded-pill" wire:navigate>
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>SBO workspace
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    <section aria-labelledby="applications-list-title">
        <div class="sbo-section-heading">
            <div>
                <div class="sbo-section-heading__eyebrow">Registration journey</div>
                <h2 id="applications-list-title" class="sbo-section-heading__title">Your applications</h2>
                <p class="sbo-section-heading__copy">Open an application to review its status, complete the next task, or see what the Registry team is waiting for.</p>
            </div>
        </div>

        @forelse($applications as $application)
            @php
                $sbo = $application['sbo'];
                $progress = $application['progress'];
                $badge = $progress['badge'];
                $isVerified = $sbo->isFullyVerified();
                $hasPaid = $sbo->feesPaid();
                $ctaStep = $isVerified ? 'verified' : ($hasPaid ? 'documents' : 'fees');
                $ctaLabel = $isVerified ? 'View verification' : ($hasPaid ? 'Upload documents' : 'Continue payment');
                $statusTone = $isVerified ? 'success' : ($sbo->isDeclined() ? 'danger' : ($sbo->isInfoRequested() ? 'warning' : 'info'));
                $currentStep = collect($progress['steps'])->firstWhere('key', $progress['current_key']);
            @endphp

            <article class="sbo-application mb-3">
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
                    <span class="small text-muted">{{ $isVerified ? 'Your SBO is fully verified.' : ($currentStep['description'] ?? 'Continue your application.') }}</span>
                    <a href="{{ route('sbo.applications.progress', ['sbo' => $sbo, 'step' => $ctaStep]) }}"
                       class="btn btn-sm {{ $isVerified ? 'btn-outline-success' : 'btn-primary' }} rounded-pill"
                       wire:navigate>
                        {{ $ctaLabel }} <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                    </a>
                </footer>
            </article>
        @empty
            <div class="sbo-remake__empty">
                <h2 class="sbo-remake__next-title">No applications yet</h2>
                <p class="sbo-remake__next-description">When you start an SBO registration, its progress will appear here.</p>
                <a href="{{ route('support.index') }}" class="btn btn-outline-primary rounded-pill" wire:navigate>
                    Contact support <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                </a>
            </div>
        @endforelse
    </section>
</div>
