<?php

use App\Models\Ministry;
use App\Models\Sbo;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Ministries – Admin Dashboard',
        'dataPage' => 'ministries',
        'pageTitle' => 'Ministries',
    ]
)] class extends Component {
    public string $name = '';

    public ?int $editingMinistryId = null;
    public string $editingName = '';

    public function mount(): void
    {
        if (! auth()->user()?->hasPermission(\App\Support\AdminCapabilities::MANAGE_CATEGORIES)) {
            abort(403);
        }
    }

    // CREATE
    public function saveMinistry(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:ministries,name',
        ]);

        Ministry::create([
            'name' => $this->name,
            'slug' => Ministry::uniqueSlug($this->name),
            'is_active' => true,
        ]);

        \App\Support\Audit::log('ministry_created', $this->name, 'Ministry created', 'created');

        $this->reset('name');
        $this->dispatch('close-add-ministry-modal');

        session()->flash('success', 'Ministry added successfully.');
    }

    public function startRename(int $ministryId): void
    {
        $ministry = Ministry::findOrFail($ministryId);

        $this->editingMinistryId = $ministry->id;
        $this->editingName = $ministry->name;

        $this->dispatch('open-rename-ministry-modal');
    }

    // UPDATE
    public function renameMinistry(): void
    {
        $this->validate([
            'editingName' => 'required|string|max:255|unique:ministries,name,' . $this->editingMinistryId,
        ]);

        $ministry = Ministry::findOrFail($this->editingMinistryId);

        $ministry->update([
            'name' => $this->editingName,
            'slug' => Ministry::uniqueSlug($this->editingName, $ministry->id),
        ]);

        \App\Support\Audit::log('ministry_renamed', $this->editingName, 'Ministry renamed');

        $this->reset(['editingMinistryId', 'editingName']);
        $this->dispatch('close-rename-ministry-modal');
    }

    // Toggle active — a ministry can be retired without deleting the history
    // of listings that chose it.
    public function toggleActive(int $ministryId): void
    {
        $ministry = Ministry::findOrFail($ministryId);
        $ministry->update(['is_active' => ! $ministry->is_active]);

        \App\Support\Audit::log(
            'ministry_toggled',
            $ministry->name,
            $ministry->is_active ? 'Ministry activated' : 'Ministry deactivated',
        );
    }

    // DELETE — refused while any listing still names this ministry, so a
    // registration's stored value never points at nothing.
    public function deleteMinistry(int $ministryId): void
    {
        $ministry = Ministry::findOrFail($ministryId);

        $inUse = Sbo::query()->where('ministry', $ministry->name)->count();

        if ($inUse > 0) {
            $this->addError('delete', "Cannot delete a ministry in use by {$inUse} business(es). Deactivate it instead.");

            return;
        }

        $name = $ministry->name;
        $ministry->delete();

        \App\Support\Audit::log('ministry_deleted', $name, 'Ministry deleted', 'deleted');
    }

    public function with(): array
    {
        $ministries = Ministry::query()->orderBy('name')->get();

        return [
            'ministries' => $ministries,
            'totalMinistries' => $ministries->count(),
            'activeMinistries' => $ministries->where('is_active', true)->count(),
        ];
    }
};
?>

<div>
    <section class="mb-5">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h5 mb-1">Ministries</h2>
                <p class="text-muted-soft small mb-0">
                    Manage the ministries an SBO can be affiliated with. Active ministries appear as a dropdown on registration.
                </p>
            </div>
            <button
              class="btn btn-sm btn-primary rounded-pill"
              data-bs-toggle="modal"
              data-bs-target="#addMinistryModal"
            >
                <i class="bi bi-plus-circle me-1"></i>Add ministry
            </button>
        </div>

        {{-- DELETE ERROR --}}
        @if($errors->has('delete'))
            <div class="alert alert-warning alert-dismissible fade show mb-3">
                {{ $errors->first('delete') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
            </div>
        @endif

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show mb-3">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
            </div>
        @endif

        <!-- Stats row -->
        <div class="row g-3 g-md-4 mb-4">
            <div class="col-12 col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <div>
                                <div class="small text-muted-soft">Ministries</div>
                                <div class="h4 mb-0">{{ $totalMinistries }}</div>
                            </div>
                            <div class="icon-circle">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                        <span class="chip">{{ $activeMinistries }} active</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ministries table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Ministries</h5>
            </div>

            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($ministries as $ministry)
                            <tr wire:key="ministry-{{ $ministry->id }}">
                                <td>{{ $ministry->name }}</td>
                                <td>
                                    @if($ministry->is_active)
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button
                                        class="btn btn-sm btn-outline-secondary rounded-pill me-1"
                                        wire:click="toggleActive({{ $ministry->id }})"
                                    >{{ $ministry->is_active ? 'Deactivate' : 'Activate' }}</button>

                                    <button
                                        class="btn btn-sm btn-outline-secondary rounded-pill me-1"
                                        wire:click="startRename({{ $ministry->id }})"
                                    >Rename</button>

                                    <button
                                        class="btn btn-sm btn-outline-danger rounded-pill"
                                        wire:click="deleteMinistry({{ $ministry->id }})"
                                        wire:confirm="Are you sure you want to delete this ministry?"
                                    >Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-muted-soft text-center py-4">
                                    No ministries yet. Add one to offer it at registration.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Add Ministry Modal -->
    <div
    class="modal fade"
    id="addMinistryModal"
    tabindex="-1"
    aria-labelledby="addMinistryModalLabel"
    aria-hidden="true"
    wire:ignore.self
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addMinistryModalLabel">Add Ministry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form wire:submit.prevent="saveMinistry">
                        <div class="mb-3">
                            <label class="form-label small">Ministry name</label>
                            <input type="text" class="form-control form-control-sm" wire:model.defer="name">
                            @error('name')
                                <small class="text-danger">{{ $message }}</small>
                            @enderror
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary rounded-pill" wire:click="saveMinistry">Save ministry</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Rename Ministry Modal -->
    <div
    class="modal fade"
    id="renameMinistryModal"
    tabindex="-1"
    aria-labelledby="renameMinistryModalLabel"
    aria-hidden="true"
    wire:ignore.self
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="renameMinistryModalLabel">Rename Ministry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form wire:submit.prevent="renameMinistry">
                        <div class="mb-3">
                            <label class="form-label small">Ministry name</label>
                            <input type="text" class="form-control form-control-sm" wire:model.defer="editingName">
                            @error('editingName')
                                <small class="text-danger">{{ $message }}</small>
                            @enderror
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary rounded-pill" wire:click="renameMinistry">Save changes</button>
                </div>
            </div>
        </div>
    </div>

</div>
