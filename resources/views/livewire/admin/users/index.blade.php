<?php

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\PurchaseOrderBid;
use App\Models\Report;
use App\Models\Sbo;
use App\Models\User;
use App\Support\AdminCapabilities;
use App\Support\SboManagerAssigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Propaganistas\LaravelPhone\Rules\Phone;

new #[Layout('components.layouts.admin', ['title' => 'Users – Admin Dashboard', 'dataPage' => 'users', 'pageTitle' => 'Users'])] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public ?User $selectedUser = null;

    public array $userStats = [];

    public $userSbos = [];

    public string $newUserFirstName = '';

    public string $newUserLastName = '';

    public string $newUserEmail = '';

    public string $newUserPhone = '';

    public string $newUserPassword = '';

    public string $newUserPasswordConfirmation = '';

    // Assign this user as an SBO manager
    public ?int $assignSboId = null;

    protected string $paginationTheme = 'bootstrap';

    public function createUser(): void
    {
        $this->validate([
            'newUserFirstName' => ['required', 'string', 'max:255'],
            'newUserLastName' => ['required', 'string', 'max:255'],
            'newUserEmail' => ['required', 'email', 'max:255', 'unique:users,email'],
            'newUserPhone' => ['nullable', (new Phone())->international()],
            'newUserPassword' => ['required', 'string', Password::min(8)->letters()->mixedCase()->numbers()],
            'newUserPasswordConfirmation' => ['required', 'same:newUserPassword'],
        ], [
            'newUserPhone.phone' => 'Enter a valid international phone number, for example +2348012345678.',
            'newUserPasswordConfirmation.same' => 'The passwords do not match.',
        ]);

        $user = User::create([
            'firstname' => $this->newUserFirstName,
            'lastname' => $this->newUserLastName,
            'email' => $this->newUserEmail,
            'phone' => $this->newUserPhone ?: null,
            'password' => Hash::make($this->newUserPassword),
            'role' => UserRole::USER,
            'email_verified_at' => now(),
        ]);

        $this->reset([
            'newUserFirstName',
            'newUserLastName',
            'newUserEmail',
            'newUserPhone',
            'newUserPassword',
            'newUserPasswordConfirmation',
        ]);

        $this->dispatch('close-add-user-modal');
        session()->flash('success', $user->name . ' has been added.');
    }

    public function suspendUser(int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($message = $this->accountActionBlocker($user, 'suspend')) {
            session()->flash('error', $message);

            return;
        }

        if ($user->isSuspended()) {
            session()->flash('error', $user->name . ' is already suspended.');

            return;
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'suspended_at' => now(),
                'suspended_by' => auth()->id(),
                'suspension_reason' => 'Suspended by administrator',
                'remember_token' => Str::random(60),
            ])->save();

            $this->deleteUserSessions($user);
        });

        $this->refreshSelectedUser($user);

        session()->flash('success', $user->name . ' has been suspended.');
    }

    public function reactivateUser(int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($message = $this->accountActionBlocker($user, 'reactivate')) {
            session()->flash('error', $message);

            return;
        }

        if ( ! $user->isSuspended()) {
            session()->flash('error', $user->name . ' is not suspended.');

            return;
        }

        $user->forceFill([
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
        ])->save();

        $this->refreshSelectedUser($user);

        session()->flash('success', $user->name . ' has been reactivated.');
    }

    public function deleteUser(int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($message = $this->accountActionBlocker($user, 'delete')) {
            session()->flash('error', $message);

            return;
        }

        $blockers = $this->deleteBlockersFor($user);

        if ([] !== $blockers) {
            session()->flash('error', 'Cannot delete ' . $user->name . ' yet. Reassign or remove: ' . implode(', ', $blockers) . '.');

            return;
        }

        DB::transaction(function () use ($user): void {
            $this->deleteUserSessions($user);
            $user->favorites()->detach();
            $user->delete();
        });

        if ($this->selectedUser?->id === $userId) {
            $this->selectedUser = null;
            $this->userStats = [];
            $this->userSbos = [];
            $this->assignSboId = null;
            $this->dispatch('close-user-modal');
        }

        session()->flash('success', $user->name . ' has been deleted.');
    }

    public function assignAsManager(): void
    {
        if ( ! $this->selectedUser) {
            return;
        }

        $this->validate([
            'assignSboId' => 'required|exists:sbos,id',
        ], [
            'assignSboId.required' => 'Please select an SBO to assign this user to.',
        ]);

        $sbo = Sbo::findOrFail($this->assignSboId);

        SboManagerAssigner::assign($sbo, $this->selectedUser, auth()->user());

        $this->selectedUser->refresh();
        $this->assignSboId = null;

        session()->flash('success', $this->selectedUser->name . ' is now the manager of ' . $sbo->name . '.');
        $this->dispatch('close-user-modal');
    }

    public function mount(): void
    {
        if ( ! auth()->user()?->hasPermission(AdminCapabilities::MANAGE_USERS)) {
            abort(403);
        }
    }

    // VIEW USER DETAILS
    public function viewUser(int $userId): void
    {
        $user = User::withCount([
            'sbos',
            'reviews',
        ])->findOrFail($userId);

        $approvedSbos = Sbo::where('created_by', $userId)
            ->where('verification_status', 'approved')
            ->count();

        $pendingSbos = Sbo::where('created_by', $userId)
            ->where('verification_status', 'pending')
            ->count();

        $this->selectedUser = $user;

        $this->userStats = [
            'sbos' => $user->sbos_count,
            'approved' => $approvedSbos,
            'pending' => $pendingSbos,
            'reviews' => $user->reviews_count,
        ];

        $this->userSbos = Sbo::query()
            ->where('created_by', $userId)
            ->latest()
            ->get();

        $this->dispatch('open-user-modal');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->reset('search');
        $this->resetPage();
    }

    public function with(): array
    {
        $search = mb_trim($this->search);

        $users = User::query()
            ->when('' !== $search, fn ($query) => $query->where(function ($q) use ($search): void {
                $term = "%{$search}%";

                $q->where('firstname', 'like', $term)
                    ->orWhere('lastname', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            }))
            ->withCount([
                'sbos',
                /*
                 * Split by entity type so each user's businesses are labelled
                 * for what they are — a COB owner read as owning "4 SBOs".
                 * Legacy rows with no entity_type count as SBO, the default.
                 */
                'sbos as sbo_entities_count' => fn ($q) => $q->where(
                    fn ($w) => $w->where('entity_type', 'SBO')->orWhereNull('entity_type'),
                ),
                'sbos as cob_entities_count' => fn ($q) => $q->where('entity_type', 'COB'),
            ])
            ->latest()
            ->paginate(10);

        $totalUsers = User::count();
        $activeUsers = User::whereNotNull('email_verified_at')->whereNull('suspended_at')->count();
        $pendingVerification = User::whereNull('email_verified_at')->count();
        $suspendedUsers = User::whereNotNull('suspended_at')->count();

        return [
            'users' => $users,
            'totalUsers' => $totalUsers,
            'activeUsers' => $activeUsers,
            'pendingVerification' => $pendingVerification,
            'suspendedUsers' => $suspendedUsers,
            'allSbos' => Sbo::orderBy('name')->get(['id', 'name', 'created_by']),
        ];
    }

    private function accountActionBlocker(User $target, string $action): ?string
    {
        $actor = auth()->user();

        if ( ! $actor) {
            return 'You must be signed in to manage users.';
        }

        if ($actor->id === $target->id) {
            return 'You cannot ' . $action . ' your own account.';
        }

        if ($target->role->isAdmin() && ! $actor->isSuperAdmin()) {
            return 'Only super admins can ' . $action . ' administrator accounts.';
        }

        if ($target->isSuperAdmin() && ! User::where('role', UserRole::SUPER_ADMIN->value)->whereKeyNot($target->id)->exists()) {
            return 'You cannot ' . $action . ' the last super admin account.';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function deleteBlockersFor(User $user): array
    {
        $blockers = [];

        $sboCount = $user->sbos()->count();

        if ($sboCount > 0) {
            $blockers[] = $sboCount . ' managed ' . Str::plural('business', $sboCount);
        }

        $businessCount = Business::where('user_id', $user->id)->count();

        if ($businessCount > 0) {
            $blockers[] = $businessCount . ' vendor ' . Str::plural('listing', $businessCount);
        }

        $bidCount = PurchaseOrderBid::where('user_id', $user->id)->count();

        if ($bidCount > 0) {
            $blockers[] = $bidCount . ' procurement ' . Str::plural('bid', $bidCount);
        }

        $reportCount = Report::where('admin_id', $user->id)->count();

        if ($reportCount > 0) {
            $blockers[] = $reportCount . ' admin ' . Str::plural('report', $reportCount);
        }

        return $blockers;
    }

    private function deleteUserSessions(User $user): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }

    private function refreshSelectedUser(User $user): void
    {
        if ($this->selectedUser?->id === $user->id) {
            $this->selectedUser = $user->fresh();
        }
    }
}; ?>

<div class="admin-page admin-users">

<style>
/* ===== User Details Modal ===== */

.user-modal {
    border-radius: 1.25rem;
    overflow: hidden;
}

.user-modal-header {
    background: linear-gradient(135deg, #0f7c92, #1dbf73);
    color: #fff;
    padding: 1.75rem 2rem;
}

.user-avatar-lg {
    width: 60px;
    height: 60px;
    border-radius: 999px;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1.2rem;
    letter-spacing: 0.5px;
}

.user-meta {
    opacity: 0.9;
    font-size: 0.85rem;
}

.stat-pill {
    background: #f8fafc;
    border-radius: 1rem;
    padding: 1.25rem 1rem;
    text-align: center;
    height: 100%;
}

.stat-pill span {
    display: block;
    font-size: 0.75rem;
    color: #6b7280;
    margin-bottom: 0.25rem;
}

.stat-pill strong {
    font-size: 1.5rem;
    font-weight: 600;
}

.stat-success {
    background: #ecfdf5;
    color: #047857;
}

.stat-warning {
    background: #fffbeb;
    color: #92400e;
}

.stat-neutral {
    background: #f1f5f9;
    color: #334155;
}

.business-table thead th {
    font-size: 0.7rem;
    letter-spacing: 0.08em;
    color: #94a3b8;
    text-transform: uppercase;
}

.business-row {
    border-bottom: 1px solid #eef2f7;
}

.badge-success-soft {
    background: #ecfdf5;
    color: #047857;
    padding: 0.35em 0.75em;
    border-radius: 999px;
    font-size: 0.75rem;
}

.badge-warning-soft {
    background: #fffbeb;
    color: #92400e;
    padding: 0.35em 0.75em;
    border-radius: 999px;
    font-size: 0.75rem;
}

.badge-danger-soft {
    background: #fef2f2;
    color: #b91c1c;
    padding: 0.35em 0.75em;
    border-radius: 999px;
    font-size: 0.75rem;
}
</style>

    <section id="users" class="mb-5">
        <x-ui.page-header
            title="Users"
            eyebrow="Account operations"
            description="Manage registry accounts, access status, and the businesses assigned to each user."
        >
            <x-slot:meta>
                <span><strong>{{ number_format($totalUsers) }}</strong> total users</span>
                <span>{{ number_format($activeUsers) }} active</span>
            </x-slot:meta>
            <x-slot:actions>
                <x-live-search
                    model="search"
                    :value="$search"
                    label="Search users"
                    placeholder="Search users..."
                    class="mb-0" />
                <button type="button" class="btn btn-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add user
                </button>
            </x-slot:actions>
        </x-ui.page-header>
        <x-ui.flash />

        <!-- Stats cards -->
        <div class="row g-3 g-md-4 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="small text-muted-soft">Total users</div>
                                <div class="h4 mb-0">{{ number_format($totalUsers) }}</div>
                            </div>
                            <div class="icon-circle">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                        <div class="stat-trend text-muted-soft d-flex align-items-center gap-1 mt-2">
                            <span> All Users </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="small text-muted-soft">Active users</div>
                                <div class="h4 mb-0">{{ number_format($activeUsers) }}</div>
                            </div>
                            <div class="icon-circle">
                                <i class="bi bi-check-circle"></i>
                            </div>
                        </div>
                        <div class="stat-trend text-muted-soft d-flex align-items-center gap-1 mt-2">
                            <span>{{ $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100) : 0 }}% of total</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="small text-muted-soft">Pending Email</div>
                                <div class="h4 mb-0">{{ number_format($pendingVerification) }}</div>
                            </div>
                            <div class="icon-circle">
                                <i class="bi bi-envelope"></i>
                            </div>
                        </div>
                        <div class="stat-trend text-muted-soft d-flex align-items-center gap-1 mt-2">
                            <span> Unverified Emails </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="small text-muted-soft">Suspended</div>
                                <div class="h4 mb-0">{{ number_format($suspendedUsers) }}</div>
                            </div>
                            <div class="icon-circle">
                                <i class="bi bi-person-lock"></i>
                            </div>
                        </div>
                        <div class="stat-trend text-muted-soft d-flex align-items-center gap-1 mt-2">
                            <span>Blocked accounts</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Users table -->
        <div class="card" wire:loading.class.delay="opacity-50" wire:target="search,clearSearch,gotoPage,nextPage,previousPage">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Businesses</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($users as $user)
                                <tr wire:key="user-{{ $user->id }}">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <x-user-avatar :user="$user" class="table-avatar" />
                                            <div>
                                                <button
                                                    class="btn btn-link p-0 fw-semibold text-start"
                                                    wire:click="viewUser({{ $user->id }})"
                                                    style="text-decoration: none;color:#333"
                                                
                                                >
                                                    {{ $user->firstname }} {{ $user->lastname }}
                                                </button>
                                                <div class="small text-muted-soft">{{ $user->email }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $user->email }}</td>
                                    <td>
                                        @php
                                            $sboN = (int) $user->sbo_entities_count;
                                            $cobN = (int) $user->cob_entities_count;
                                        @endphp
                                        @if($sboN && $cobN)
                                            <span class="badge bg-light text-dark">{{ $sboN }} {{ Str::plural('SBO', $sboN) }}</span>
                                            <span class="badge bg-light text-dark ms-1">{{ $cobN }} {{ Str::plural('COB', $cobN) }}</span>
                                        @elseif($cobN)
                                            <span class="badge bg-light text-dark">{{ $cobN }} {{ Str::plural('COB', $cobN) }}</span>
                                        @elseif($sboN)
                                            <span class="badge bg-light text-dark">{{ $sboN }} {{ Str::plural('SBO', $sboN) }}</span>
                                        @else
                                            <span class="badge bg-light text-dark">0</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($user->isSuspended())
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                                Suspended
                                            </span>
                                        @elseif($user->email_verified_at)
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                Active
                                            </span>
                                        @else
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                                Pending email
                                            </span>
                                        @endif
                                    </td>
                                    <td>{{ $user->created_at->format('d M Y') }}</td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group" aria-label="User actions">
                                            <button
                                                type="button"
                                                class="btn btn-outline-secondary"
                                                wire:click="viewUser({{ $user->id }})"
                                            >
                                                View
                                            </button>
                                            @if($user->isSuspended())
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-success"
                                                    wire:click="reactivateUser({{ $user->id }})"
                                                    wire:confirm="Reactivate this user?"
                                                >
                                                    Reactivate
                                                </button>
                                            @else
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-warning"
                                                    wire:click="suspendUser({{ $user->id }})"
                                                    wire:confirm="Suspend this user? They will be signed out and blocked from the platform."
                                                >
                                                    Suspend
                                                </button>
                                            @endif
                                            <button
                                                type="button"
                                                class="btn btn-outline-danger"
                                                wire:click="deleteUser({{ $user->id }})"
                                                wire:confirm="Delete this user? This cannot be undone. Accounts with active dependencies will be blocked."
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted-soft py-4">
                                        No users found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($users->hasPages())
                    <div class="card-footer bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="small text-muted-soft">
                                Showing {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ number_format($users->total()) }} users
                            </div>
                            <div>
                                {{ $users->links() }}
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>

<!-- Add User Modal -->
<div
  class="modal fade"
  id="addUserModal"
  tabindex="-1"
  aria-labelledby="addUserModalLabel"
  aria-hidden="true"
  wire:ignore.self
>
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
        <form wire:submit.prevent="createUser" data-ui-form>
            <div class="modal-header">
                <h5 class="modal-title" id="addUserModalLabel">Add User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <x-ui.form-errors />
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label">First name</label>
                        <input type="text" class="form-control" wire:model.defer="newUserFirstName">
                        @error('newUserFirstName') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">Last name</label>
                        <input type="text" class="form-control" wire:model.defer="newUserLastName">
                        @error('newUserLastName') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" wire:model.defer="newUserEmail">
                        @error('newUserEmail') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" wire:model.defer="newUserPhone" placeholder="+2348012345678">
                        @error('newUserPhone') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" wire:model.defer="newUserPassword" autocomplete="new-password">
                        @error('newUserPassword') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">Confirm password</label>
                        <input type="password" class="form-control" wire:model.defer="newUserPasswordConfirmation" autocomplete="new-password">
                        @error('newUserPasswordConfirmation') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <x-ui.form-state label="Ready to add" />
                <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary rounded-pill" wire:loading.attr="disabled" wire:target="createUser">
                    <span wire:loading.remove wire:target="createUser">Add user</span>
                    <span wire:loading wire:target="createUser">Adding...</span>
                </button>
            </div>
        </form>
    </div>
  </div>
</div>

<!-- User Details Modal -->
<div
  class="modal fade"
  id="userDetailsModal"
  tabindex="-1"
  aria-labelledby="userDetailsModalLabel"
  aria-hidden="true"
  wire:ignore.self
>
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content user-modal">

      @if($selectedUser)

      <!-- Header -->
      <div class="modal-header user-modal-header">
        <div class="d-flex align-items-center gap-3">
            <x-user-avatar :user="$selectedUser" class="user-avatar-lg" />

            <div>
                <h5 class="mb-0" id="userDetailsModalLabel">
                    {{ $selectedUser->firstname }} {{ $selectedUser->lastname }}
                </h5>
                <div class="user-meta">{{ $selectedUser->email }}</div>
                <div class="user-meta">
                    Joined {{ $selectedUser->created_at->format('d M Y') }}
                </div>
                <div class="mt-2">
                    @if($selectedUser->isSuspended())
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Suspended</span>
                    @elseif($selectedUser->email_verified_at)
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Pending email</span>
                    @endif
                </div>
            </div>
        </div>

        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <!-- Body -->
      <div class="modal-body px-4 py-4">

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-pill">
                    <span>Businesses</span>
                    <strong>{{ $userStats['sbos'] }}</strong>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-pill stat-success">
                    <span>Approved</span>
                    <strong>{{ $userStats['approved'] }}</strong>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-pill stat-warning">
                    <span>Pending</span>
                    <strong>{{ $userStats['pending'] }}</strong>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-pill stat-neutral">
                    <span>Reviews</span>
                    <strong>{{ $userStats['reviews'] }}</strong>
                </div>
            </div>
        </div>

        <!-- Account controls -->
        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
            <div>
                <h6 class="fw-semibold mb-1">Account Access</h6>
                <p class="small text-muted-soft mb-0">
                    Suspension signs the user out and blocks future access. Delete is only allowed when core business dependencies have been cleared.
                </p>
                @if($selectedUser->isSuspended())
                    <div class="small text-danger mt-2">
                        Suspended {{ $selectedUser->suspended_at?->diffForHumans() }}
                        @if($selectedUser->suspension_reason)
                            · {{ $selectedUser->suspension_reason }}
                        @endif
                    </div>
                @endif
            </div>
            <div class="d-flex gap-2 flex-wrap">
                @if(auth()->user()?->isSuperAdmin())
                    @if(! $selectedUser->role->isAdmin())
                        <a
                            href="{{ route('admin.admins.index', ['promote' => $selectedUser->id]) }}"
                            class="btn btn-sm btn-outline-primary rounded-pill"
                        >
                            Promote to Admin
                        </a>
                    @endif
                @endif
                @if($selectedUser->isSuspended())
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-success rounded-pill"
                        wire:click="reactivateUser({{ $selectedUser->id }})"
                        wire:confirm="Reactivate this user?"
                    >
                        Reactivate
                    </button>
                @else
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-warning rounded-pill"
                        wire:click="suspendUser({{ $selectedUser->id }})"
                        wire:confirm="Suspend this user? They will be signed out and blocked from the platform."
                    >
                        Suspend
                    </button>
                @endif
                <button
                    type="button"
                    class="btn btn-sm btn-outline-danger rounded-pill"
                    wire:click="deleteUser({{ $selectedUser->id }})"
                    wire:confirm="Delete this user? This cannot be undone. Accounts with active dependencies will be blocked."
                >
                    Delete
                </button>
            </div>
        </div>

        <!-- Businesses (SBOs and COBs) -->
        <h6 class="fw-semibold mb-3">Managed businesses</h6>

        <div class="table-responsive">
            <table class="table table-borderless align-middle business-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($userSbos as $sbo)
                        @php
                            $status = $sbo->verification_status ?? 'pending';
                            $statusLabel = match ($status) {
                                'approved' => 'Approved',
                                'declined' => 'Declined',
                                'info_requested' => 'Info requested',
                                default => 'Pending',
                            };
                            $statusClass = match ($status) {
                                'approved' => 'badge-success-soft',
                                'declined' => 'badge-danger-soft',
                                default => 'badge-warning-soft',
                            };
                        @endphp
                        <tr class="business-row">
                            <td class="fw-medium">
                                {{ $sbo->name }}
                            </td>
                            <td class="text-muted-soft">
                                {{ $sbo->entity_type ?: 'SBO' }}
                            </td>
                            <td class="text-muted-soft">
                                {{ $sbo->category ?? $sbo->business_type ?? '—' }}
                            </td>
                            <td>
                                <span class="{{ $statusClass }}">{{ $statusLabel }}</span>
                            </td>
                            <td class="text-end">
                                <a
                                  href="{{ route('admin.sbos.show', $sbo) }}"
                                  class="btn btn-sm btn-outline-primary rounded-pill px-3"
                                >
                                  View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted-soft py-4">
                                No businesses assigned.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Assign as SBO Manager -->
        <hr class="my-4">
        <h6 class="fw-semibold mb-2">SBO Management</h6>
        <p class="small text-muted-soft mb-2">
            Current role: <strong>{{ $selectedUser->role->label() }}</strong>.
            Assign this user as the manager of an SBO (they will be set as an SBO Manager and emailed).
        </p>
        <form wire:submit.prevent="assignAsManager" class="row g-2 align-items-start">
            <div class="col-md-8">
                <select class="form-select form-select-sm" wire:model.defer="assignSboId">
                    <option value="">Select an SBO…</option>
                    @foreach($allSbos as $s)
                        <option value="{{ $s->id }}">
                            {{ $s->name }}{{ $s->created_by === $selectedUser->id ? ' (already managing)' : '' }}
                        </option>
                    @endforeach
                </select>
                @error('assignSboId') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-sm btn-primary rounded-pill w-100">
                    Assign as Manager
                </button>
            </div>
        </form>

      </div>
      @endif

    </div>
  </div>
</div>
