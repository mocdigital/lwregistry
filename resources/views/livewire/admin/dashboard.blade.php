<?php

use App\Models\ActivityLog;
use App\Models\Sbo;
use App\Models\User;
use App\Support\AdminPages;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Admin Dashboard – Loveworld Registry',
        'dataPage' => 'dashboard',
        'pageTitle' => 'Admin Dashboard'
    ]
)] class extends Component {

    public function mount()
    {
        $user = Auth::user();

        abort_unless($user?->hasAdminPage(AdminPages::DASHBOARD), 403);
    }

    public function with(): array
    {
        $totalUsers = User::count();

        // SBO and COB are two entity types on the same table. Legacy rows with
        // no entity_type are counted as SBO, the historical default.
        $sbos = fn () => Sbo::query()->where(fn ($q) => $q->where('entity_type', 'SBO')->orWhereNull('entity_type'));
        $cobs = fn () => Sbo::query()->where('entity_type', 'COB');

        $totalSbos = $sbos()->count();
        $approvedSbos = $sbos()->where('verification_status', 'approved')->count();
        $pendingSbos = $sbos()->where('verification_status', 'pending')->count();

        $totalCobs = $cobs()->count();
        $approvedCobs = $cobs()->where('verification_status', 'approved')->count();
        $pendingCobs = $cobs()->where('verification_status', 'pending')->count();

        $pendingPayments = Sbo::query()
            ->whereNotNull('payment_submitted_at')
            ->where(function ($query): void {
                $query->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', 'paid');
            })
            ->count();
        $infoRequested = Sbo::where('verification_status', 'info_requested')->count();

        $approvalRate = $totalSbos > 0
            ? round(($approvedSbos / $totalSbos) * 100)
            : 0;
        $cobApprovalRate = $totalCobs > 0
            ? round(($approvedCobs / $totalCobs) * 100)
            : 0;

        $topSboCategories = Sbo::query()
            ->selectRaw('category, COUNT(*) as sbo_count')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->groupBy('category')
            ->orderByDesc('sbo_count')
            ->limit(10)
            ->get();

        $recentActivities = ActivityLog::latest()
            ->limit(8)
            ->get();

        return [
            'totalUsers' => $totalUsers,
            'totalSbos' => $totalSbos,
            'approvedSbos' => $approvedSbos,
            'pendingSbos' => $pendingSbos,
            'totalCobs' => $totalCobs,
            'approvedCobs' => $approvedCobs,
            'pendingCobs' => $pendingCobs,
            'cobApprovalRate' => $cobApprovalRate,
            'pendingPayments' => $pendingPayments,
            'infoRequested' => $infoRequested,
            'approvalRate' => $approvalRate,
            'topSboCategories' => $topSboCategories,
            'recentActivities' => $recentActivities,
        ];
    }
};
?>
<div class="admin-workspace">
    <x-ui.page-header
        title="Admin overview"
        eyebrow="Registry operations"
        description="A focused view of the applications, payments, and applicant updates that need the team’s attention."
    >
        <x-slot:meta>
            <span><strong>{{ number_format($totalSbos) }}</strong> registered SBOs</span>
            <span>{{ $approvalRate }}% approved</span>
        </x-slot:meta>
        <x-slot:actions>
            @if(auth()->user()->hasAdminPage(AdminPages::ORGANIZATIONS))
                <a href="{{ route('admin.sbos.index') }}" class="btn btn-primary rounded-pill">
                    <i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Review applications
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    <section class="admin-workspace__queue ui-surface ui-queue-panel" aria-labelledby="admin-queue-title">
        <div class="ui-queue-panel__header">
            <div>
                <div class="admin-workspace__eyebrow">Today’s work</div>
                <h2 id="admin-queue-title" class="ui-queue-panel__title">Needs attention</h2>
                <p class="ui-queue-panel__copy">Start with the work that is waiting on the Registry team.</p>
            </div>
            <span class="ui-status ui-status--warning"><i class="bi bi-inbox" aria-hidden="true"></i> Work queue</span>
        </div>

        <div class="ui-queue-grid">
            @if(auth()->user()->hasAdminPage(AdminPages::ORGANIZATIONS))
                <a href="{{ route('admin.sbos.index') }}" class="ui-queue-item">
                    <span class="ui-queue-item__icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span>
                    <span class="ui-queue-item__body">
                        <span class="ui-queue-item__label d-block">Application approvals</span>
                        <span class="ui-queue-item__meta d-block">Review newly submitted SBO applications.</span>
                    </span>
                    <span class="ui-queue-item__count">{{ number_format($pendingSbos) }}</span>
                </a>
            @endif

            @if(auth()->user()->hasAdminPage(AdminPages::FEES))
                <a href="{{ route('admin.fees.index') }}" class="ui-queue-item">
                    <span class="ui-queue-item__icon" aria-hidden="true"><i class="bi bi-receipt"></i></span>
                    <span class="ui-queue-item__body">
                        <span class="ui-queue-item__label d-block">Payment reviews</span>
                        <span class="ui-queue-item__meta d-block">Confirm submitted registration payments.</span>
                    </span>
                    <span class="ui-queue-item__count">{{ number_format($pendingPayments) }}</span>
                </a>
            @endif

            @if(auth()->user()->hasAdminPage(AdminPages::APPROVAL_INFO_REQUESTS))
                <a href="{{ route('admin.approvals.info-requests') }}" class="ui-queue-item">
                    <span class="ui-queue-item__icon" aria-hidden="true"><i class="bi bi-chat-left-text"></i></span>
                    <span class="ui-queue-item__body">
                        <span class="ui-queue-item__label d-block">Information requests</span>
                        <span class="ui-queue-item__meta d-block">Track applications waiting for applicant updates.</span>
                    </span>
                    <span class="ui-queue-item__count">{{ number_format($infoRequested) }}</span>
                </a>
            @endif
        </div>
    </section>

    <section class="admin-stat-grid" aria-label="Registry totals">
        <article class="admin-stat">
            <div class="admin-stat__icon" aria-hidden="true"><i class="bi bi-people"></i></div>
            <div class="admin-stat__label">Total users</div>
            <div class="admin-stat__value">{{ number_format($totalUsers) }}</div>
            <div class="admin-stat__meta">Registered accounts</div>
        </article>
        <article class="admin-stat">
            <div class="admin-stat__icon" aria-hidden="true"><i class="bi bi-building"></i></div>
            <div class="admin-stat__label">Total SBOs</div>
            <div class="admin-stat__value">{{ number_format($totalSbos) }}</div>
            <div class="admin-stat__meta">Strategic Business Organizations</div>
        </article>
        <article class="admin-stat">
            <div class="admin-stat__icon admin-stat__icon--success" aria-hidden="true"><i class="bi bi-patch-check"></i></div>
            <div class="admin-stat__label">Approved SBOs</div>
            <div class="admin-stat__value">{{ number_format($approvedSbos) }}</div>
            <div class="admin-stat__meta">{{ $approvalRate }}% approved</div>
            <div class="admin-stat__track" role="progressbar" aria-label="SBO approval rate" aria-valuenow="{{ $approvalRate }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $approvalRate }}%"></span></div>
        </article>
        <article class="admin-stat">
            <div class="admin-stat__icon admin-stat__icon--warning" aria-hidden="true"><i class="bi bi-hourglass-split"></i></div>
            <div class="admin-stat__label">Pending SBOs</div>
            <div class="admin-stat__value">{{ number_format($pendingSbos) }}</div>
            <div class="admin-stat__meta">Awaiting review</div>
        </article>
        <article class="admin-stat">
            <div class="admin-stat__icon" aria-hidden="true"><i class="bi bi-shop"></i></div>
            <div class="admin-stat__label">Total COBs</div>
            <div class="admin-stat__value">{{ number_format($totalCobs) }}</div>
            <div class="admin-stat__meta">Citizen Owned Businesses</div>
        </article>
        <article class="admin-stat">
            <div class="admin-stat__icon admin-stat__icon--success" aria-hidden="true"><i class="bi bi-patch-check"></i></div>
            <div class="admin-stat__label">Approved COBs</div>
            <div class="admin-stat__value">{{ number_format($approvedCobs) }}</div>
            <div class="admin-stat__meta">{{ $cobApprovalRate }}% approved</div>
            <div class="admin-stat__track" role="progressbar" aria-label="COB approval rate" aria-valuenow="{{ $cobApprovalRate }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $cobApprovalRate }}%"></span></div>
        </article>
        <article class="admin-stat">
            <div class="admin-stat__icon admin-stat__icon--warning" aria-hidden="true"><i class="bi bi-hourglass-split"></i></div>
            <div class="admin-stat__label">Pending COBs</div>
            <div class="admin-stat__value">{{ number_format($pendingCobs) }}</div>
            <div class="admin-stat__meta">Awaiting review</div>
        </article>
    </section>

    <div class="admin-workspace__lower">
        <section class="admin-surface" aria-labelledby="admin-activity-title">
            <div class="admin-surface__header">
                <div>
                    <div class="admin-workspace__eyebrow">Audit trail</div>
                    <h2 id="admin-activity-title" class="admin-surface__title">Recent activity</h2>
                </div>
                <a href="{{ route('admin.activity.index') }}" class="btn btn-sm btn-outline-secondary rounded-pill">View all</a>
            </div>
            <ul class="admin-activity-list">
                @forelse($recentActivities as $activity)
                    <li class="admin-activity">
                        <span class="admin-activity__avatar" aria-hidden="true">{{ $activity->icon ?? 'NA' }}</span>
                        <span class="admin-activity__body">
                            <span class="admin-activity__title">{{ $activity->title }}</span>
                            <span class="admin-activity__description">{!! $activity->description !!}</span>
                            <span class="admin-activity__time">{{ $activity->created_at->diffForHumans() }}</span>
                        </span>
                        <span class="ui-status ui-status--{{ ($activity->status ?? 'info') === 'success' ? 'success' : (($activity->status ?? 'info') === 'error' ? 'danger' : 'info') }}">{{ ucfirst($activity->status ?? 'info') }}</span>
                    </li>
                @empty
                    <li class="admin-empty">No recent activity yet.</li>
                @endforelse
            </ul>
        </section>

        <section class="admin-surface" aria-labelledby="admin-category-title">
            <div class="admin-surface__header">
                <div>
                    <div class="admin-workspace__eyebrow">Directory mix</div>
                    <h2 id="admin-category-title" class="admin-surface__title">Top SBO categories</h2>
                </div>
            </div>
            @if($topSboCategories->count())
                <ol class="admin-category-list">
                    @foreach($topSboCategories as $category)
                        <li class="admin-category">
                            <span class="admin-category__name">{{ $category->category }}</span>
                            <span class="admin-category__count">{{ $category->sbo_count }}</span>
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="admin-empty">No categories available.</p>
            @endif
        </section>
    </div>
</div>
