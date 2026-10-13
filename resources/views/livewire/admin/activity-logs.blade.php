<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\AdminCapabilities;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Activity Logs – Admin Dashboard', 'dataPage' => 'activity', 'pageTitle' => 'Activity Logs'])] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'actor')]
    public ?int $actorId = null;

    protected string $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        if ( ! auth()->user()?->hasPermission(AdminCapabilities::VIEW_AUDIT_LOG)) {
            abort(403);
        }
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

    public function updatedActorId(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $search = mb_trim($this->search);

        $activities = ActivityLog::query()
            ->with('user')
            ->when('' !== $search, function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $term = "%{$search}%";

                    $q->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('type', 'like', $term);
                });
            })
            ->when($this->actorId, fn ($q) => $q->where('user_id', $this->actorId))
            ->latest()
            ->paginate(10);

        return [
            'activities' => $activities,
            // Admins available as actor filter options.
            'actors' => User::whereIn('role', ['admin', 'super_admin'])
                ->orderBy('firstname')
                ->get(),
        ];
    }
};
?>

<div>
    <section id="activity" class="mb-5">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h5 mb-1">Activity Logs</h2>
                <p class="text-muted-soft small mb-0">
                    System and user activity across the platform.
                </p>
            </div>

            <div class="d-flex gap-2">
                <select wire:model.live="actorId" class="form-select form-select-sm" style="min-width: 200px;">
                    <option value="">All admins</option>
                    @foreach($actors as $actor)
                        <option value="{{ $actor->id }}">{{ $actor->name }}</option>
                    @endforeach
                </select>
                <x-live-search
                    model="search"
                    :value="$search"
                    label="Search activity"
                    placeholder="Search activity..."
                    class="mb-0" />
            </div>
        </div>

        <div class="card" wire:loading.class.delay="opacity-50" wire:target="search,clearSearch,actorId,gotoPage,nextPage,previousPage">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>Activity</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Actor</th>
                                <th>Date</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse($activities as $activity)
                                <tr wire:key="activity-{{ $activity->id }}">

                                    <!-- Activity -->
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="table-avatar">
                                                {{ $activity->icon ?? 'NA' }}
                                            </div>
                                            <div>
                                                <div class="fw-semibold">
                                                    {{ $activity->title }}
                                                </div>
                                                <div class="small text-muted-soft">
                                                    {!! $activity->description !!}
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Type -->
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            {{ Str::headline($activity->type) }}
                                        </span>
                                    </td>

                                    <!-- Status -->
                                    <td>
                                        @php
                                            $badgeClass = match ($activity->status) {
                                                'pending'     => 'badge-warning-soft',
                                                'verified'    => 'badge-primary-soft',
                                                'updated'     => 'badge-primary-soft',
                                                'registered'  => 'badge-success-soft',
                                                'deleted'     => 'badge-danger-soft',
                                                default       => 'badge-secondary-soft',
                                            };
                                        @endphp

                                        <span class="{{ $badgeClass }} rounded-pill">
                                            {{ ucfirst($activity->status ?? 'info') }}
                                        </span>
                                    </td>

                                    <!-- Actor -->
                                    <td>
                                        {{ optional($activity->user)->name ?? 'System' }}
                                    </td>

                                    <!-- Date -->
                                    <td>
                                        <div>{{ $activity->created_at->format('d M Y') }}</div>
                                        <div class="small text-muted-soft">
                                            {{ $activity->created_at->diffForHumans() }}
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted-soft py-4">
                                        No activity logs found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($activities->hasPages())
                    <div class="card-footer bg-white">
                        <div class="d-flex flex-column flex-md-row gap-3 justify-content-between align-items-center">
                            
                            <div class="small text-muted">
                                Showing {{ $activities->firstItem() }}–{{ $activities->lastItem() }}
                                of {{ number_format($activities->total()) }} activities
                            </div>

                            <div class="pagination-wrapper">
                                {{ $activities->links() }}
                            </div>

                        </div>
                    </div>
                @endif


            </div>
        </div>
    </section>
</div>
