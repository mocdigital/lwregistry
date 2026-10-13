<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\AdminWelcomeNotification;
use App\Support\AdminPages;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Propaganistas\LaravelPhone\Rules\Phone;

new #[Layout('components.layouts.admin', ['title' => 'Administrators – Admin Dashboard', 'dataPage' => 'admins', 'pageTitle' => 'Administrators'])] class extends Component
{
    use WithPagination;

    /* ========= Add Admin Modal Fields ========= */
    public string $firstname = '';

    public string $lastname = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** @var array<int, string> */
    public array $pagePermissions = [];

    public string $pagePreset = '';

    #[Url(as: 'promote')]
    public ?int $promoteExistingUserId = null;

    public string $promoteSearch = '';

    /** @var array<int, string> */
    public array $promotePagePermissions = [];

    public string $promotePagePreset = '';

    /* ========= Edit permissions ========= */
    public ?int $editingAdminId = null;

    /** @var array<int, string> */
    public array $editPagePermissions = [];

    public string $editPagePreset = '';

    protected string $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasAdminPage(AdminPages::ADMINS), 403);
    }

    public function applyPagePreset(): void
    {
        $this->authorizePermissionManagement();

        $presets = AdminPages::presets();
        $this->pagePermissions = $presets[$this->pagePreset]['pages'] ?? [];
    }

    public function applyPromotePagePreset(): void
    {
        $this->authorizePermissionManagement();

        $presets = AdminPages::presets();
        $this->promotePagePermissions = $presets[$this->promotePagePreset]['pages'] ?? [];
    }

    public function applyEditPagePreset(): void
    {
        $this->authorizePermissionManagement();

        $presets = AdminPages::presets();
        $this->editPagePermissions = $presets[$this->editPagePreset]['pages'] ?? [];
    }

    public function editPageAccess(int $adminId): void
    {
        $this->authorizePermissionManagement();

        $admin = User::findOrFail($adminId);
        $this->editingAdminId = $admin->id;
        $this->editPagePermissions = $admin->admin_page_permissions
            ?? AdminPages::fromLegacyCapabilities($admin->permissions ?? [], includeDefaults: true);
        $this->editPagePreset = '';
        $this->dispatch('open-edit-perms-modal');
    }

    public function savePageAccess(): void
    {
        $this->authorizePermissionManagement();

        if ( ! Schema::hasColumn('users', 'admin_page_permissions')) {
            $message = 'Backend page access cannot be saved until the admin page permissions migration has run.';

            $this->addError('editPagePermissions', $message);
            $this->dispatch('page-access-save-failed', message: $message);

            return;
        }

        $admin = User::findOrFail($this->editingAdminId);

        // Never edit a super admin's page access (they implicitly have all).
        if ($admin->isSuperAdmin()) {
            $this->dispatch('close-edit-perms-modal');

            return;
        }

        $this->validate([
            'editPagePermissions' => 'array',
            'editPagePermissions.*' => 'string|in:' . implode(',', AdminPages::keys()),
        ]);

        $validPages = AdminPages::normalize($this->editPagePermissions);
        $admin->update([
            'admin_page_permissions' => $validPages,
            'permissions' => AdminPages::capabilitiesForPages($validPages),
        ]);

        $message = 'Page access updated for ' . $admin->name . '.';

        $this->dispatch('close-edit-perms-modal');
        $this->dispatch('page-access-saved', message: $message);
        session()->flash('success', $message);
    }

    public function createAdmin(): void
    {
        $this->authorizePermissionManagement();

        $this->validate();
        $plainPassword = $this->password;

        $validPages = AdminPages::normalize($this->pagePermissions);

        $admin = User::create([
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => UserRole::ADMIN,
            'permissions' => AdminPages::capabilitiesForPages($validPages),
            'admin_page_permissions' => $validPages,
            'email_verified_at' => now(),
            'password' => Hash::make($this->password),
        ]);

        // 📧 Send welcome email
        $admin->notify(new AdminWelcomeNotification($plainPassword));

        $this->reset([
            'firstname',
            'lastname',
            'email',
            'phone',
            'password',
            'password_confirmation',
            'pagePermissions',
            'pagePreset',
        ]);

        $this->dispatch('close-add-admin-modal');

        session()->flash('success', 'Administrator created successfully.');
    }

    public function promoteExistingUser(): void
    {
        $this->authorizePermissionManagement();

        $this->validate([
            'promoteExistingUserId' => 'required|exists:users,id',
            'promotePagePermissions' => 'array|min:1',
            'promotePagePermissions.*' => 'string|in:' . implode(',', AdminPages::keys()),
        ], [
            'promoteExistingUserId.required' => 'Select an existing user to promote.',
            'promotePagePermissions.min' => 'Select at least one backend page.',
        ]);

        $user = User::findOrFail($this->promoteExistingUserId);

        if ($user->role->isAdmin()) {
            $this->addError('promoteExistingUserId', 'This user is already an administrator.');

            return;
        }

        $validPages = AdminPages::normalize($this->promotePagePermissions);

        $user->update([
            'role' => UserRole::ADMIN,
            'permissions' => AdminPages::capabilitiesForPages($validPages),
            'admin_page_permissions' => $validPages,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $this->reset(['promoteExistingUserId', 'promoteSearch', 'promotePagePermissions', 'promotePagePreset']);

        $this->dispatch('close-add-admin-modal');

        session()->flash('success', $user->name . ' has been promoted to administrator.');
    }

    public function clearPromoteSearch(): void
    {
        $this->reset(['promoteSearch', 'promoteExistingUserId']);
    }

    public function with(): array
    {
        $promoteSearch = mb_trim($this->promoteSearch);

        $promoteCandidates = User::query()
            ->whereNotIn('role', [UserRole::ADMIN, UserRole::SUPER_ADMIN])
            ->when('' !== $promoteSearch, fn ($query) => $query->where(function ($q) use ($promoteSearch): void {
                $term = "%{$promoteSearch}%";

                $q->where('firstname', 'like', $term)
                    ->orWhere('lastname', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            }))
            ->withCount('sbos')
            ->latest()
            ->limit(30)
            ->get();

        if ($this->promoteExistingUserId && ! $promoteCandidates->contains('id', $this->promoteExistingUserId)) {
            $selectedPromoteUser = User::query()
                ->whereKey($this->promoteExistingUserId)
                ->whereNotIn('role', [UserRole::ADMIN, UserRole::SUPER_ADMIN])
                ->withCount('sbos')
                ->first();

            if ($selectedPromoteUser) {
                $promoteCandidates->prepend($selectedPromoteUser);
            }
        }

        return [
            'admins' => User::whereIn('role', [
                UserRole::SUPER_ADMIN,
                UserRole::ADMIN,
            ])
                ->latest()
                ->paginate(10),
            'pageGroups' => AdminPages::grouped(),
            'pagePresets' => AdminPages::presets(),
            'promoteCandidates' => $promoteCandidates,
        ];
    }

    protected function rules(): array
    {
        return [
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => ['required', (new Phone())->international()],
            'password' => 'required|string|min:8|confirmed',
            'pagePermissions' => 'array|min:1',
            'pagePermissions.*' => 'string|in:' . implode(',', AdminPages::keys()),
        ];
    }

    protected function messages(): array
    {
        return [
            'email.unique' => 'This email already belongs to an existing user. Use Promote existing user instead of creating a duplicate admin account.',
            'pagePermissions.min' => 'Select at least one backend page.',
            'promotePagePermissions.min' => 'Select at least one backend page.',
            'phone.phone' => 'Enter a valid international phone number, for example +2348012345678.',
        ];
    }

    private function authorizePermissionManagement(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }
};
?>

<div>

<!-- ================= ADMINS ================= -->
<section id="admins" class="mb-2">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h2 class="h5 mb-1">Administrators</h2>
      <p class="text-muted-soft small mb-0">
        Manage admin accounts for Loveworld Registry.
      </p>
    </div>

    @if(auth()->user()->isSuperAdmin())
      <button
        class="btn btn-sm btn-primary rounded-pill"
        data-bs-toggle="modal"
        data-bs-target="#addAdminModal"
      >
        <i class="bi bi-person-plus me-1"></i>Add admin
      </button>
    @endif
  </div>

  <div class="row g-3 g-md-4">
    <!-- Admin Table -->
    <div class="col-12 col-lg-7">
      <div class="card h-100">
        <div class="card-header">
          <h5 class="card-title mb-0">Admin Users</h5>
        </div>

        <div class="card-body p-0">
          <table class="table mb-0 align-middle">
            <thead class="bg-light">
              <tr>
                <th>Admin</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th class="text-end">Page Access</th>
              </tr>
            </thead>

            <tbody>
              @forelse($admins as $admin)
              @php
                $adminPagePermissions = $admin->admin_page_permissions
                    ?? \App\Support\AdminPages::fromLegacyCapabilities($admin->permissions ?? [], includeDefaults: true);
              @endphp
              <tr wire:key="admin-{{ $admin->id }}">
                <td>
                  <div>
                    <div class="fw-semibold">
                      {{ $admin->firstname }} {{ $admin->lastname }}
                    </div>
                    <div class="small text-muted-soft">
                      Joined {{ $admin->created_at->format('M Y') }}
                    </div>
                  </div>
                </td>

                <td>{{ $admin->email }}</td>

                <td>
                  @if($admin->role->isSuperAdmin())
                    <span class="badge rounded-pill bg-dark text-white">Super Admin</span>
                  @else
                    <span class="badge rounded-pill bg-light text-dark">
                      {{ \App\Support\AdminPages::typeLabel($adminPagePermissions) }}
                    </span>
                    <div class="small text-muted-soft">Admin</div>
                  @endif
                </td>

                <td>
                  <span class="badge bg-success-subtle text-success border border-success-subtle">
                    Active
                  </span>
                </td>

                <td class="text-end">
                  @if($admin->isSuperAdmin())
                    <span class="small text-muted-soft">All access</span>
                  @else
                    <span class="small text-muted-soft me-2">{{ count($adminPagePermissions) }} pages</span>
                    @if(auth()->user()->isSuperAdmin())
                      <button class="btn btn-sm btn-outline-primary rounded-pill"
                              wire:click="editPageAccess({{ $admin->id }})">
                        Edit
                      </button>
                    @endif
                  @endif
                </td>
              </tr>
              @empty
              <tr>
                <td colspan="5" class="text-center text-muted-soft py-4">
                  No administrators found.
                </td>
              </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        @if($admins->hasPages())
        <div class="card-footer bg-white">
          {{ $admins->links() }}
        </div>
        @endif
      </div>
    </div>

	    <!-- Page Access Info -->
	    <div class="col-12 col-lg-5">
	      <div class="card h-100">
	        <div class="card-header">
	          <h5 class="card-title mb-0">Backend Page Access</h5>
        </div>

        <div class="card-body">
          <p class="small text-muted-soft">
            Tick the backend pages each administrator can open. Super admins always keep full access.
          </p>

          <div class="accordion" id="adminPageAccessOverview">
            @foreach($pageGroups as $group => $pages)
              <div class="accordion-item">
                <h2 class="accordion-header" id="page-group-heading-{{ \Illuminate\Support\Str::slug($group) }}">
                  <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#page-group-{{ \Illuminate\Support\Str::slug($group) }}" aria-expanded="false" aria-controls="page-group-{{ \Illuminate\Support\Str::slug($group) }}">
                    {{ $group }}
                  </button>
                </h2>
                <div id="page-group-{{ \Illuminate\Support\Str::slug($group) }}" class="accordion-collapse collapse" aria-labelledby="page-group-heading-{{ \Illuminate\Support\Str::slug($group) }}" data-bs-parent="#adminPageAccessOverview">
                  <div class="accordion-body py-2">
                    <ul class="list-unstyled small mb-0">
                      @foreach($pages as $page)
                        <li class="py-1">{{ $page['label'] }}</li>
                      @endforeach
                    </ul>
                  </div>
                </div>
              </div>
            @endforeach
          </div>

	        <p class="small text-muted-soft mb-0 mt-3">Page access controls sidebar visibility and direct backend route access.</p>
	        </div>
	      </div>
	    </div>
	  </div>

	</section>

@if(auth()->user()->isSuperAdmin())
<!-- ============ ADD ADMIN MODAL ============ -->
<div
  class="modal fade"
  id="addAdminModal"
  tabindex="-1"
  aria-labelledby="addAdminModalLabel"
  aria-hidden="true"
  wire:ignore.self
>
	  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
	    <div class="modal-content">

	      <div class="modal-header">
	        <h5 class="modal-title" id="addAdminModalLabel">Add Administrator</h5>
	        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
	      </div>

	      <div class="modal-body">
          <ul class="nav nav-tabs mb-4" id="addAdminTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button
                class="nav-link active"
                id="create-admin-tab"
                data-bs-toggle="tab"
                data-bs-target="#create-admin-pane"
                type="button"
                role="tab"
                aria-controls="create-admin-pane"
                aria-selected="true"
              >
                Create New Admin
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button
                class="nav-link"
                id="promote-admin-tab"
                data-bs-toggle="tab"
                data-bs-target="#promote-admin-pane"
                type="button"
                role="tab"
                aria-controls="promote-admin-pane"
                aria-selected="false"
              >
                Promote Existing User
              </button>
            </li>
          </ul>

          <div class="tab-content" id="addAdminTabsContent">
            <div
              class="tab-pane fade show active"
              id="create-admin-pane"
              role="tabpanel"
              aria-labelledby="create-admin-tab"
              tabindex="0"
            >
              <form wire:submit.prevent="createAdmin">
      	        <div class="row mb-3">
      	          <div class="col-12 col-md-6">
      	            <label class="form-label">First name</label>
      	            <input type="text" wire:model.defer="firstname" class="form-control">
      	            @error('firstname') <small class="text-danger">{{ $message }}</small> @enderror
      	          </div>
      	          <div class="col-12 col-md-6">
      	            <label class="form-label">Last name</label>
      	            <input type="text" wire:model.defer="lastname" class="form-control">
      	            @error('lastname') <small class="text-danger">{{ $message }}</small> @enderror
      	          </div>
      	        </div>

      	        <div class="row mb-3">
      	          <div class="col-12 col-md-6">
      	            <label class="form-label">Email</label>
      	            <input type="email" wire:model.defer="email" class="form-control">
      	            @error('email') <small class="text-danger">{{ $message }}</small> @enderror
      	          </div>
      	          <div class="col-12 col-md-6">
      	            <label class="form-label">Phone number</label>
      	            <input type="text" wire:model.defer="phone" class="form-control" placeholder="+2348012345678">
      	            @error('phone') <small class="text-danger">{{ $message }}</small> @enderror
      	          </div>
      	        </div>

      	        <div class="row mb-3">
      	          <div class="col-12 col-md-6">
      	            <label class="form-label">Password</label>
      	            <input type="password" wire:model.defer="password" class="form-control">
      	            @error('password') <small class="text-danger">{{ $message }}</small> @enderror
      	          </div>
      	          <div class="col-12 col-md-6">
      	            <label class="form-label">Confirm password</label>
      	            <input type="password" wire:model.defer="password_confirmation" class="form-control">
      	          </div>
      	        </div>

                <div class="mb-3">
                  <label class="form-label">Quick page template</label>
                  <select class="form-select" wire:model="pagePreset" wire:change="applyPagePreset">
                    <option value="">Custom (choose below)</option>
                    @foreach($pagePresets as $key => $p)
                      <option value="{{ $key }}">{{ $p['label'] }}</option>
                    @endforeach
                  </select>
                  <small class="text-muted-soft">Templates pre-select backend pages; you can still adjust them.</small>
                </div>

                <div class="mb-2">
                  <label class="form-label">Backend pages</label>
                  @error('pagePermissions') <div class="text-danger small mb-1">{{ $message }}</div> @enderror
                  @foreach($pageGroups as $group => $pages)
                    <div class="mb-2">
                      <div class="small fw-semibold text-muted-soft">{{ $group }}</div>
                      @foreach($pages as $pageKey => $page)
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" value="{{ $pageKey }}"
                                 wire:model="pagePermissions" id="page-{{ $pageKey }}">
                          <label class="form-check-label small" for="page-{{ $pageKey }}">{{ $page['label'] }}</label>
                        </div>
                      @endforeach
                    </div>
                  @endforeach
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                  <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                    Cancel
                  </button>
                  <button type="submit" class="btn btn-primary">
                    Create Admin
                  </button>
                </div>
              </form>
            </div>

            <div
              class="tab-pane fade"
              id="promote-admin-pane"
              role="tabpanel"
              aria-labelledby="promote-admin-tab"
              tabindex="0"
            >
              <form wire:submit.prevent="promoteExistingUser">
                <p class="small text-muted-soft">
                  Promote an existing user or SBO owner to administrator without creating a duplicate account. Their SBOs, listings, bids, and profile remain attached to the same user.
                </p>

                <div class="row g-3">
                  <div class="col-12 col-lg-5">
                    <x-live-search
                        model="promoteSearch"
                        :value="$promoteSearch"
                        label="Search users"
                        placeholder="Name or email"
                        clear-action="clearPromoteSearch"
                        loading-target="promoteSearch,clearPromoteSearch"
                        as="div"
                        min-width="100%"
                        class="mb-3" />

                    <label class="form-label mt-3">User</label>
                    <select class="form-select" wire:model="promoteExistingUserId" wire:loading.class.delay="opacity-50" wire:target="promoteSearch,clearPromoteSearch">
                      <option value="">Select a user…</option>
                      @foreach($promoteCandidates as $candidate)
                        <option value="{{ $candidate->id }}">
                          {{ $candidate->name }} — {{ $candidate->email }}{{ $candidate->sbos_count ? ' · '.$candidate->sbos_count.' SBOs' : '' }}
                        </option>
                      @endforeach
                    </select>
                    @error('promoteExistingUserId') <small class="text-danger">{{ $message }}</small> @enderror
                  </div>

                  <div class="col-12 col-lg-7">
                    <label class="form-label">Quick page template</label>
                    <select class="form-select" wire:model="promotePagePreset" wire:change="applyPromotePagePreset">
                      <option value="">Custom (choose below)</option>
                      @foreach($pagePresets as $key => $p)
                        <option value="{{ $key }}">{{ $p['label'] }}</option>
                      @endforeach
                    </select>

                    <div class="mt-3">
                      <label class="form-label">Backend pages</label>
                      @error('promotePagePermissions') <div class="text-danger small mb-1">{{ $message }}</div> @enderror
                      <div class="row">
                        @foreach($pageGroups as $group => $pages)
                          <div class="col-12 col-md-6 mb-2">
                            <div class="small fw-semibold text-muted-soft">{{ $group }}</div>
                            @foreach($pages as $pageKey => $page)
                              <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="{{ $pageKey }}"
                                       wire:model="promotePagePermissions" id="promote-page-{{ $pageKey }}">
                                <label class="form-check-label small" for="promote-page-{{ $pageKey }}">{{ $page['label'] }}</label>
                              </div>
                            @endforeach
                          </div>
                        @endforeach
                      </div>
                    </div>
                  </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                  <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                    Cancel
                  </button>
                  <button type="submit" class="btn btn-primary">
                    Promote to Admin
                  </button>
                </div>
              </form>
            </div>
          </div>
	      </div>

	    </div>
	  </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1095;" wire:ignore>
  <div id="pageAccessToast" class="toast align-items-center text-bg-success border-0" role="status" aria-live="polite" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" data-page-access-toast-message>
        Page access updated.
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<!-- ============ EDIT PAGE ACCESS MODAL ============ -->
<div class="modal fade" id="editPermsModal" tabindex="-1" aria-labelledby="editPermsModalLabel" aria-hidden="true" wire:ignore.self>
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <form wire:submit.prevent="savePageAccess" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editPermsModalLabel">Edit Backend Page Access</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Apply page template</label>
          <select class="form-select" wire:model="editPagePreset" wire:change="applyEditPagePreset">
            <option value="">Custom</option>
            @foreach($pagePresets as $key => $p)
              <option value="{{ $key }}">{{ $p['label'] }}</option>
            @endforeach
          </select>
        </div>
        @error('editPagePermissions') <div class="text-danger small mb-1">{{ $message }}</div> @enderror
        <p class="small text-muted-soft mb-2">
          Clear all pages to revoke backend access from this administrator.
        </p>
        @foreach($pageGroups as $group => $pages)
          <div class="mb-2">
            <div class="small fw-semibold text-muted-soft">{{ $group }}</div>
            @foreach($pages as $pageKey => $page)
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="{{ $pageKey }}"
                       wire:model="editPagePermissions" id="edit-page-{{ $pageKey }}">
                <label class="form-check-label small" for="edit-page-{{ $pageKey }}">{{ $page['label'] }}</label>
              </div>
            @endforeach
          </div>
        @endforeach
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="savePageAccess">
          <span wire:loading.remove wire:target="savePageAccess">Save page access</span>
          <span wire:loading wire:target="savePageAccess">
            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
            Saving...
          </span>
        </button>
      </div>
    </form>
  </div>
</div>
@endif

</div>

@script
	<script>
      const showPromoteAdminTab = () => {
          const modalEl = document.getElementById('addAdminModal');
          const tabEl = document.getElementById('promote-admin-tab');

          if (! modalEl || ! tabEl) {
              return;
          }

          bootstrap.Tab.getOrCreateInstance(tabEl).show();
          bootstrap.Modal.getOrCreateInstance(modalEl).show();
      };

      if (@js((bool) $promoteExistingUserId)) {
          requestAnimationFrame(showPromoteAdminTab);
      }

	    window.addEventListener('close-add-admin-modal', () => {
	        const el = document.getElementById('addAdminModal');
	        const m = el ? bootstrap.Modal.getInstance(el) : null; if (m) m.hide();
    });
    window.addEventListener('open-edit-perms-modal', () => {
        const el = document.getElementById('editPermsModal');
        if (! el) return;

        bootstrap.Modal.getOrCreateInstance(el).show();
    });
    window.addEventListener('close-edit-perms-modal', () => {
        const el = document.getElementById('editPermsModal');
        const m = el ? bootstrap.Modal.getOrCreateInstance(el) : null; if (m) m.hide();
    });
    window.addEventListener('page-access-saved', (event) => {
        const toastEl = document.getElementById('pageAccessToast');
        const messageEl = document.querySelector('[data-page-access-toast-message]');

        if (! toastEl) return;

        if (messageEl) {
            messageEl.textContent = event.detail?.message || 'Page access updated.';
        }

        toastEl.classList.remove('text-bg-danger');
        toastEl.classList.add('text-bg-success');
        bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 3500 }).show();
    });
    window.addEventListener('page-access-save-failed', (event) => {
        const toastEl = document.getElementById('pageAccessToast');
        const messageEl = document.querySelector('[data-page-access-toast-message]');

        if (! toastEl) return;

        if (messageEl) {
            messageEl.textContent = event.detail?.message || 'Page access could not be saved.';
        }

        toastEl.classList.remove('text-bg-success');
        toastEl.classList.add('text-bg-danger');
        bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 3500 }).show();
    });
</script>
@endscript
