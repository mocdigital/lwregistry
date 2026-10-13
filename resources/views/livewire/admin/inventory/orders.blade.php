<?php

use App\Enums\InventoryOrderStatus;
use App\Models\BusinessProduct;
use App\Models\InventoryOrder;
use App\Models\InventoryOrderItem;
use App\Notifications\InventoryPaymentReceivedNotification;
use App\Support\InventoryCsvExporter;
use App\Support\InventoryStockManager;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Inventory Orders – Admin Dashboard', 'dataPage' => 'inventory-orders', 'pageTitle' => 'Inventory Orders & Checkout'])] class extends Component {
    use WithPagination;

    /* ---- Filters ---- */
    public string $statusFilter = '';

    public string $search = '';

    public ?int $expandedId = null;

    /* ---- Receipt: the just-checked-out order to offer a print for ---- */
    public ?int $receiptOrderId = null;

    /* ---- New order (POS checkout) ---- */
    public string $price_type = 'retail';

    public ?string $customer_name = null;

    public ?string $customer_phone = null;

    public ?string $customer_email = null;

    public ?string $notes = null;

    /** @var array<int, array{product_id: ?int, quantity: int}> */
    public array $lines = [];

    protected string $paginationTheme = 'bootstrap';

    protected $queryString = [
        'statusFilter' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    public function mount(): void
    {
        $this->lines = [['product_id' => null, 'quantity' => 1]];
    }

    // Reset to the first page whenever the search or status filter changes so
    // results stay consistent instead of landing on an empty later page.
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function addLine(): void
    {
        $this->lines[] = ['product_id' => null, 'quantity' => 1];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->addLine();
        }
    }

    /**
     * Live cart preview using current store product prices.
     */
    public function computedLines(): array
    {
        $products = BusinessProduct::store()
            ->whereIn('id', array_filter(array_column($this->lines, 'product_id')))
            ->get()
            ->keyBy('id');

        $rows = [];
        $total = 0;

        foreach ($this->lines as $line) {
            $product = $line['product_id'] ? $products->get($line['product_id']) : null;
            $qty = max(0, (int) ($line['quantity'] ?? 0));
            $unit = $product ? $product->priceFor($this->price_type) : 0;
            $lineTotal = $unit * $qty;
            $total += $lineTotal;

            $rows[] = [
                'product' => $product,
                'quantity' => $qty,
                'unit' => $unit,
                'line_total' => $lineTotal,
                'stock' => $product?->stock_quantity ?? 0,
                'out_of_stock' => $product && $product->stock_quantity <= 0,
                'insufficient' => $product && $qty > $product->stock_quantity,
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Inline restock from the New Order modal: add units to a product's stock
     * so an out-of-stock (or short) item can still be ordered without leaving
     * the checkout screen.
     */
    public function restock(int $lineIndex, InventoryStockManager $stock): void
    {
        $productId = $this->lines[$lineIndex]['product_id'] ?? null;
        $amount = (int) ($this->lines[$lineIndex]['restock'] ?? 0);

        if (! $productId || $amount < 1) {
            return;
        }

        $product = BusinessProduct::store()->find($productId);
        if (! $product) {
            return;
        }

        $stock->adjustStock($product, $amount);
        $this->lines[$lineIndex]['restock'] = null;
        session()->flash('inventory_success', "Added {$amount} to “{$product->name}” stock.");
    }

    public function createOrder(): void
    {
        $this->validate([
            'price_type' => ['required', 'in:retail,bulk'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $products = BusinessProduct::store()
            ->whereIn('id', array_column($this->lines, 'product_id'))
            ->get()
            ->keyBy('id');

        foreach ($this->lines as $i => $line) {
            if (! $products->has($line['product_id'])) {
                $this->addError("lines.{$i}.product_id", 'Select a valid product.');

                return;
            }
        }

        $order = DB::transaction(function () use ($products) {
            $order = InventoryOrder::create([
                'business_id' => null,
                'created_by' => auth()->id(),
                'price_type' => $this->price_type,
                'customer_name' => $this->customer_name,
                'customer_phone' => $this->customer_phone,
                'customer_email' => $this->customer_email,
                'notes' => $this->notes,
            ]);

            foreach ($this->lines as $line) {
                $product = $products->get($line['product_id']);
                $qty = (int) $line['quantity'];
                $unit = $product->priceFor($this->price_type);

                InventoryOrderItem::create([
                    'inventory_order_id' => $order->id,
                    'business_product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $unit * $qty,
                ]);
            }

            $order->recalculateTotal();

            return $order;
        });

        $this->reset(['customer_name', 'customer_phone', 'customer_email', 'notes']);
        $this->price_type = 'retail';
        $this->lines = [['product_id' => null, 'quantity' => 1]];
        $this->dispatch('close-order-modal');
        session()->flash('inventory_success', "Order {$order->reference} created (pending payment).");
    }

    /**
     * Checkout: mark an order paid, atomically decrementing stock.
     */
    public function markPaid(int $id, InventoryStockManager $stock): void
    {
        $order = InventoryOrder::store()->visibleTo(auth()->user())->findOrFail($id);

        try {
            // paid_by records which admin checked this order out from their desk.
            $stock->markPaid($order, auth()->id());
            // Offer a printable receipt for the order that was just checked out.
            $this->receiptOrderId = $order->id;

            // Email the customer a "Payment Received" confirmation if we captured
            // their address on the order.
            $this->notifyCustomer($order->refresh());

            session()->flash('inventory_success', "Order {$order->reference} checked out — stock updated.");
        } catch (RuntimeException $e) {
            session()->flash('inventory_error', $e->getMessage());
        }
    }

    /**
     * Send the customer their payment-received email when an address was saved.
     */
    private function notifyCustomer(InventoryOrder $order): void
    {
        $email = mb_trim((string) $order->customer_email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        (new AnonymousNotifiable())
            ->route('mail', $email)
            ->notify(new InventoryPaymentReceivedNotification($order));
    }

    public function clearReceipt(): void
    {
        $this->receiptOrderId = null;
    }

    /**
     * The order to render on the print receipt (with its items), if any.
     */
    public function receiptOrder(): ?InventoryOrder
    {
        if (! $this->receiptOrderId) {
            return null;
        }

        return InventoryOrder::store()
            ->visibleTo(auth()->user())
            ->with(['items', 'creator', 'paidBy'])
            ->find($this->receiptOrderId);
    }

    public function cancel(int $id, InventoryStockManager $stock): void
    {
        $order = InventoryOrder::store()->visibleTo(auth()->user())->findOrFail($id);

        try {
            $stock->cancel($order);
            session()->flash('inventory_success', "Order {$order->reference} cancelled.");
        } catch (RuntimeException $e) {
            session()->flash('inventory_error', $e->getMessage());
        }
    }

    /**
     * Base query for the orders this admin may see. Regular admins are limited
     * to their own orders (the ones they raised); super-admins see everything.
     */
    private function baseQuery()
    {
        return InventoryOrder::store()->visibleTo(auth()->user());
    }

    /**
     * Apply the active search + status filters to a fresh scoped query.
     */
    private function filteredQuery()
    {
        $query = $this->baseQuery()->with(['items', 'paidBy'])->latest();

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($search = mb_trim($this->search)) {
            // Resolve store products whose code exactly matches the term, so the
            // search can also find orders that contain that product's line item.
            $productIds = BusinessProduct::store()
                ->where('code', $search)
                ->pluck('id');

            $query->where(function ($q) use ($search, $productIds): void {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");

                if ($productIds->isNotEmpty()) {
                    $q->orWhereHas('items', function ($itemQuery) use ($productIds): void {
                        $itemQuery->whereIn('business_product_id', $productIds);
                    });
                }
            });
        }

        return $query;
    }

    /**
     * Download the currently filtered orders as CSV.
     */
    public function exportCsv(InventoryCsvExporter $exporter)
    {
        return $exporter->orders($this->filteredQuery()->reorder()->latest());
    }

    public function with(): array
    {
        $scoped = fn () => $this->baseQuery();

        return [
            'orders' => $this->filteredQuery()->paginate(15),
            'pendingCount' => $scoped()->where('status', InventoryOrderStatus::PENDING->value)->count(),
            'paidCount' => $scoped()->where('status', InventoryOrderStatus::PAID->value)->count(),
            'revenue' => (int) $scoped()->where('status', InventoryOrderStatus::PAID->value)->sum('total_amount'),
            'statuses' => InventoryOrderStatus::cases(),
            // Lightweight list for the searchable product combobox (id/name/stock).
            'productOptions' => BusinessProduct::store()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'stock_quantity'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'stock' => $p->stock_quantity,
                ])
                ->values()
                ->all(),
            'preview' => $this->computedLines(),
            'isSuperAdmin' => auth()->user()->isSuperAdmin(),
            'receiptOrder' => $this->receiptOrder(),
            'nairaPerEspee' => (float) config('espees.naira_per_espee', 2050),
        ];
    }
}; ?>

<div x-data="{ orderModal: false }"
     x-on:close-order-modal.window="orderModal = false">

    <style>
        [x-cloak]{display:none!important}
        .inv-modal-overlay{position:fixed;inset:0;z-index:1055;background:rgba(0,0,0,.5);
            overflow-y:auto;display:flex;align-items:flex-start;justify-content:center;
            padding:1.75rem 1rem;}
        .inv-modal-overlay .modal-dialog{width:100%;margin:0;}
        .inv-modal-overlay .modal-content{max-height:calc(100vh - 3.5rem);
            background:#fff;border-radius:.5rem;box-shadow:0 1rem 3rem rgba(0,0,0,.4);}
        .inv-modal-overlay .modal-body{overflow-y:auto;}

        /* On screen the receipt stays hidden; it only appears for printing. */
        .thermal-receipt{display:none;}
        @media print {
            /* Hide the whole app, show only the receipt, sized for an 80mm roll. */
            body * { visibility: hidden !important; }
            .thermal-receipt, .thermal-receipt * { visibility: visible !important; }
            .thermal-receipt{
                display:block !important; position:absolute; top:0; left:0;
                width:80mm; padding:2mm 3mm; margin:0;
                font-family:'Courier New', ui-monospace, monospace;
                font-size:12px; color:#000; background:#fff;
            }
            .thermal-receipt .r-center{text-align:center;}
            .thermal-receipt .r-row{display:flex;justify-content:space-between;gap:6px;}
            .thermal-receipt hr{border:0;border-top:1px dashed #000;margin:4px 0;}
            .thermal-receipt table{width:100%;border-collapse:collapse;}
            .thermal-receipt td{padding:1px 0;vertical-align:top;}
            .thermal-receipt .r-right{text-align:right;}
            @page { size: 80mm auto; margin: 0; }
        }
    </style>

    {{-- Searchable product combobox behaviour. @assets loads this once, OUTSIDE
         Livewire's morph region, so re-renders don't drop sibling DOM. --}}
    @assets
    <script>
        (function registerProductCombobox() {
            if (window.__productComboboxRegistered) return;
            const define = () => {
                if (window.__productComboboxRegistered || ! window.Alpine) return;
                window.__productComboboxRegistered = true;
                window.Alpine.data('productCombobox', (options, initialId, model) => ({
                    options: options,
                    model: model,
                    open: false,
                    query: '',
                    selectedId: initialId,
                    init() {
                        this.syncLabel();
                        this.$watch('selectedId', () => this.syncLabel());
                    },
                    syncLabel() {
                        const current = this.options.find(o => o.id === this.selectedId);
                        if (current && ! this.open) {
                            this.query = current.name;
                        }
                    },
                    get filtered() {
                        const q = this.query.trim().toLowerCase();
                        if (q === '') return this.options;
                        return this.options.filter(o => o.name.toLowerCase().includes(q));
                    },
                    choose(option) {
                        this.selectedId = option.id;
                        this.query = option.name;
                        this.open = false;
                        this.$wire.set(this.model, option.id);
                    },
                }));
            };
            if (window.Alpine) { define(); }
            else { document.addEventListener('alpine:init', define); }
        })();
    </script>
    @endassets

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <div>
            <h1 class="h4 mb-1">Orders &amp; Checkout</h1>
            <div class="text-muted-soft small">
                Central store ·
                {{ $isSuperAdmin ? 'showing all admins’ orders' : 'showing your orders' }}
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.inventory.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-box-seam"></i> Inventory
            </a>
            <button class="btn btn-outline-secondary" wire:click="exportCsv">
                <i class="bi bi-download"></i> Export CSV
            </button>
            <button class="btn btn-primary" @click="orderModal = true">
                <i class="bi bi-plus-lg"></i> New Order
            </button>
        </div>
    </div>

    @if (session('inventory_success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('inventory_success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if (session('inventory_error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('inventory_error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    {{-- Print-receipt prompt after a checkout --}}
    @if ($receiptOrder)
        <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>
                <i class="bi bi-printer"></i>
                Receipt ready for <strong>{{ $receiptOrder->reference }}</strong>.
            </span>
            <span class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print receipt
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="clearReceipt">
                    Dismiss
                </button>
            </span>
        </div>
    @endif

    <div class="row g-3 g-md-4 mb-4">
        <div class="col-4">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="small text-muted-soft">Pending</div>
                <div class="h4 mb-0 text-warning">{{ number_format($pendingCount) }}</div>
            </div></div>
        </div>
        <div class="col-4">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="small text-muted-soft">Paid</div>
                <div class="h4 mb-0 text-success">{{ number_format($paidCount) }}</div>
            </div></div>
        </div>
        <div class="col-4">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="small text-muted-soft">Revenue (paid)</div>
                <div class="h4 mb-0">{{ number_format($revenue) }}</div>
            </div></div>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-md-8">
            <input type="search" class="form-control" placeholder="Search by reference, customer or product code…"
                   wire:model.live.debounce.400ms="search">
        </div>
        <div class="col-md-4">
            <select class="form-select" wire:model.live="statusFilter">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Type</th>
                        <th class="text-end">Total</th>
                        <th>Status</th>
                        <th>Checked out by</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr wire:key="order-{{ $order->id }}" style="cursor:pointer" wire:click="toggle({{ $order->id }})">
                            <td class="fw-semibold">{{ $order->reference }}</td>
                            <td>{{ $order->customer_name ?? '—' }}</td>
                            <td class="text-capitalize">{{ $order->price_type }}</td>
                            <td class="text-end">{{ number_format($order->total_amount) }}</td>
                            <td>
                                <span class="badge {{ $order->status->badgeClass() }}">{{ $order->status->label() }}</span>
                            </td>
                            <td class="text-muted-soft small">{{ $order->paidBy?->name ?? '—' }}</td>
                            <td class="text-end" wire:click.stop>
                                @if ($order->isPending())
                                    <button class="btn btn-sm btn-success"
                                            wire:click="markPaid({{ $order->id }})"
                                            wire:confirm="Check out {{ $order->reference }} as paid? This reduces stock for each item.">
                                        Checkout / Mark Paid
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger"
                                            wire:click="cancel({{ $order->id }})"
                                            wire:confirm="Cancel this order?">Cancel</button>
                                @else
                                    <span class="text-muted-soft small">
                                        @if ($order->isPaid() && $order->paid_at)
                                            Paid {{ $order->paid_at->diffForHumans() }}
                                        @else
                                            —
                                        @endif
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @if ($expandedId === $order->id)
                            <tr wire:key="order-detail-{{ $order->id }}">
                                <td colspan="7" class="bg-light">
                                    <table class="table table-sm mb-0">
                                        <thead><tr>
                                            <th>Item</th><th class="text-end">Qty</th>
                                            <th class="text-end">Unit</th><th class="text-end">Line total</th>
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
                            <td colspan="7" class="text-center text-muted-soft py-5">
                                No orders yet. Create one to start selling from your inventory.
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

    {{-- New order modal (POS) --}}
    <div class="inv-modal-overlay" x-cloak x-show="orderModal" style="display:none;">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form wire:submit="createOrder">
                    <div class="modal-header">
                        <h5 class="modal-title">New Order</h5>
                        <button type="button" class="btn-close" @click="orderModal = false"></button>
                    </div>
                    <div class="modal-body">
                        @error('lines') <div class="alert alert-danger">{{ $message }}</div> @enderror
                        <div class="row g-4">
                            <div class="col-lg-7">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong>Line Items</strong>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <input type="radio" class="btn-check" id="pt-retail" value="retail" wire:model.live="price_type">
                                        <label class="btn btn-outline-primary" for="pt-retail">Retail</label>
                                        <input type="radio" class="btn-check" id="pt-bulk" value="bulk" wire:model.live="price_type">
                                        <label class="btn btn-outline-primary" for="pt-bulk">Bulk</label>
                                    </div>
                                </div>

                                @foreach ($lines as $i => $line)
                                    <div class="row g-2 align-items-end mb-2" wire:key="line-{{ $i }}">
                                        <div class="col-7">
                                            @if ($i === 0)<label class="form-label small text-muted-soft">Product</label>@endif
                                            {{-- Searchable product combobox: type to filter by name, click to select. --}}
                                            <div class="position-relative"
                                                 x-data="productCombobox(@js($productOptions), {{ $line['product_id'] ?? 'null' }}, @js('lines.'.$i.'.product_id'))"
                                                 @click.outside="open = false; syncLabel()">
                                                <input type="text" class="form-control"
                                                       placeholder="Search product…"
                                                       autocomplete="off"
                                                       x-model="query"
                                                       @focus="open = true"
                                                       @input="open = true"
                                                       :class="{ 'text-muted-soft': !selectedId && !query }">
                                                <div class="list-group position-absolute w-100 shadow-sm"
                                                     style="z-index:1080; max-height:220px; overflow-y:auto;"
                                                     x-show="open" x-cloak>
                                                    <template x-for="option in filtered" :key="option.id">
                                                        <button type="button"
                                                                class="list-group-item list-group-item-action d-flex justify-content-between"
                                                                :class="{ 'active': option.id === selectedId }"
                                                                @click="choose(option)">
                                                            <span x-text="option.name"></span>
                                                            <span class="small text-muted-soft" x-text="option.stock + ' in stock'"></span>
                                                        </button>
                                                    </template>
                                                    <div class="list-group-item text-muted-soft small" x-show="filtered.length === 0">
                                                        No products match “<span x-text="query"></span>”.
                                                    </div>
                                                </div>
                                            </div>
                                            @error("lines.{$i}.product_id") <div class="text-danger small">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-3">
                                            @if ($i === 0)<label class="form-label small text-muted-soft">Qty</label>@endif
                                            <input type="number" min="1" class="form-control" wire:model.live="lines.{{ $i }}.quantity">
                                            @error("lines.{$i}.quantity") <div class="text-danger small">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-2">
                                            <button type="button" class="btn btn-outline-danger w-100" wire:click="removeLine({{ $i }})">&times;</button>
                                        </div>
                                    </div>

                                    {{-- Out-of-stock / insufficient warning + inline restock --}}
                                    @php($lineRow = $preview['rows'][$i] ?? null)
                                    @if ($lineRow && $lineRow['product'] && ($lineRow['out_of_stock'] || $lineRow['insufficient']))
                                        <div class="alert alert-warning py-2 px-3 mb-3" wire:key="warn-{{ $i }}">
                                            <div class="small mb-2">
                                                @if ($lineRow['out_of_stock'])
                                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                                    <strong>{{ $lineRow['product']->name }}</strong> is out of stock.
                                                @else
                                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                                    Only <strong>{{ number_format($lineRow['stock']) }}</strong> of
                                                    <strong>{{ $lineRow['product']->name }}</strong> in stock ({{ $lineRow['quantity'] }} requested).
                                                @endif
                                                You can add stock now to continue.
                                            </div>
                                            <div class="input-group input-group-sm" style="max-width:260px;">
                                                <span class="input-group-text">Add stock</span>
                                                <input type="number" min="1" class="form-control"
                                                       placeholder="Qty" wire:model="lines.{{ $i }}.restock">
                                                <button type="button" class="btn btn-outline-dark"
                                                        wire:click="restock({{ $i }})">Add</button>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach

                                <button type="button" class="btn btn-sm btn-outline-primary mt-2" wire:click="addLine">
                                    <i class="bi bi-plus-lg"></i> Add line
                                </button>

                                <hr>
                                <strong class="d-block mb-2">Customer (optional)</strong>
                                <div class="row g-2">
                                    <div class="col-md-6"><input type="text" class="form-control" placeholder="Name" wire:model="customer_name"></div>
                                    <div class="col-md-6"><input type="text" class="form-control" placeholder="Phone" wire:model="customer_phone"></div>
                                    <div class="col-md-6">
                                        <input type="email" class="form-control" placeholder="Email" wire:model="customer_email">
                                        @error('customer_email') <div class="text-danger small">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-12"><textarea class="form-control" rows="2" placeholder="Notes" wire:model="notes"></textarea></div>
                                </div>
                            </div>

                            {{-- Summary --}}
                            <div class="col-lg-5">
                                <div class="card">
                                    <div class="card-header"><strong>Summary</strong></div>
                                    <div class="card-body">
                                        <table class="table table-sm mb-3">
                                            <tbody>
                                            @foreach ($preview['rows'] as $row)
                                                @if ($row['product'])
                                                    <tr @class(['text-danger' => $row['insufficient']])>
                                                        <td>
                                                            {{ $row['product']->name }}
                                                            <div class="small text-muted-soft">
                                                                {{ $row['quantity'] }} × {{ number_format($row['unit']) }}
                                                                @if ($row['insufficient'])<span class="text-danger">· exceeds stock</span>@endif
                                                            </div>
                                                        </td>
                                                        <td class="text-end">{{ number_format($row['line_total']) }}</td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                            </tbody>
                                        </table>
                                        <div class="d-flex justify-content-between border-top pt-2">
                                            <span class="fw-bold">Total (Naira)</span>
                                            <span class="fw-bold">&#8358;{{ number_format($preview['total']) }}</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-1 p-2 rounded"
                                             style="background:#eef7f0;">
                                            <span class="small text-success"><i class="bi bi-coin"></i> Espees equivalent</span>
                                            <span class="fw-semibold text-success">
                                                {{ number_format(\App\Models\InventoryOrder::nairaToEspees($preview['total']), 2) }} Espees
                                            </span>
                                        </div>
                                        <p class="small text-muted-soft mt-2 mb-0">
                                            Rate: 1 Espee = &#8358;{{ number_format((float) config('espees.naira_per_espee', 2050)) }}.
                                            Stock is only reduced when you check out (mark paid).
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="orderModal = false">Cancel</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="createOrder">
                            <span wire:loading.remove wire:target="createOrder">Create Order</span>
                            <span wire:loading wire:target="createOrder">Saving…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- 80mm thermal print receipt (hidden on screen; @media print reveals it) --}}
    @if ($receiptOrder)
        <div class="thermal-receipt">
            <div class="r-center">
                <strong>Cheaper with Espees</strong><br>
                Sales Receipt
            </div>
            <hr>
            <div class="r-row"><span>Ref</span><span>{{ $receiptOrder->reference }}</span></div>
            <div class="r-row"><span>Date</span><span>{{ $receiptOrder->paid_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}</span></div>
            <div class="r-row"><span>Cashier</span><span>{{ $receiptOrder->paidBy?->name ?? '—' }}</span></div>
            @if ($receiptOrder->customer_name)
                <div class="r-row"><span>Customer</span><span>{{ $receiptOrder->customer_name }}</span></div>
            @endif
            <div class="r-row"><span>Price type</span><span class="text-capitalize">{{ $receiptOrder->price_type }}</span></div>
            <hr>
            <table>
                <tbody>
                @foreach ($receiptOrder->items as $item)
                    <tr>
                        <td>{{ $item->product_name }}</td>
                        <td class="r-right">&#8358;{{ number_format($item->line_total) }}</td>
                    </tr>
                    <tr>
                        <td colspan="2">{{ $item->quantity }} x &#8358;{{ number_format($item->unit_price) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <hr>
            <div class="r-row"><strong>TOTAL</strong><strong>&#8358;{{ number_format($receiptOrder->total_amount) }}</strong></div>
            <div class="r-row"><span>Espees</span><span>{{ number_format($receiptOrder->totalInEspees(), 2) }}</span></div>
            <div class="r-center" style="font-size:10px;margin-top:2px;">1 Espee = &#8358;{{ number_format($nairaPerEspee) }}</div>
            <hr>
            <div class="r-center">Thank you for your purchase!</div>
        </div>
    @endif
</div>
