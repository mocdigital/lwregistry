<?php

use App\Models\BusinessProduct;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Inventory – Admin Dashboard', 'dataPage' => 'inventory', 'pageTitle' => 'Inventory'])] class extends Component {
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public string $stockFilter = '';

    /* ---- Create / edit product form ---- */
    public ?int $editingId = null;

    public string $name = '';

    public ?string $code = null;

    public ?string $category = null;

    public ?string $sku = null;

    public ?string $description = null;

    public ?string $size = null;

    public ?string $colour = null;

    public int $retail_price = 0;

    public int $bulk_price = 0;

    public int $bulk_min_quantity = 1;

    public int $stock_quantity = 0;

    public int $low_stock_threshold = 0;

    public bool $is_active = true;

    /* ---- Bulk CSV upload ---- */
    public $csv = null;

    protected string $paginationTheme = 'bootstrap';

    protected $queryString = [
        'search' => ['except' => ''],
        'stockFilter' => ['except' => ''],
    ];

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:150'],
            'sku' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'size' => ['nullable', 'string', 'max:100'],
            'colour' => ['nullable', 'string', 'max:100'],
            'retail_price' => ['required', 'integer', 'min:0'],
            'bulk_price' => ['required', 'integer', 'min:0'],
            'bulk_min_quantity' => ['required', 'integer', 'min:1'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->dispatch('open-product-modal');
    }

    public function edit(int $id): void
    {
        $product = BusinessProduct::store()->findOrFail($id);

        $this->editingId = $product->id;
        $this->name = $product->name;
        $this->code = $product->code;
        $this->category = $product->category;
        $this->sku = $product->sku;
        $this->description = $product->description;
        $this->size = $product->size;
        $this->colour = $product->colour;
        $this->retail_price = $product->retail_price;
        $this->bulk_price = $product->bulk_price;
        $this->bulk_min_quantity = $product->bulk_min_quantity;
        $this->stock_quantity = $product->stock_quantity;
        $this->low_stock_threshold = $product->low_stock_threshold;
        $this->is_active = $product->is_active;

        $this->dispatch('open-product-modal');
    }

    public function save(): void
    {
        $this->validate();

        $payload = [
            'business_id' => null, // central store
            'name' => $this->name,
            'code' => $this->code ?: null,
            'category' => $this->category ?: null,
            'sku' => $this->sku ?: null,
            'description' => $this->description ?: null,
            'size' => $this->size ?: null,
            'colour' => $this->colour ?: null,
            'retail_price' => $this->retail_price,
            'bulk_price' => $this->bulk_price,
            'bulk_min_quantity' => $this->bulk_min_quantity,
            'stock_quantity' => $this->stock_quantity,
            'low_stock_threshold' => $this->low_stock_threshold,
            'is_active' => $this->is_active,
        ];

        if ($this->editingId) {
            BusinessProduct::store()->whereKey($this->editingId)->firstOrFail()->update($payload);
            session()->flash('inventory_success', 'Product updated.');
        } else {
            BusinessProduct::create($payload);
            session()->flash('inventory_success', 'Product added to inventory.');
        }

        $this->resetForm();
        $this->dispatch('close-product-modal');
    }

    public function delete(int $id): void
    {
        BusinessProduct::store()->whereKey($id)->firstOrFail()->delete();
        session()->flash('inventory_success', 'Product removed.');
    }

    public function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'code', 'category', 'sku', 'description',
            'size', 'colour', 'retail_price', 'bulk_price', 'bulk_min_quantity',
            'stock_quantity', 'low_stock_threshold',
        ]);
        $this->is_active = true;
        $this->bulk_min_quantity = 1;
        $this->resetValidation();
    }

    /**
     * Bulk-import products from a CSV mapped strictly by COLUMN POSITION
     * (left → right), regardless of the header names. Column order is:
     *   1 Name, 2 Code, 3 Category, 4 Status Price, 5 Description,
     *   6 In Stock, 7 Stock_warn limit, 8 Size, 9 Colour, 10 Sku, 11 Naira price
     *
     * A leading header row (first cell = "name") is skipped automatically.
     * Rows whose Sku already exists update that product; others are created.
     * "Status Price" maps to active/inactive; "Naira price" maps to retail.
     */
    public function importCsv(): void
    {
        $this->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $handle = fopen($this->csv->getRealPath(), 'r');
        if ($handle === false) {
            session()->flash('inventory_error', 'Could not read the uploaded file.');

            return;
        }

        $cell = fn (array $row, int $i): ?string => (isset($row[$i]) && mb_trim((string) $row[$i]) !== '')
            ? mb_trim((string) $row[$i])
            : null;

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $first = true;

        while (($row = fgetcsv($handle)) !== false) {
            // Skip a leading header row if present. A data row always carries a
            // number in the numeric positions (In Stock = 5, Naira price = 10);
            // if neither is numeric, treat the first row as a header.
            if ($first) {
                $first = false;
                $stockCell = mb_trim((string) ($row[5] ?? ''));
                $priceCell = mb_trim((string) ($row[10] ?? ''));
                if (! is_numeric($stockCell) && ! is_numeric($priceCell)) {
                    continue;
                }
            }

            // Positional mapping, left → right, in the fixed column sequence:
            // 0 Name, 1 Code, 2 Category, 3 Status Price, 4 Description,
            // 5 In Stock, 6 Stock_warn limit, 7 Size, 8 Colour, 9 Sku, 10 Naira price
            $name = $cell($row, 0);

            if ($name === null) {
                $skipped++;
                continue;
            }

            $statusRaw = mb_strtolower((string) $cell($row, 3));
            $isActive = ! in_array($statusRaw, ['inactive', 'disabled', '0', 'no', 'off'], true);

            $sku = $cell($row, 9);
            $payload = [
                'business_id' => null,
                'name' => $name,
                'code' => $cell($row, 1),
                'category' => $cell($row, 2),
                'is_active' => $isActive,
                'description' => $cell($row, 4),
                'stock_quantity' => max(0, (int) ($cell($row, 5) ?? 0)),
                'low_stock_threshold' => max(0, (int) ($cell($row, 6) ?? 0)),
                'size' => $cell($row, 7),
                'colour' => $cell($row, 8),
                'sku' => $sku,
                'retail_price' => max(0, (int) ($cell($row, 10) ?? 0)),
            ];

            $existing = $sku ? BusinessProduct::store()->where('sku', $sku)->first() : null;

            if ($existing) {
                $existing->update($payload);
                $updated++;
            } else {
                BusinessProduct::create($payload);
                $created++;
            }
        }

        fclose($handle);
        $this->reset('csv');
        $this->dispatch('close-import-modal');
        session()->flash(
            'inventory_success',
            "Import complete: {$created} added, {$updated} updated, {$skipped} skipped."
        );
    }

    /**
     * The product query with the active search + stock filters applied. Shared
     * by the on-screen table and the CSV export so they always match.
     */
    private function filteredQuery()
    {
        $query = BusinessProduct::store()->orderBy('name');

        if ($search = mb_trim($this->search)) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($this->stockFilter === 'out') {
            $query->where('stock_quantity', '<=', 0);
        } elseif ($this->stockFilter === 'low') {
            $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                ->where('low_stock_threshold', '>', 0)
                ->where('stock_quantity', '>', 0);
        }

        return $query;
    }

    /**
     * Download every product in the current table view (all filtered rows) as CSV.
     */
    public function exportCsv(\App\Support\InventoryCsvExporter $exporter)
    {
        return $exporter->products($this->filteredQuery());
    }

    public function with(): array
    {
        return [
            'products' => $this->filteredQuery()->paginate(15),
            'totalProducts' => BusinessProduct::store()->count(),
            'outOfStock' => BusinessProduct::store()->where('stock_quantity', '<=', 0)->count(),
            'stockValue' => (int) BusinessProduct::store()
                ->selectRaw('COALESCE(SUM(stock_quantity * retail_price), 0) AS v')->value('v'),
        ];
    }
}; ?>

<div x-data="{ productModal: false, importModal: false }"
     x-on:open-product-modal.window="productModal = true"
     x-on:close-product-modal.window="productModal = false"
     x-on:close-import-modal.window="importModal = false">

    <style>
        [x-cloak]{display:none!important}
        /* Scrollable modal overlay: the overlay scrolls, the dialog keeps a
           safe margin, and the body scrolls internally on short screens. */
        .inv-modal-overlay{position:fixed;inset:0;z-index:1055;background:rgba(0,0,0,.5);
            overflow-y:auto;display:flex;align-items:flex-start;justify-content:center;
            padding:1.75rem 1rem;}
        .inv-modal-overlay .modal-dialog{width:100%;margin:0;}
        .inv-modal-overlay .modal-content{max-height:calc(100vh - 3.5rem);
            background:#fff;border-radius:.5rem;box-shadow:0 1rem 3rem rgba(0,0,0,.4);}
        .inv-modal-overlay .modal-body{overflow-y:auto;}
    </style>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <div>
            <h1 class="h4 mb-1">Inventory</h1>
            <div class="text-muted-soft small">Central store &middot; manage stock &amp; pricing</div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.inventory.orders.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-receipt"></i> Orders &amp; Checkout
            </a>
            <button class="btn btn-outline-secondary" wire:click="exportCsv">
                <i class="bi bi-download"></i> Export CSV
            </button>
            <button class="btn btn-outline-primary" @click="importModal = true">
                <i class="bi bi-upload"></i> Bulk Upload
            </button>
            <button class="btn btn-primary" wire:click="openCreate">
                <i class="bi bi-plus-lg"></i> Add Product
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

    {{-- Stat tiles --}}
    <div class="row g-3 g-md-4 mb-4">
        <div class="col-6 col-md-4">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="small text-muted-soft">Products</div>
                <div class="h4 mb-0">{{ number_format($totalProducts) }}</div>
            </div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="small text-muted-soft">Out of Stock</div>
                <div class="h4 mb-0 text-danger">{{ number_format($outOfStock) }}</div>
            </div></div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="small text-muted-soft">Stock Value (Naira)</div>
                <div class="h4 mb-0">{{ number_format($stockValue) }}</div>
            </div></div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="row g-2 mb-3">
        <div class="col-md-8">
            <input type="search" class="form-control" placeholder="Search by name, code, SKU or category…"
                   wire:model.live.debounce.400ms="search">
        </div>
        <div class="col-md-4">
            <select class="form-select" wire:model.live="stockFilter">
                <option value="">All stock levels</option>
                <option value="low">Low stock</option>
                <option value="out">Out of stock</option>
            </select>
        </div>
    </div>

    {{-- Product table --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="min-width:1100px;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Category</th>
                        <th>Status Price</th>
                        <th>Description</th>
                        <th class="text-end">In Stock</th>
                        <th class="text-end">Stock_warn limit</th>
                        <th>Size</th>
                        <th>Colour</th>
                        <th>Sku</th>
                        <th class="text-end">Naira price</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr wire:key="product-{{ $product->id }}">
                            <td class="fw-semibold">{{ $product->name }}</td>
                            <td class="text-muted-soft">{{ $product->code ?? '—' }}</td>
                            <td>{{ $product->category ?? '—' }}</td>
                            <td>
                                @if ($product->is_active)
                                    <span class="badge bg-success-subtle text-success">Active</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="text-muted-soft text-truncate" style="max-width:220px;">{{ $product->description ?? '—' }}</td>
                            <td class="text-end">
                                <span class="fw-semibold">{{ number_format($product->stock_quantity) }}</span>
                                @if ($product->isOutOfStock())
                                    <span class="badge bg-danger-subtle text-danger ms-1">Out</span>
                                @elseif ($product->isLowStock())
                                    <span class="badge bg-warning-subtle text-warning ms-1">Low</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($product->low_stock_threshold) }}</td>
                            <td>{{ $product->size ?? '—' }}</td>
                            <td>{{ $product->colour ?? '—' }}</td>
                            <td class="text-muted-soft">{{ $product->sku ?? '—' }}</td>
                            <td class="text-end">{{ number_format($product->retail_price) }}</td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-secondary"
                                        wire:click="edit({{ $product->id }})">Edit</button>
                                <button class="btn btn-sm btn-outline-danger"
                                        wire:click="delete({{ $product->id }})"
                                        wire:confirm="Remove this product from inventory?">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="text-center text-muted-soft py-5">
                                No products yet. Add one or bulk-upload a CSV to get started.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($products->hasPages())
            <div class="card-footer">{{ $products->links() }}</div>
        @endif
    </div>

    {{-- Product modal --}}
    <div class="inv-modal-overlay" x-cloak x-show="productModal" style="display:none;">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form wire:submit="save">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingId ? 'Edit Product' : 'Add Product' }}</h5>
                        <button type="button" class="btn-close" @click="productModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model="name">
                                @error('name') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Code</label>
                                <input type="text" class="form-control" wire:model="code">
                                @error('code') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">SKU</label>
                                <input type="text" class="form-control" wire:model="sku">
                                @error('sku') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Category</label>
                                <input type="text" class="form-control" wire:model="category">
                                @error('category') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Size</label>
                                <input type="text" class="form-control" wire:model="size">
                                @error('size') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Colour</label>
                                <input type="text" class="form-control" wire:model="colour">
                                @error('colour') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" rows="2" wire:model="description"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Naira price (retail)</label>
                                <input type="number" min="0" class="form-control" wire:model="retail_price">
                                @error('retail_price') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Bulk price</label>
                                <input type="number" min="0" class="form-control" wire:model="bulk_price">
                                @error('bulk_price') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bulk min qty</label>
                                <input type="number" min="1" class="form-control" wire:model="bulk_min_quantity">
                                @error('bulk_min_quantity') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">In stock</label>
                                <input type="number" min="0" class="form-control" wire:model="stock_quantity">
                                @error('stock_quantity') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Stock warn limit</label>
                                <input type="number" min="0" class="form-control" wire:model="low_stock_threshold">
                                @error('low_stock_threshold') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="is_active" wire:model="is_active">
                                    <label class="form-check-label" for="is_active">Active (available for orders)</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="productModal = false">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <span wire:loading.remove wire:target="save">Save Product</span>
                            <span wire:loading wire:target="save">Saving…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Bulk import modal --}}
    <div class="inv-modal-overlay" x-cloak x-show="importModal" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-content">
                <form wire:submit="importCsv">
                    <div class="modal-header">
                        <h5 class="modal-title">Bulk Upload Stock</h5>
                        <button type="button" class="btn-close" @click="importModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted-soft small">
                            Upload a CSV. Columns are read <strong>by position, left to right</strong>
                            (header names are ignored), in this order:
                            <code>Name, Code, Category, Status Price, Description, In Stock,
                            Stock_warn limit, Size, Colour, Sku, Naira price</code>.
                            A header row is optional and skipped automatically.
                            Rows whose <strong>Sku</strong> already exists update that product; the rest are added.
                        </p>
                        <input type="file" class="form-control" wire:model="csv" accept=".csv,text/csv">
                        @error('csv') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        <div wire:loading wire:target="csv" class="small text-muted-soft mt-2">
                            <span class="spinner-border spinner-border-sm"></span> Uploading file…
                        </div>
                        <div wire:loading.remove wire:target="csv">
                            @if ($csv)
                                <div class="small text-success mt-2"><i class="bi bi-check-circle"></i> File ready: {{ $csv->getClientOriginalName() }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="importModal = false">Cancel</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="importCsv,csv">
                            <span wire:loading.remove wire:target="importCsv">Import</span>
                            <span wire:loading wire:target="importCsv">Importing…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
