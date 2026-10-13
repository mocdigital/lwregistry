<?php

use App\Models\ActivityLog;
use App\Models\Zone;
use App\Notifications\ZoneReferralLinkNotification;
use App\Support\AdminPages;
use Illuminate\Notifications\AnonymousNotifiable;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin', ['title' => 'Zone Referral Links – Admin Dashboard', 'dataPage' => 'zone-referrals', 'pageTitle' => 'Zone Referral Links'])] class extends Component
{
    use WithPagination;

    public string $search = '';

    // Inline "edit pastor" state.
    public ?int $editId = null;

    public string $editPastorName = '';

    public string $editPastorEmail = '';

    // Inline "send link" state.
    public ?int $sendId = null;

    public string $sendEmail = '';

    protected string $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->reset('search');
        $this->resetPage();
    }

    // ---- Edit pastor contact ----
    public function editPastor(int $id): void
    {
        $zone = Zone::findOrFail($id);
        $this->editId = $zone->id;
        $this->editPastorName = (string) $zone->pastor_name;
        $this->editPastorEmail = (string) $zone->pastor_email;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editId', 'editPastorName', 'editPastorEmail']);
    }

    public function savePastor(): void
    {
        $this->authorizeAccess();
        $zone = Zone::findOrFail($this->editId);

        $data = $this->validate([
            'editPastorName' => ['nullable', 'string', 'max:255'],
            'editPastorEmail' => ['nullable', 'email', 'max:255'],
        ]);

        $zone->update([
            'pastor_name' => $data['editPastorName'] ?: null,
            'pastor_email' => $data['editPastorEmail'] ?: null,
        ]);

        $this->log('zone_pastor_updated', $zone->name, 'Pastor contact updated for <strong>' . e($zone->name) . '</strong>.');
        $this->cancelEdit();
        session()->flash('success', 'Pastor contact saved.');
    }

    // ---- Send referral link ----
    public function startSend(int $id): void
    {
        $zone = Zone::findOrFail($id);
        $this->sendId = $zone->id;
        $this->sendEmail = (string) $zone->pastor_email;
    }

    public function cancelSend(): void
    {
        $this->reset(['sendId', 'sendEmail']);
    }

    public function sendLink(): void
    {
        $this->authorizeAccess();
        $zone = Zone::findOrFail($this->sendId);

        $data = $this->validate(['sendEmail' => ['required', 'email', 'max:255']]);

        $this->dispatchLink($zone, $data['sendEmail']);

        $this->log('zone_referral_sent', $zone->name, 'Referral link sent to <strong>' . e($data['sendEmail']) . '</strong> for <strong>' . e($zone->name) . '</strong>.');
        $this->cancelSend();
        session()->flash('success', 'Referral link sent.');
    }

    public function sendToAll(): void
    {
        $this->authorizeAccess();

        $zones = Zone::query()
            ->where('is_active', true)
            ->whereNotNull('pastor_email')
            ->get();

        foreach ($zones as $zone) {
            $this->dispatchLink($zone, (string) $zone->pastor_email);
        }

        $this->log('zone_referral_bulk_sent', 'All zones', 'Referral links sent to <strong>' . $zones->count() . '</strong> zonal pastors.');
        session()->flash('success', $zones->count() . ' referral link(s) sent to pastors with an email on file.');
    }

    // ---- Code + status ----
    public function regenerateCode(int $id): void
    {
        $this->authorizeAccess();
        $zone = Zone::findOrFail($id);
        $zone->update(['code' => Zone::generateCode()]);

        $this->log('zone_code_regenerated', $zone->name, 'Referral code regenerated for <strong>' . e($zone->name) . '</strong> — old links no longer work.', 'declined');
        session()->flash('success', 'Referral code regenerated. Previous links for this zone no longer work.');
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeAccess();
        $zone = Zone::findOrFail($id);
        $zone->update(['is_active' => ! $zone->is_active]);

        $this->log('zone_toggled', $zone->name, 'Zone <strong>' . e($zone->name) . '</strong> ' . ($zone->is_active ? 'activated' : 'deactivated') . '.');
        session()->flash('success', 'Zone ' . ($zone->is_active ? 'activated' : 'deactivated') . '.');
    }

    public function with(): array
    {
        $search = mb_trim($this->search);

        return [
            'zones' => Zone::query()
                ->when('' !== $search, function ($query) use ($search): void {
                    $term = '%' . $search . '%';

                    $query->where(function ($zoneQuery) use ($term): void {
                        $zoneQuery->where('name', 'like', $term)
                            ->orWhere('code', 'like', $term)
                            ->orWhere('pastor_name', 'like', $term)
                            ->orWhere('pastor_email', 'like', $term);
                    });
                })
                ->withCount('sbos')
                ->orderBy('name')
                ->paginate(20),
        ];
    }

    private function dispatchLink(Zone $zone, string $email): void
    {
        (new AnonymousNotifiable())
            ->route('mail', $email)
            ->notify(new ZoneReferralLinkNotification($zone));

        $zone->update(['sent_at' => now()]);
    }

    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->hasAdminPage(AdminPages::ZONE_REFERRALS), 403);
    }

    private function log(string $type, string $title, string $description, string $status = 'approved'): void
    {
        ActivityLog::create([
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'user_id' => auth()->id(),
        ]);
    }
}; ?>

<div>
    <div class="mb-4 d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <p class="text-muted small mb-0" style="max-width: 640px;">
            Each zone has a unique referral link. Share it with the zone's pastor to drive
            registrations — anyone who signs up through the link is counted towards that zone,
            and their zone is pre-filled on the join form. Track results in
            <a href="{{ route('admin.zones.momentum.index') }}" wire:navigate>Zone Momentum</a>.
        </p>
        <button class="btn btn-primary btn-sm rounded-pill" wire:click="sendToAll"
            wire:confirm="Email every active zone that has a pastor email its own referral link?">
            <i class="bi bi-send"></i> Send to all pastors
        </button>
    </div>

    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <x-live-search
        model="search"
        :value="$search"
        label="Search zone referral links"
        placeholder="Zone, code, pastor, or email"
        input-id="zoneReferralSearch"
        label-class="form-label small fw-semibold text-muted mb-1"
        max-width="420px"
        class="mb-3" />

    <div class="card" wire:loading.class.delay="opacity-50" wire:target="search,clearSearch,gotoPage,nextPage,previousPage">
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>Zone</th>
                            <th>Referral link</th>
                            <th class="text-center">Registrations</th>
                            <th>Pastor</th>
                            <th>Last sent</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($zones as $zone)
                            <tr wire:key="zone-{{ $zone->id }}">
                                <td class="fw-semibold">{{ $zone->name }}</td>

                                {{-- Referral link: readonly (click-to-select) field + reliable copy --}}
                                <td style="min-width: 260px;">
                                    <div class="input-group input-group-sm" x-data="copyLink('{{ $zone->referralUrl() }}')">
                                        <input type="text" class="form-control form-control-sm bg-light" readonly
                                            :value="url" x-ref="field" @focus="$refs.field.select()"
                                            title="Click to select, then copy">
                                        <button type="button" class="btn btn-outline-secondary" @click="copy()"
                                            :title="copied ? 'Copied!' : 'Copy link'">
                                            <span x-show="!copied"><i class="bi bi-clipboard"></i> Copy</span>
                                            <span x-show="copied" x-cloak class="text-success"><i class="bi bi-check2"></i> Copied</span>
                                        </button>
                                    </div>
                                </td>

                                <td class="text-center fw-semibold">{{ number_format($zone->sbos_count) }}</td>

                                {{-- Pastor: inline edit --}}
                                <td>
                                    @if($editId === $zone->id)
                                        <div class="d-flex flex-column gap-1" style="min-width: 220px;">
                                            <input type="text" wire:model="editPastorName" class="form-control form-control-sm" placeholder="Pastor name">
                                            @error('editPastorName') <span class="text-danger small">{{ $message }}</span> @enderror
                                            <input type="email" wire:model="editPastorEmail" class="form-control form-control-sm" placeholder="pastor@email.com">
                                            @error('editPastorEmail') <span class="text-danger small">{{ $message }}</span> @enderror
                                            <div class="d-flex gap-1">
                                                <button class="btn btn-sm btn-success py-0" wire:click="savePastor">Save</button>
                                                <button class="btn btn-sm btn-link text-muted py-0" wire:click="cancelEdit">Cancel</button>
                                            </div>
                                        </div>
                                    @else
                                        <div class="small fw-semibold">{{ $zone->pastor_name ?: '—' }}</div>
                                        <div class="small text-muted-soft">{{ $zone->pastor_email ?: 'No email on file' }}</div>
                                    @endif
                                </td>

                                <td class="small text-muted-soft">
                                    {{ $zone->sent_at ? $zone->sent_at->format('d M Y') : 'Never' }}
                                </td>

                                <td>
                                    @if($zone->is_active)
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
                                    @endif
                                </td>

                                <td class="text-end">
                                    @if($sendId === $zone->id)
                                        <div class="d-flex flex-column gap-1 align-items-end" style="min-width: 240px;">
                                            <input type="email" wire:model="sendEmail" class="form-control form-control-sm" placeholder="Send to email">
                                            @error('sendEmail') <span class="text-danger small">{{ $message }}</span> @enderror
                                            <div class="d-flex gap-1">
                                                <button class="btn btn-sm btn-primary py-0" wire:click="sendLink">Send</button>
                                                <button class="btn btn-sm btn-link text-muted py-0" wire:click="cancelSend">Cancel</button>
                                            </div>
                                        </div>
                                    @else
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-outline-secondary" wire:click="editPastor({{ $zone->id }})" title="Edit pastor">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary" wire:click="startSend({{ $zone->id }})" title="Send link">
                                                <i class="bi bi-send"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-warning" wire:click="regenerateCode({{ $zone->id }})"
                                                wire:confirm="Regenerate this zone's code? Existing links will stop working." title="Regenerate code">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-secondary" wire:click="toggleActive({{ $zone->id }})" title="Activate / deactivate">
                                                <i class="bi bi-power"></i>
                                            </button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No zones found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $zones->links() }}</div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        // Reliable copy for referral links: prefer the async Clipboard API (needs
        // a secure context/HTTPS), and fall back to selecting the field + the
        // legacy execCommand('copy') so it still works over plain HTTP.
        document.addEventListener('alpine:init', () => {
            Alpine.data('copyLink', (url) => ({
                url,
                copied: false,
                async copy() {
                    try {
                        if (navigator.clipboard && window.isSecureContext) {
                            await navigator.clipboard.writeText(this.url);
                        } else {
                            this.$refs.field.removeAttribute('readonly');
                            this.$refs.field.select();
                            document.execCommand('copy');
                            this.$refs.field.setAttribute('readonly', 'readonly');
                        }
                        this.copied = true;
                        setTimeout(() => this.copied = false, 1500);
                    } catch (e) {
                        // Last resort: select the text so the admin can copy manually.
                        this.$refs.field.select();
                    }
                },
            }));
        });
    </script>
@endpush
