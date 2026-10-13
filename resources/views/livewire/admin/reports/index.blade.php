<?php

use App\Models\Report;
use App\Models\Sbo;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Reports – Admin Dashboard',
        'dataPage' => 'reports',
        'pageTitle' => 'Reports',
    ]
)] class extends Component {

    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public int $sbo_id;

    public string $title = '';
    public string $description = '';
    public ?string $period_start = null;
    public ?string $period_end = null;
    
    public ?Report $viewingReport = null;

    public function viewReport(int $reportId): void
    {
        $query = Report::with('sbo');

        if (! auth()->user()->hasPermission(\App\Support\AdminCapabilities::MANAGE_REPORTS)) {
            $query->where('admin_id', auth()->id());
        }

        $this->viewingReport = $query->findOrFail($reportId);

        $this->dispatch('open-view-report-modal');
    }

    /**
     * Metrics stored as JSON
     */
    public array $metrics = [
        'revenue_growth' => null,
        'customer_acquisition_rate' => null,
        'customer_retention_rate' => null,
        'market_share' => null,
        'sales_growth_product' => null,
        'sales_growth_region' => null,
        'sales_growth_channel' => null,

        'gross_profit_margin' => null,
        'operating_profit_margin' => null,
        'net_profit_margin' => null,
        'roi' => null,
        'roe' => null,

        'customer_ltv' => null,
        'satisfaction_score' => null,
        'net_promoter_score' => null,
        'customer_churn_rate' => null,

        'cash_flow_health' => null,
        'accounts_receivable_turnover' => null,
        'accounts_payable_turnover' => null,
        'debt_to_equity_ratio' => null,
        'current_ratio' => null,
    ];

    protected function rules(): array
    {
        return [
            'sbo_id' => 'required|exists:sbos,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'metrics' => 'array',
        ];
    }

    public function createReport(): void
    {
        $this->validate();

        Report::create([
            'sbo_id' => $this->sbo_id,
            'admin_id' => Auth::id(),
            'title' => $this->title,
            'description' => $this->description,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'metrics' => $this->metrics,
        ]);

        \App\Support\Audit::log('report_created', $this->title, 'Report created', 'created');

        $this->reset([
            'title',
            'description',
            'period_start',
            'period_end',
            'metrics',
        ]);

        $this->dispatch('close-add-report-modal');

        session()->flash('success', 'Report submitted successfully.');
    }


    public function with(): array
    {
        $user = auth()->user();

        $query = Report::with(['sbo', 'admin']);

        // 🔒 Scope reports for admins without the reports capability
        if (! $user->hasPermission(\App\Support\AdminCapabilities::MANAGE_REPORTS)) {
            $query->where('admin_id', $user->id);
        }

        $reports = $query->latest()->paginate(10);

        $totalReports = (clone $query)->count();

        // SBO dropdown should also be scoped
        $sboQuery = Sbo::orderBy('name');

        if (! $user->hasPermission(\App\Support\AdminCapabilities::MANAGE_REPORTS)) {
            $sboQuery->where('created_by', $user->id);
        }

        return [
            'reports' => $reports,
            'totalReports' => $totalReports,
            'sbos' => $sboQuery->get(),
        ];
    }

};
?>
<div>


    <style>

        /* Reports page styling */
        [data-page="reports"] .card {
        border-radius: 14px;
        border: none;
        box-shadow: 0 6px 24px rgba(15, 23, 42, 0.06);
        }

        [data-page="reports"] .table tbody tr:hover {
        background: #f7f9ff;
        }

        /* Metrics layout inside modal */
        .report-metrics-grid {
        display: grid;
        gap: 1.5rem;
        }

        .report-metric-section {
        padding: 1rem;
        border-radius: 12px;
        background: #f9fafb;
        border: 1px solid #e2e8f0;
        }

        /* Metric badges (section icons) */
        .metric-badge {
        width: 32px;
        height: 32px;
        border-radius: 999px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        color: #fff;
        }

        .metric-badge-growth {
        background: linear-gradient(135deg, #0f766e, #22c55e);
        }

        .metric-badge-profit {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .metric-badge-customer {
        background: linear-gradient(135deg, #2563eb, #60a5fa);
        }

        .metric-badge-financial {
        background: linear-gradient(135deg, #0b2545, #1f2933);
        }

        /* Individual metric card */
        .report-metric-card {
        border-radius: 10px;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        padding: 0.55rem 0.7rem;
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
        }

        .report-metric-label {
        font-size: 0.75rem;
        color: #6b7280;
        }

        .report-metric-value {
        font-size: 1rem;
        font-weight: 600;
        }

        .report-metric-tag {
        font-size: 0.7rem;
        color: #9ca3af;
        }

        /* Responsive */
        @media (max-width: 767.98px) {
        .report-metric-section {
            padding: 0.75rem;
        }
        }


    </style>

    <section class="mb-5">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h5 mb-1">Reports</h2>
                <p class="text-muted-soft small mb-0">
                    Performance reports submitted for organizations (SBOs).
                </p>
            </div>

            <a
                href="#"
                class="btn btn-sm btn-outline-primary rounded-pill"
                data-bs-toggle="modal"
                data-bs-target="#addReportModal"
            >
                <i class="bi bi-plus-circle me-1"></i>Add report
            </a>

        </div>

        <!-- Stats -->
        <div class="row g-3 g-md-4 mb-4">
            <div class="col-12 col-md-4">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="small text-muted-soft">Total reports</div>
                        <div class="h4 mb-0">{{ $totalReports }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reports table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Report title</th>
                                <th>Organization</th>
                                <th>Reporting period</th>
                                <th>Uploaded by</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse($reports as $report)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $report->title }}</div>
                                    </td>

                                    <td>
                                        <div class="fw-semibold">{{ $report->sbo->name }}</div>
                                        <div class="small text-muted-soft">
                                            {{ $report->sbo->region }}
                                        </div>
                                    </td>

                                    <td>
                                        {{ $report->period_start->format('d M Y') }}
                                        –
                                        {{ $report->period_end->format('d M Y') }}
                                    </td>

                                    <td>
                                        {{ $report->admin->firstname ?? 'Admin' }}
                                        {{ $report->admin->lastname ?? '' }}
                                    </td>

                                    <td>
                                        {{ $report->created_at->format('d M Y') }}
                                    </td>

                                    <td class="text-end">
                                        <button
                                            class="btn btn-sm btn-outline-primary rounded-pill"
                                            wire:click="viewReport({{ $report->id }})"
                                            data-bs-toggle="modal"
                                            data-bs-target="#viewReportModal"
                                             >
                                            View
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted-soft py-4">
                                        No reports found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($reports->hasPages())
                    <div class="card-footer bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="small text-muted-soft">
                                Showing {{ $reports->firstItem() }}–{{ $reports->lastItem() }}
                                of {{ $reports->total() }} reports
                            </div>

                            {{ $reports->links() }}
                        </div>
                    </div>
                @endif
            </div>
        </div>

    </section>

    <!-- ADD REPORT MODAL -->
    <div
    class="modal fade"
    id="addReportModal"
    tabindex="-1"
    aria-labelledby="addReportModalLabel"
    aria-hidden="true"
    wire:ignore.self
    >
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">

        <form wire:submit.prevent="createReport">

            <!-- HEADER -->
            <div class="modal-header">
            <div>
                <h5 class="modal-title" id="addReportModalLabel">Add report</h5>
                <div class="small text-muted-soft">
                Capture period, summary and key metrics for this report.
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- BODY -->
            <div class="modal-body">

            <!-- BASIC INFO -->
            <div class="row g-3 mb-3">

                <div class="col-12 col-md-3">
                <label class="form-label">Start Date</label>
                <input
                    type="date"
                    class="form-control"
                    wire:model.defer="period_start"
                >
                @error('period_start') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-12 col-md-3">
                <label class="form-label">End Date</label>
                <input
                    type="date"
                    class="form-control"
                    wire:model.defer="period_end"
                >
                @error('period_end') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-12 col-md-6">
                <label class="form-label">Title</label>
                <input
                    type="text"
                    class="form-control"
                    placeholder="e.g. Q1 Performance Review"
                    wire:model.defer="title"
                >
                @error('title') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-12">
                <label class="form-label">Description</label>
                <textarea
                    class="form-control"
                    rows="3"
                    wire:model.defer="description"
                ></textarea>
                @error('description') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-12 col-md-6">
                <label class="form-label">Organization (SBO)</label>
                <select class="form-select" wire:model.defer="sbo_id">
                    <option value="">Select organization…</option>
                    @foreach($sbos as $sbo)
                    <option value="{{ $sbo->id }}">{{ $sbo->name }}</option>
                    @endforeach
                </select>
                @error('sbo_id') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

            </div>

            <!-- METRICS GRID -->
            <div class="report-metrics-grid">

                <!-- GROWTH METRICS -->
                <section class="report-metric-section">
                <h6 class="mb-2">Growth metrics (%)</h6>
                <div class="row g-2">
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Revenue growth"
                        wire:model.defer="metrics.revenue_growth">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Customer acquisition"
                        wire:model.defer="metrics.customer_acquisition_rate">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Customer retention"
                        wire:model.defer="metrics.customer_retention_rate">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Market share"
                        wire:model.defer="metrics.market_share">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Sales growth (product)"
                        wire:model.defer="metrics.sales_growth_product">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Sales growth (region)"
                        wire:model.defer="metrics.sales_growth_region">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Sales growth (channel)"
                        wire:model.defer="metrics.sales_growth_channel">
                    </div>
                </div>
                </section>

                <!-- PROFITABILITY -->
                <section class="report-metric-section mt-3">
                <h6 class="mb-2">Profitability metrics (%)</h6>
                <div class="row g-2">
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Gross profit"
                        wire:model.defer="metrics.gross_profit_margin">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Operating profit"
                        wire:model.defer="metrics.operating_profit_margin">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Net profit"
                        wire:model.defer="metrics.net_profit_margin">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="ROI"
                        wire:model.defer="metrics.roi">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="ROE"
                        wire:model.defer="metrics.roe">
                    </div>
                </div>
                </section>

                <!-- CUSTOMER -->
                <section class="report-metric-section mt-3">
                <h6 class="mb-2">Customer metrics</h6>
                <div class="row g-2">
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Satisfaction score"
                        wire:model.defer="metrics.satisfaction_score">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Net promoter score"
                        wire:model.defer="metrics.net_promoter_score">
                    </div>
                    <div class="col-md-4">
                    <input type="number" class="form-control form-control-sm" placeholder="Customer churn rate"
                        wire:model.defer="metrics.customer_churn_rate">
                    </div>
                </div>
                </section>

                <!-- FINANCIAL -->
                <section class="report-metric-section mt-3">
                <h6 class="mb-2">Financial metrics</h6>
                <div class="row g-2">
                    <div class="col-md-4">
                    <input type="text" class="form-control form-control-sm" placeholder="Cash flow health"
                        wire:model.defer="metrics.cash_flow_health">
                    </div>
                    <div class="col-md-4">
                    <input type="text" class="form-control form-control-sm" placeholder="AR turnover"
                        wire:model.defer="metrics.accounts_receivable_turnover">
                    </div>
                    <div class="col-md-4">
                    <input type="text" class="form-control form-control-sm" placeholder="AP turnover"
                        wire:model.defer="metrics.accounts_payable_turnover">
                    </div>
                    <div class="col-md-4">
                    <input type="text" class="form-control form-control-sm" placeholder="Debt to equity"
                        wire:model.defer="metrics.debt_to_equity_ratio">
                    </div>
                    <div class="col-md-4">
                    <input type="text" class="form-control form-control-sm" placeholder="Current ratio"
                        wire:model.defer="metrics.current_ratio">
                    </div>
                </div>
                </section>

            </div>
            </div>

            <!-- FOOTER -->
            <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">
                Cancel
            </button>
            <button type="submit" class="btn btn-primary rounded-pill" wire:loading.attr="disabled">
                Submit Report
            </button>
            </div>

        </form>
        </div>
    </div>
    </div>

    <!-- VIEW REPORT MODAL -->
    <div
        class="modal fade"
        id="viewReportModal"
        tabindex="-1"
        aria-labelledby="viewReportModalLabel"
        aria-hidden="true"
        wire:ignore.self
    >
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">

        <div class="modal-header">
            <div>
            <h5 class="modal-title" id="viewReportModalLabel">
                {{ $viewingReport?->title ?? 'Report details' }}
            </h5>
            <div class="small text-muted-soft">
                @if($viewingReport)
                {{ $viewingReport->sbo->name ?? '—' }}
                ·
                {{ optional($viewingReport->period_start)->format('d M Y') }}
                –
                {{ optional($viewingReport->period_end)->format('d M Y') }}
                @else
                —
                @endif
            </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">

            <!-- SUMMARY -->
            <div class="mb-3">
            <div class="small text-muted-soft mb-1">Summary</div>
            <p class="small mb-0">
                {{ $viewingReport->description ?? '—' }}
            </p>
            </div>

            <!-- METRICS -->
            @php
            $metrics = $viewingReport->metrics ?? [];
            @endphp

            <div class="report-metrics-grid">

            <!-- Growth -->
            <section class="report-metric-section">
                <x-report-metric-section
                title="Growth metrics"
                subtitle="All values in %"
                icon="graph-up-arrow"
                :metrics="[
                    'Revenue growth' => $metrics['revenue_growth'] ?? null,
                    'Customer acquisition rate' => $metrics['customer_acquisition_rate'] ?? null,
                    'Customer retention rate' => $metrics['customer_retention_rate'] ?? null,
                    'Market share' => $metrics['market_share'] ?? null,
                    'Sales growth (product)' => $metrics['sales_growth_product'] ?? null,
                    'Sales growth (region)' => $metrics['sales_growth_region'] ?? null,
                    'Sales growth (channel)' => $metrics['sales_growth_channel'] ?? null,
                ]"
                />
            </section>

            <!-- Profitability -->
            <section class="report-metric-section">
                <x-report-metric-section
                title="Profitability metrics"
                subtitle="All values in %"
                icon="cash-coin"
                :metrics="[
                    'Gross profit margin' => $metrics['gross_profit_margin'] ?? null,
                    'Net profit margin' => $metrics['net_profit_margin'] ?? null,
                    'Debt to equity ratio' => $metrics['debt_to_equity_ratio'] ?? null,
                ]"
                />
            </section>

            <!-- Customer -->
            <section class="report-metric-section">
                <x-report-metric-section
                title="Customer metrics"
                subtitle="Indexes & scores"
                icon="emoji-smile"
                :metrics="[
                    'Satisfaction score' => $metrics['satisfaction_score'] ?? null,
                    'Net promoter score' => $metrics['net_promoter_score'] ?? null,
                    'Customer churn rate' => $metrics['customer_churn_rate'] ?? null,
                ]"
                />
            </section>

            <!-- Financial -->
            <section class="report-metric-section">
                <x-report-metric-section
                title="Financial metrics"
                subtitle="Ratios & health indicators"
                icon="bank"
                :metrics="[
                    'Cash flow health' => $metrics['cash_flow_health'] ?? null,
                    'Accounts receivable turnover' => $metrics['accounts_receivable_turnover'] ?? null,
                    'Accounts payable turnover' => $metrics['accounts_payable_turnover'] ?? null,
                    'Current ratio' => $metrics['current_ratio'] ?? null,
                ]"
                />
            </section>

            </div>
        </div>

        <div class="modal-footer d-flex justify-content-between align-items-center">
            <button
            class="btn btn-outline-secondary rounded-pill"
            data-bs-dismiss="modal"
            >
            Close
            </button>

            <button class="btn btn-sm btn-outline-primary rounded-pill">
            <i class="bi bi-download me-1"></i> Download report
            </button>
        </div>

        </div>
    </div>
    </div>


</div>
