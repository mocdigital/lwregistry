<?php

use App\Models\Category;
use App\Models\Business;
use App\Models\Country;
use App\Models\State;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;

new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Categories & Locations – Admin Dashboard',
        'dataPage' => 'categories-locations',
        'pageTitle' => 'Categories & Locations'
    ]
)] class extends Component {
    use WithFileUploads;

    public string $name = '';

    /*
     * The band behind a category's title on /categories.
     *
     * Every category shared the site's stock hero video, so browsing from
     * Construction to Churches & Ministries changed the heading and nothing
     * else. Held per category id while one is being replaced.
     */
    public ?int $coverForCategoryId = null;

    public $cover = null;
    public ?int $editingCategoryId = null;
    public string $editingName = '';
    public string $locationName = '';
    public ?int $countryId = null;

    public function mount(): void
    {
        if (! auth()->user()?->hasPermission(\App\Support\AdminCapabilities::MANAGE_CATEGORIES)) {
            abort(403);
        }
    }

    //DELETE LOCATION
    public function deleteLocation(int $locationId): void
    {
        $location = State::withCount('businesses')->findOrFail($locationId);

        if ($location->businesses_count > 0) {
            $this->addError('locationDelete', 'Cannot delete a location with businesses.');
            return;
        }

        $name = $location->name;
        $location->delete();

        \App\Support\Audit::log('location_deleted', $name, 'Location deleted', 'deleted');
    }

    //SAVE LOCATION
    public function saveLocation(): void
    {
        $this->validate([
            'countryId' => 'required|exists:countries,id',
            'locationName' => 'required|string|max:255|unique:states,name',
        ]);

        State::create([
            'name' => $this->locationName,
            'country_id' => $this->countryId,
        ]);

        \App\Support\Audit::log('location_created', $this->locationName, 'Location created', 'created');

        $this->reset(['locationName', 'countryId']);
        $this->resetErrorBag();

        $this->dispatch('close-add-location-modal');
    }

    //TRIGGER RENAME MODAL
    /** Chosen through the file input; saved as soon as it arrives. */
    public function updatedCover(): void
    {
        if (null === $this->coverForCategoryId) {
            return;
        }

        $this->validate([
            'cover' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [], ['cover' => 'cover image']);

        $category = Category::findOrFail($this->coverForCategoryId);

        // singleFile() on the collection, so this replaces rather than stacks.
        $category->addMedia($this->cover->getRealPath())
            ->usingFileName(Str::slug($category->name) . '.' . $this->cover->getClientOriginalExtension())
            ->toMediaCollection(Category::COVER_COLLECTION);

        $this->reset(['cover', 'coverForCategoryId']);

        session()->flash('success', 'Category cover updated.');
    }

    public function chooseCover(int $categoryId): void
    {
        $this->coverForCategoryId = $categoryId;
    }

    public function removeCover(int $categoryId): void
    {
        Category::findOrFail($categoryId)->clearMediaCollection(Category::COVER_COLLECTION);

        session()->flash('success', 'Category cover removed. The shared hero shows instead.');
    }

    public function startRename(int $categoryId): void
    {
        $category = Category::findOrFail($categoryId);

        $this->editingCategoryId = $category->id;
        $this->editingName = $category->name;

        $this->dispatch('open-rename-category-modal');
    }

    //RENAME CATEGORY
    public function renameCategory(): void
    {
        $this->validate([
            'editingName' => 'required|string|max:255|unique:categories,name,' . $this->editingCategoryId,
        ]);

        $category = Category::findOrFail($this->editingCategoryId);

        $category->update([
            'name' => $this->editingName,
            'slug' => Str::slug($this->editingName),
        ]);

        \App\Support\Audit::log('category_renamed', $this->editingName, 'Category renamed');

        $this->reset(['editingCategoryId', 'editingName']);

        $this->dispatch('close-rename-category-modal');
    }

    //DELETE CATEGORY
    public function deleteCategory(int $categoryId): void
    {
        $category = Category::findOrFail($categoryId);

        if ($category->business_count > 0) {
            $this->addError('delete', 'Cannot delete a category with businesses.');
            return;
        }

        $name = $category->name;
        $category->delete();

        \App\Support\Audit::log('category_deleted', $name, 'Category deleted', 'deleted');
    }

    //SAVE CATEGORY
    public function saveCategory(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:categories,name',
        ]);

        Category::create([
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'business_count' => 0,
        ]);

        \App\Support\Audit::log('category_created', $this->name, 'Category created', 'created');

        $this->reset('name');

        $this->dispatch('close-add-category-modal');

        session()->flash('success', 'Category added successfully.');
    }

    //DATA FOR RENDERING
    public function with(): array
    {
        $categories = Category::orderByDesc('business_count')->get();
        $topCategory = $categories->first();

        $locations = State::withCount('businesses')
            ->orderByDesc('businesses_count')
            ->get();
        $countries = Country::orderBy('name')->get();

        return [
            'categories' => $categories,
            'locations' => $locations,
            'totalCategories' => $categories->count(),
            'topCategory' => $topCategory,
            'totalBusinesses' => Business::count(),
            'countries' => $countries,
        ];
    }
};
?>

<div>
    <section class="mb-5">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h5 mb-1">Categories & Locations</h2>
                <p class="text-muted-soft small mb-0">
                    Manage business categories and supported locations powering the Loveworld Registry.
                </p>
            </div>
        </div>

        <!-- Analytics row -->
        <div class="row g-3 g-md-4 mb-4 align-items-stretch">
                    
             {{-- DELETE ERROR --}}
             @if($errors->has('delete'))
                <div class="alert alert-warning alert-dismissible fade show mb-3">
                    {{ $errors->first('delete') }}
                </div>
             @endif    
             
            @if($errors->has('locationDelete'))
                <div class="alert alert-warning alert-dismissible fade show mb-3">
                    {{ $errors->first('locationDelete') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
                </div>
            @endif

                    
            <!-- Categories stats -->
            <div class="col-12 col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <div>
                                <div class="small text-muted-soft">Business categories</div>
                                <div class="h4 mb-0">{{ $totalCategories }}</div>
                            </div>
                            <div class="icon-circle">
                                <i class="bi bi-tags"></i>
                            </div>
                        </div>

                        @if($topCategory)
                            <div class="small text-muted-soft mb-1">
                                Top category:
                                <strong>{{ $topCategory->name }}</strong>
                            </div>
                            <span class="chip">
                                {{ $topCategory->business_count }} businesses
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Locations stats -->
            <div class="col-12 col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <div>
                                <div class="small text-muted-soft">Locations</div>
                                <div class="h4 mb-0">{{ $locations->count() }} cities</div>
                            </div>
                            <div class="icon-circle">
                                <i class="bi bi-geo-alt"></i>
                            </div>
                        </div>

                        <div class="small text-muted-soft mb-1">
                            Total businesses mapped:
                            <strong>{{ $totalBusinesses }}</strong>
                        </div>

                        @foreach($locations->take(2) as $location)
                            <span class="chip me-1">
                                {{ $location->name }}: {{ $location->businesses_count }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Tables -->
        <div class="row g-3 g-md-4">

            <!-- Categories table -->
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Business Categories</h5>
                        <button
                          class="btn btn-sm btn-primary rounded-pill"
                          data-bs-toggle="modal"
                          data-bs-target="#addCategoryModal"
                        >
                            <i class="bi bi-plus-circle me-1"></i>Add category
                        </button>
                    </div>

                    <div class="card-body">
                        {{-- The picker, shown once a category is chosen. One input
                             for the whole table rather than one per row: only one
                             upload can be in flight, and 40 hidden file inputs is
                             40 chances for the wrong one to fire. --}}
                        @if($coverForCategoryId)
                            @php($coverCategory = $categories->firstWhere('id', $coverForCategoryId))
                            <div class="alert alert-secondary d-flex flex-wrap align-items-center gap-3 py-2">
                                <span class="small mb-0">
                                    Cover for <strong>{{ $coverCategory?->name }}</strong>
                                </span>
                                <input
                                    type="file"
                                    class="form-control form-control-sm @error('cover') is-invalid @enderror"
                                    style="max-width: 320px"
                                    accept=".jpg,.jpeg,.png,.webp"
                                    wire:model="cover"
                                >
                                <span class="small text-muted" wire:loading wire:target="cover">Uploading…</span>
                                <button class="btn btn-sm btn-link" wire:click="$set('coverForCategoryId', null)">Cancel</button>
                                @error('cover')
                                    <div class="invalid-feedback d-block w-100">{{ $message }}</div>
                                @enderror
                                <span class="small text-muted w-100">
                                    Wide crops read best — the band is 1600&times;600. JPG, PNG or WebP up to 5MB.
                                </span>
                            </div>
                        @endif

                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 84px">Cover</th>
                                    <th>Name</th>
                                    <th>Businesses</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($categories as $category)
                                    <tr>
                                        <td>
                                            @if($category->coverUrl())
                                                <img
                                                    src="{{ $category->coverUrl() }}"
                                                    alt=""
                                                    class="rounded"
                                                    style="width: 68px; height: 40px; object-fit: cover"
                                                >
                                            @else
                                                {{-- No cover: /categories falls back to the shared hero. --}}
                                                <span class="d-inline-flex align-items-center justify-content-center rounded bg-body-secondary text-muted small"
                                                      style="width: 68px; height: 40px">—</span>
                                            @endif
                                        </td>
                                        <td>{{ $category->name }}</td>
                                        <td>{{ $category->business_count }}</td>
                                        <td class="text-end">
                                            <button
                                                class="btn btn-sm btn-outline-secondary rounded-pill me-1"
                                                wire:click="chooseCover({{ $category->id }})"
                                            >{{ $category->coverUrl() ? 'Replace cover' : 'Add cover' }}</button>

                                            @if($category->coverUrl())
                                                <button
                                                    class="btn btn-sm btn-outline-danger rounded-pill me-1"
                                                    wire:click="removeCover({{ $category->id }})"
                                                    wire:confirm="Remove this cover? The shared hero will show instead."
                                                >Clear</button>
                                            @endif

                                            <button
                                                class="btn btn-sm btn-outline-secondary rounded-pill me-1"
                                                wire:click="startRename({{ $category->id }})"
                                                > Rename </button>

                                            <button
                                                class="btn btn-sm btn-outline-danger rounded-pill"
                                                wire:click="deleteCategory({{ $category->id }})"
                                                wire:confirm="Are you sure you want to delete this category?"
                                                @disabled($category->business_count > 0)
                                                title="{{ $category->business_count > 0 
                                                    ? 'Cannot delete category with businesses' 
                                                    : 'Delete category' }}"
                                            >
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-muted-soft text-center py-4">
                                            No categories found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Locations table -->
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Locations</h5>
                            <button
                            class="btn btn-sm btn-primary rounded-pill"
                            data-bs-toggle="modal"
                            data-bs-target="#addLocationModal"
                            >
                            <i class="bi bi-plus-circle me-1"></i>Add location
                        </button>
                    </div>

                    <div class="card-body">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>City</th>
                                    <th>Country</th>
                                    <th>Businesses</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($locations as $location)
                                    <tr>
                                        <td>{{ $location->name }}</td>
                                        <td>{{ $location->country->name ?? '—' }}</td>
                                        <td>{{ $location->businesses_count }}</td>
                                        <td class="text-end">
                                            <button
                                            class="btn btn-sm btn-outline-danger rounded-pill"
                                            wire:click="deleteLocation({{ $location->id }})"
                                            @disabled($location->businesses_count > 0)
                                            >
                                            Delete
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-muted-soft text-center py-4">
                                            No locations found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- Add Category Modal -->
    <div
    class="modal fade"
    id="addCategoryModal"
    tabindex="-1"
    aria-labelledby="addCategoryModalLabel"
    aria-hidden="true"
    wire:ignore.self
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="addCategoryModalLabel">Add Business Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <form wire:submit.prevent="saveCategory">
                <div class="mb-3">
                    <label class="form-label small">Category name</label>
                    <input
                    type="text"
                    class="form-control form-control-sm"
                    wire:model.defer="name"
                    >
                    @error('name')
                    <small class="text-danger">{{ $message }}</small>
                    @enderror
                </div>
                </form>
            </div>

            <div class="modal-footer">
                <button class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">
                Cancel
                </button>
                <button
                class="btn btn-primary rounded-pill"
                wire:click="saveCategory"
                >
                Save category
                </button>
            </div>

            </div>
        </div>
    </div>

    <!-- Rename Category Modal -->
    <div
    class="modal fade"
    id="renameCategoryModal"
    tabindex="-1"
    aria-labelledby="renameCategoryModalLabel"
    aria-hidden="true"
    wire:ignore.self
    >
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

        <div class="modal-header">
            <h5 class="modal-title" id="renameCategoryModalLabel">Rename Category</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
            <form wire:submit.prevent="renameCategory">
            <div class="mb-3">
                <label class="form-label small">Category name</label>
                <input
                type="text"
                class="form-control form-control-sm"
                wire:model.defer="editingName"
                >
                @error('editingName')
                <small class="text-danger">{{ $message }}</small>
                @enderror
            </div>
            </form>
        </div>

        <div class="modal-footer">
            <button class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">
            Cancel
            </button>
            <button
            class="btn btn-primary rounded-pill"
            wire:click="renameCategory"
            >
            Save changes
            </button>
        </div>

        </div>
    </div>
    </div>

    <!-- Add Location Modal -->
    <div
    class="modal fade"
    id="addLocationModal"
    tabindex="-1"
    aria-labelledby="addLocationModalLabel"
    aria-hidden="true"
    wire:ignore.self
    >
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

        <div class="modal-header">
        <h5 class="modal-title" id="addLocationModalLabel">Add Location</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
            <form wire:submit.prevent="saveLocation">

            <!-- Country -->
            <div class="mb-3">
                <label class="form-label small">Country</label>
                <select
                class="form-select form-select-sm"
                wire:model.defer="countryId"
                >
                <option value="">Select country</option>
                @foreach($countries as $country)
                    <option value="{{ $country->id }}">
                    {{ $country->name }}
                    </option>
                @endforeach
                </select>

                @error('countryId')
                <small class="text-danger">{{ $message }}</small>
                @enderror
            </div>

            <!-- City / Location -->
            <div class="mb-3">
                <label class="form-label small">City / Location</label>
                <input
                type="text"
                class="form-control form-control-sm"
                wire:model.defer="locationName"
                placeholder="e.g. Lagos"
                >

                @error('locationName')
                <small class="text-danger">{{ $message }}</small>
                @enderror
            </div>

            </form>
        </div>

        <div class="modal-footer">
            <button
            type="button"
            class="btn btn-outline-secondary rounded-pill"
            data-bs-dismiss="modal"
            >
            Cancel
            </button>

            <button
            type="button"
            class="btn btn-primary rounded-pill"
            wire:click="saveLocation"
            wire:loading.attr="disabled"
            >
            Save location
            </button>
        </div>

        </div>
    </div>
    </div>

</div>
