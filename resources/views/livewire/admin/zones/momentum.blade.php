<?php

use App\Models\Sbo;
use App\Models\Zone;
use App\Support\AdminPages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.admin', [
    'title' => 'Zone Momentum – Admin Dashboard',
    'dataPage' => 'zone-momentum',
    'pageTitle' => 'Registration Momentum by Zone',
])] class extends Component {
    // Time window in days; 0 = all time.
    public int $window = 30;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasAdminPage(AdminPages::ZONE_MOMENTUM), 403);
    }

    /** SBOs created within the selected window (all time when window = 0). */
    private function scopedSbos()
    {
        return Sbo::query()->when(
            $this->window > 0,
            fn ($q) => $q->where('created_at', '>=', now()->subDays($this->window)),
        );
    }

    public function with(): array
    {
        // Per-zone registration counts + status breakdown + last signup.
        $rows = (clone $this->scopedSbos())
            ->whereNotNull('zone_id')
            ->select('zone_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN verification_status = 'approved' THEN 1 ELSE 0 END) as approved")
            ->selectRaw('SUM(CASE WHEN referred_via_code IS NOT NULL THEN 1 ELSE 0 END) as via_link')
            ->selectRaw('MAX(created_at) as last_signup')
            ->groupBy('zone_id')
            ->orderByDesc('total')
            ->get();

        $zoneNames = Zone::query()->pluck('name', 'id');

        $ranked = $rows->map(fn ($r): array => [
            'zone' => $zoneNames[$r->zone_id] ?? 'Unknown',
            'total' => (int) $r->total,
            'pending' => (int) $r->pending,
            'approved' => (int) $r->approved,
            'via_link' => (int) $r->via_link,
            'last_signup' => $r->last_signup ? Carbon::parse($r->last_signup)->format('d M Y') : '—',
        ]);

        // Headline numbers.
        $viaLinkTotal = (clone $this->scopedSbos())->whereNotNull('referred_via_code')->count();
        $thisWeek = Sbo::query()->where('created_at', '>=', now()->subDays(7))->count();

        // Bar chart: top 10 zones by registrations in the window.
        $top = $ranked->take(10);

        // Line chart: sign-ups per day across the window (default 30 days).
        $days = $this->window > 0 ? $this->window : 30;
        $perDay = (clone $this->scopedSbos())
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day');

        // Build a continuous date axis so gaps show as zero.
        $trendLabels = [];
        $trendValues = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $trendLabels[] = now()->subDays($i)->format('d M');
            $trendValues[] = (int) ($perDay[$date] ?? 0);
        }

        return [
            'ranked' => $ranked,
            'viaLinkTotal' => $viaLinkTotal,
            'thisWeek' => $thisWeek,
            'topZone' => $ranked->first()['zone'] ?? '—',
            'chart' => [
                'barLabels' => $top->pluck('zone')->all(),
                'barValues' => $top->pluck('total')->all(),
                'trendLabels' => $trendLabels,
                'trendValues' => $trendValues,
            ],
        ];
    }
}; ?>

<div>
    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-4">
        <p class="text-muted small mb-0" style="max-width: 620px;">
            Registrations attributed to each zone. "Via link" counts sign-ups that came
            through a zone referral link; the rest chose their zone on the form. Manage links
            in <a href="{{ route('admin.zones.referrals.index') }}" wire:navigate>Zone Referrals</a>.
        </p>
        <select wire:model.live="window" class="form-select form-select-sm" style="width: auto;">
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
            <option value="90">Last 90 days</option>
            <option value="0">All time</option>
        </select>
    </div>

    {{-- Stat cards --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Registrations via links</div>
                <div class="h3 mb-0 fw-bold">{{ number_format($viaLinkTotal) }}</div>
                <div class="small text-muted-soft">In selected window</div>
            </div></div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">New this week</div>
                <div class="h3 mb-0 fw-bold">{{ number_format($thisWeek) }}</div>
                <div class="small text-muted-soft">All registrations, last 7 days</div>
            </div></div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Top zone</div>
                <div class="h5 mb-0 fw-bold">{{ $topZone }}</div>
                <div class="small text-muted-soft">Most registrations in window</div>
            </div></div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted">Active zones</div>
                <div class="h3 mb-0 fw-bold">{{ number_format($ranked->count()) }}</div>
                <div class="small text-muted-soft">Zones with registrations</div>
            </div></div>
        </div>
    </div>

    {{-- Charts --}}
    <div class="row g-3 mb-4" wire:ignore
         x-data="zoneMomentumCharts(@js($chart))" x-init="render()">
        <div class="col-12 col-lg-6">
            <div class="card h-100"><div class="card-body">
                <h6 class="fw-bold mb-3">Top zones by registrations</h6>
                <div x-ref="bar"></div>
            </div></div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card h-100"><div class="card-body">
                <h6 class="fw-bold mb-3">Sign-ups over time</h6>
                <div x-ref="trend"></div>
            </div></div>
        </div>
    </div>

    {{-- Ranked table --}}
    <div class="card">
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>#</th>
                            <th>Zone</th>
                            <th class="text-center">Registrations</th>
                            <th class="text-center">Via link</th>
                            <th class="text-center">Pending</th>
                            <th class="text-center">Approved</th>
                            <th>Last signup</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($ranked as $i => $row)
                            <tr>
                                <td class="text-muted">{{ $i + 1 }}</td>
                                <td class="fw-semibold">{{ $row['zone'] }}</td>
                                <td class="text-center fw-semibold">{{ number_format($row['total']) }}</td>
                                <td class="text-center">{{ number_format($row['via_link']) }}</td>
                                <td class="text-center">{{ number_format($row['pending']) }}</td>
                                <td class="text-center">{{ number_format($row['approved']) }}</td>
                                <td class="small text-muted-soft">{{ $row['last_signup'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No registrations in this window yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        function zoneMomentumCharts(data) {
            return {
                bar: null,
                trend: null,
                render() {
                    // Clean up any previous instances (Livewire re-renders).
                    if (this.bar) { this.bar.destroy(); }
                    if (this.trend) { this.trend.destroy(); }

                    this.bar = new ApexCharts(this.$refs.bar, {
                        chart: { type: 'bar', height: 300, toolbar: { show: false } },
                        series: [{ name: 'Registrations', data: data.barValues }],
                        xaxis: { categories: data.barLabels },
                        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                        dataLabels: { enabled: false },
                        colors: ['#198754'],
                    });
                    this.bar.render();

                    this.trend = new ApexCharts(this.$refs.trend, {
                        chart: { type: 'area', height: 300, toolbar: { show: false } },
                        series: [{ name: 'Sign-ups', data: data.trendValues }],
                        xaxis: { categories: data.trendLabels, tickAmount: 6 },
                        stroke: { curve: 'smooth', width: 2 },
                        dataLabels: { enabled: false },
                        colors: ['#0d6efd'],
                    });
                    this.trend.render();
                },
            };
        }
    </script>
@endpush
