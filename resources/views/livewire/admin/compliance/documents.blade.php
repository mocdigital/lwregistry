<?php

use App\Models\ActivityLog;
use App\Models\ComplianceDocumentType;
use App\Models\Country;
use App\Support\AdminCapabilities;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Required Documents – Admin Dashboard',
        'dataPage' => 'compliance-documents',
        'pageTitle' => 'Required Compliance Documents',
    ]
)] class extends Component {

    public string $name = '';
    public ?string $description = null;
    public string $countryId = '';
    public ?string $registryAuthority = null;
    public ?string $sourceUrl = null;
    public ?string $sourceNote = null;
    public bool $isRequired = true;
    public string $countryFilter = 'all';
    public string $seedFilter = 'all';
    public string $reviewFilter = 'all';

    public ?int $editingId = null;
    public string $editName = '';
    public ?string $editDescription = null;
    public string $editCountryId = '';
    public ?string $editRegistryAuthority = null;
    public ?string $editSourceUrl = null;
    public ?string $editSourceNote = null;
    public bool $editRequired = true;
    public bool $editActive = true;
    public bool $editNeedsAdminReview = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission(AdminCapabilities::APPROVE_SBOS), 403);
    }

    public function create(): void
    {
        abort_unless(auth()->user()?->hasPermission(AdminCapabilities::APPROVE_SBOS), 403);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule($this->countryId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'countryId' => ['nullable', 'exists:countries,id'],
            'registryAuthority' => ['nullable', 'string', 'max:255'],
            'sourceUrl' => ['nullable', 'url', 'max:255'],
            'sourceNote' => ['nullable', 'string', 'max:1000'],
            'isRequired' => ['boolean'],
        ]);

        $countryId = $this->normalizedCountryId($data['countryId'] ?? '');

        $type = ComplianceDocumentType::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'country_id' => $countryId,
            'registry_authority' => $data['registryAuthority'] ?? null,
            'source_url' => $data['sourceUrl'] ?? null,
            'source_note' => $data['sourceNote'] ?? null,
            'is_required' => $this->isRequired,
            'is_active' => true,
            'sort_order' => (int) ComplianceDocumentType::max('sort_order') + 1,
            'is_seeded' => false,
            'needs_admin_review' => false,
        ]);

        ActivityLog::create([
            'type' => 'compliance_document_type_created',
            'title' => $type->name,
            'description' => 'Required document type <strong>' . e($type->name) . '</strong> added by <strong>' . e(auth()->user()->name ?? 'admin') . '</strong>.',
            'status' => 'approved',
            'user_id' => auth()->id(),
        ]);

        $this->reset(['name', 'description', 'countryId', 'registryAuthority', 'sourceUrl', 'sourceNote', 'isRequired']);
        $this->isRequired = true;
        session()->flash('success', 'Document type added.');
    }

    public function edit(int $id): void
    {
        $type = ComplianceDocumentType::findOrFail($id);
        $this->editingId = $type->id;
        $this->editName = $type->name;
        $this->editDescription = $type->description;
        $this->editCountryId = $type->country_id ? (string) $type->country_id : '';
        $this->editRegistryAuthority = $type->registry_authority;
        $this->editSourceUrl = $type->source_url;
        $this->editSourceNote = $type->source_note;
        $this->editRequired = $type->is_required;
        $this->editActive = $type->is_active;
        $this->editNeedsAdminReview = $type->needs_admin_review;
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingId',
            'editName',
            'editDescription',
            'editCountryId',
            'editRegistryAuthority',
            'editSourceUrl',
            'editSourceNote',
            'editRequired',
            'editActive',
            'editNeedsAdminReview',
        ]);
    }

    public function update(): void
    {
        abort_unless(auth()->user()?->hasPermission(AdminCapabilities::APPROVE_SBOS), 403);

        $type = ComplianceDocumentType::findOrFail($this->editingId);

        $data = $this->validate([
            'editName' => ['required', 'string', 'max:255', $this->uniqueNameRule($this->editCountryId, $type->id)],
            'editDescription' => ['nullable', 'string', 'max:1000'],
            'editCountryId' => ['nullable', 'exists:countries,id'],
            'editRegistryAuthority' => ['nullable', 'string', 'max:255'],
            'editSourceUrl' => ['nullable', 'url', 'max:255'],
            'editSourceNote' => ['nullable', 'string', 'max:1000'],
            'editRequired' => ['boolean'],
            'editActive' => ['boolean'],
            'editNeedsAdminReview' => ['boolean'],
        ]);

        $type->update([
            'name' => $data['editName'],
            'description' => $data['editDescription'] ?? null,
            'country_id' => $this->normalizedCountryId($data['editCountryId'] ?? ''),
            'registry_authority' => $data['editRegistryAuthority'] ?? null,
            'source_url' => $data['editSourceUrl'] ?? null,
            'source_note' => $data['editSourceNote'] ?? null,
            'is_required' => $this->editRequired,
            'is_active' => $this->editActive,
            'needs_admin_review' => $this->editNeedsAdminReview,
        ]);

        $this->cancelEdit();
        session()->flash('success', 'Document type updated.');
    }

    public function delete(int $id): void
    {
        abort_unless(auth()->user()?->hasPermission(AdminCapabilities::APPROVE_SBOS), 403);

        $type = ComplianceDocumentType::findOrFail($id);
        $name = $type->name;
        $type->delete();

        ActivityLog::create([
            'type' => 'compliance_document_type_deleted',
            'title' => $name,
            'description' => 'Required document type <strong>' . e($name) . '</strong> removed by <strong>' . e(auth()->user()->name ?? 'admin') . '</strong>.',
            'status' => 'declined',
            'user_id' => auth()->id(),
        ]);

        session()->flash('success', 'Document type removed.');
    }

    public function with(): array
    {
        $types = ComplianceDocumentType::query()
            ->with('country')
            ->withCount('documents');

        if ($this->countryFilter === 'global') {
            $types->whereNull('country_id');
        } elseif (ctype_digit($this->countryFilter)) {
            $types->where('country_id', (int) $this->countryFilter);
        }

        if ($this->seedFilter === 'seeded') {
            $types->where('is_seeded', true);
        } elseif ($this->seedFilter === 'manual') {
            $types->where('is_seeded', false);
        }

        if ($this->reviewFilter === 'needs_review') {
            $types->where('needs_admin_review', true);
        } elseif ($this->reviewFilter === 'reviewed') {
            $types->where('needs_admin_review', false);
        }

        return [
            'countries' => Country::query()->get(),
            'types' => $types->get(),
        ];
    }

    private function uniqueNameRule(string $countryId, ?int $ignoreId = null): Unique
    {
        $countryId = $this->normalizedCountryId($countryId);

        $rule = Rule::unique('compliance_document_types', 'name')
            ->where(fn ($query) => $countryId === null
                ? $query->whereNull('country_id')
                : $query->where('country_id', $countryId));

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }

    private function normalizedCountryId(?string $countryId): ?int
    {
        return filled($countryId) ? (int) $countryId : null;
    }
}; ?>

<div>
    <div class="mb-4">
        <p class="text-muted small mb-0">
            Define global documents for every SBO or country-specific documents based on the SBO's
            business country. Active items appear as upload fields on each SBO owner's dashboard.
            Required items must be approved before an SBO is marked
            <strong>Documents compliant</strong>.
        </p>
    </div>

    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <div class="row g-4">
        {{-- Add new --}}
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Add a document type</h6>
                    <form wire:submit.prevent="create">
                        <div class="mb-3">
                            <label class="form-label small">Name</label>
                            <input type="text" wire:model="name" class="form-control" placeholder="e.g. CAC Certificate">
                            @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Guidance for the applicant <span class="text-muted">(optional)</span></label>
                            <textarea wire:model="description" class="form-control" rows="2" placeholder="What this document is / acceptable form"></textarea>
                            @error('description') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Applies to</label>
                            <select wire:model="countryId" class="form-select">
                                <option value="">Global — all countries</option>
                                @foreach($countries as $country)
                                    <option value="{{ $country->id }}">{{ $country->name }}</option>
                                @endforeach
                            </select>
                            @error('countryId') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Registry authority <span class="text-muted">(optional)</span></label>
                            <input type="text" wire:model="registryAuthority" class="form-control" placeholder="e.g. Companies House">
                            @error('registryAuthority') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Source URL <span class="text-muted">(optional)</span></label>
                            <input type="url" wire:model="sourceUrl" class="form-control" placeholder="https://…">
                            @error('sourceUrl') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Source note <span class="text-muted">(optional)</span></label>
                            <textarea wire:model="sourceNote" class="form-control" rows="2" placeholder="Registry/source context for admins"></textarea>
                            @error('sourceNote') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" wire:model="isRequired" id="isRequired">
                            <label class="form-check-label small" for="isRequired">Required for compliance</label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm rounded-pill w-100">Add document type</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- List --}}
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body border-bottom">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small">Filter by country</label>
                            <select wire:model.live="countryFilter" class="form-select form-select-sm">
                                <option value="all">All document requirements</option>
                                <option value="global">Global only</option>
                                @foreach($countries as $country)
                                    <option value="{{ $country->id }}">{{ $country->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Seed status</label>
                            <select wire:model.live="seedFilter" class="form-select form-select-sm">
                                <option value="all">Seeded and manual</option>
                                <option value="seeded">Seeded only</option>
                                <option value="manual">Manual only</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Review status</label>
                            <select wire:model.live="reviewFilter" class="form-select form-select-sm">
                                <option value="all">All review states</option>
                                <option value="needs_review">Needs admin review</option>
                                <option value="reviewed">Reviewed</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Document</th>
                                <th>Country</th>
                                <th>Required</th>
                                <th>Status</th>
                                <th>Submissions</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($types as $type)
                                @if($editingId === $type->id)
                                    <tr>
                                        <td colspan="6">
                                            <div class="row g-2 align-items-start">
                                                <div class="col-md-3">
                                                    <input type="text" wire:model="editName" class="form-control form-control-sm">
                                                    @error('editName') <span class="text-danger small">{{ $message }}</span> @enderror
                                                </div>
                                                <div class="col-md-3">
                                                    <textarea wire:model="editDescription" class="form-control form-control-sm" rows="1" placeholder="Guidance"></textarea>
                                                </div>
                                                <div class="col-md-2">
                                                    <select wire:model="editCountryId" class="form-select form-select-sm">
                                                        <option value="">Global</option>
                                                        @foreach($countries as $country)
                                                            <option value="{{ $country->id }}">{{ $country->name }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('editCountryId') <span class="text-danger small">{{ $message }}</span> @enderror
                                                </div>
                                                <div class="col-md-3">
                                                    <input type="text" wire:model="editRegistryAuthority" class="form-control form-control-sm" placeholder="Registry authority">
                                                    @error('editRegistryAuthority') <span class="text-danger small">{{ $message }}</span> @enderror
                                                </div>
                                                <div class="col-md-3">
                                                    <input type="url" wire:model="editSourceUrl" class="form-control form-control-sm" placeholder="Source URL">
                                                    @error('editSourceUrl') <span class="text-danger small">{{ $message }}</span> @enderror
                                                </div>
                                                <div class="col-md-3">
                                                    <textarea wire:model="editSourceNote" class="form-control form-control-sm" rows="1" placeholder="Source note"></textarea>
                                                    @error('editSourceNote') <span class="text-danger small">{{ $message }}</span> @enderror
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="form-check"><input class="form-check-input" type="checkbox" wire:model="editRequired" id="er{{ $type->id }}"><label class="form-check-label small" for="er{{ $type->id }}">Required</label></div>
                                                    <div class="form-check"><input class="form-check-input" type="checkbox" wire:model="editActive" id="ea{{ $type->id }}"><label class="form-check-label small" for="ea{{ $type->id }}">Active</label></div>
                                                    <div class="form-check"><input class="form-check-input" type="checkbox" wire:model="editNeedsAdminReview" id="nr{{ $type->id }}"><label class="form-check-label small" for="nr{{ $type->id }}">Needs review</label></div>
                                                </div>
                                                <div class="col-md-2 text-end">
                                                    <button type="button" class="btn btn-sm btn-success" wire:click="update">Save</button>
                                                    <button type="button" class="btn btn-sm btn-link text-muted" wire:click="cancelEdit">Cancel</button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @else
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $type->name }}</div>
                                            @if($type->description)<div class="small text-muted">{{ $type->description }}</div>@endif
                                            @if($type->registry_authority)
                                                <div class="small text-muted">Authority: {{ $type->registry_authority }}</div>
                                            @endif
                                            @if($type->source_url)
                                                <a href="{{ $type->source_url }}" target="_blank" rel="noopener" class="small">Source</a>
                                            @endif
                                            @if($type->source_note)
                                                <div class="small text-muted">{{ $type->source_note }}</div>
                                            @endif
                                            <div class="mt-1">
                                                @if($type->is_seeded)
                                                    <span class="badge bg-secondary-subtle text-secondary">Seeded</span>
                                                @endif
                                                @if($type->needs_admin_review)
                                                    <span class="badge bg-warning-subtle text-warning">Needs review</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            @if($type->country)
                                                <span class="badge bg-info-subtle text-info">{{ $type->country->name }}</span>
                                            @else
                                                <span class="badge bg-primary-subtle text-primary">Global</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($type->is_required)
                                                <span class="badge bg-danger-subtle text-danger">Required</span>
                                            @else
                                                <span class="badge bg-secondary-subtle text-secondary">Optional</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($type->is_active)
                                                <span class="badge bg-success-subtle text-success">Active</span>
                                            @else
                                                <span class="badge bg-secondary-subtle text-secondary">Hidden</span>
                                            @endif
                                        </td>
                                        <td>{{ $type->documents_count }}</td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="edit({{ $type->id }})">Edit</button>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                wire:click="delete({{ $type->id }})"
                                                wire:confirm="Delete this document type? Existing submissions for it will also be removed.">
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">No document types defined yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
