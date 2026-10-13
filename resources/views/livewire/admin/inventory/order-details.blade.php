<?php

use App\Enums\InventoryOrderStatus;
use App\Models\InventoryOrder;
use App\Models\User;
use App\Support\InventoryCsvExporter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Order Details – Admin Dashboard', 'dataPage' => 'inventory-order-details', 'pageTitle' => 'Order Details'])] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    // Super-admin only: filter to a specific admin's orders.
    public ?int $adminFilter = null;

    public ?int $expandedId = null;

    protected string $paginationTheme = 'bootstrap';

    protected $queryString = [
        'search' => ['except' => ''],
        'statusFilter' => ['except' => ''],
        'adminFilter' => ['except' => null],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAdminFilter(): void
    {
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    /**
     * Orders this admin may see: their own; super-admins see everyone's and may
     * narrow to a specific admin via $adminFilter.
     */
    private function filteredQuery()
    {
        $user = auth()->user();

        $query = InventoryOrder::store()
            ->visibleTo($user)
            ->with(['creator', 'paidBy', 'items'])
            ->latest();

        if ($user->isSuperAdmin() && $this->adminFilter) {
            $query->where('created_by', $this->adminFilter);
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($search = mb_trim($this->search)) {
            $query->where(function ($q) use ($search): void {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    public function exportCsv(InventoryCsvExporter $exporter)
    {
        return $exporter->orders($this->filteredQuery()->reorder()->latest());
    }

    public function with(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();

        return [
            'orders' => $this->filteredQuery()->paginate(20),
            'statuses' => InventoryOrderStatus::cases(),
            'isSuperAdmin' => $isSuperAdmin,
            // For the super-admin admin filter: admins who have raised orders.
            'admins' => $isSuperAdmin
                ? User::query()
                    ->whereIn('id', InventoryOrder::store()->whereNotNull('created_by')->distinct()->pluck('created_by'))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : collect(),
        ];
    }
}; ?>

<div>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <div>
            <h1 class="h4 mb-1">Order Details</h1>
            <div class="text-muted-soft small">
                {{ $isSuperAdmin ? 'All admins’ orders across the store' : 'Orders you have raised' }}
            </div>
        </div>
        <button class="btn btn-outline-secondary" wire:click="exportCsv">
            <i class="bi bi-download"></i> Export CSV
        </button>
    </div>

    @if (session('inventory_success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('inventory_success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    <div class="row g-2 mb-3">
        <div class="col-md{{ $isSuperAdmin ? '-5' : '-8' }}">
            <input type="search" class="form-control" placeholder="Search by reference or customer…"
                   wire:model.live.debounce.400ms="search">
        </div>
        <div class="col-md-4 col-lg-3">
            <select class="form-select" wire:model.live="statusFilter">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        @if ($isSuperAdmin)
            <div class="col-md-3 col-lg-4">
                <select class="form-select" wire:model.live="adminFilter">
                    <option value="">All admins</option>
                    @foreach ($admins as $admin)
                        <option value="{{ $admin->id }}">{{ $admin->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Type</th>
                        <th class="text-end">Total (Naira)</th>
                        <th>Status</th>
                        <th>Raised by</th>
                        <th>Checked out by</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr wire:key="od-{{ $order->id }}" style="cursor:pointer" wire:click="toggle({{ $order->id }})">
                            <td class="fw-semibold">{{ $order->reference }}</td>
                            <td>{{ $order->customer_name ?? '—' }}</td>
                            <td class="text-capitalize">{{ $order->price_type }}</td>
                            <td class="text-end">{{ number_format($order->total_amount) }}</td>
                            <td><span class="badge {{ $order->status->badgeClass() }}">{{ $order->status->label() }}</span></td>
                            <td class="text-muted-soft small">{{ $order->creator?->name ?? '—' }}</td>
                            <td class="text-muted-soft small">{{ $order->paidBy?->name ?? '—' }}</td>
                            <td class="text-muted-soft small">{{ $order->created_at?->format('d M Y, H:i') }}</td>
                        </tr>
                        @if ($expandedId === $order->id)
                            <tr wire:key="od-detail-{{ $order->id }}">
                                <td colspan="8" class="bg-light">
                                    <table class="table table-sm mb-0">
                                        <thead><tr>
                                            <th>Item</th><th class="text-end">Qty</th>
                                            <th class="text-end">Naira price</th><th class="text-end">Line total</th>
                                        </tr></thead>
                                        <tbody>
                                        @foreach ($order->items as $item)
                                            <tr>
                                                <td>{{ $item->product_name }}</td>
                                                <td class="text-end">{{ number_format($item->quantity) }}</td>
                                                <td class="text-end">{{ number_format($item->unit_price) }}</td>
                                                <td class="text-end">{{ number_format($item->line_total) }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                    @if ($order->notes)
                                        <div class="small text-muted-soft mt-2"><strong>Notes:</strong> {{ $order->notes }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted-soft py-5">
                                No orders to show.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())
            <div class="card-footer">{{ $orders->links() }}</div>
        @endif
    </div>
</div>
