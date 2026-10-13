<?php

use App\Models\ActivityLog;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Models\AdRateCard;
use App\Notifications\AdCampaignApprovedNotification;
use App\Notifications\AdCampaignRejectedNotification;
use App\Rules\AdCreativeDimensions;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.admin', [
    'title' => 'Ad Placements – Admin Dashboard',
    'dataPage' => 'ads',
    'pageTitle' => 'Ad Placements',
])] class extends Component {
    use WithFileUploads;

    public ?int $editingId = null;

    // form fields
    public string $slot = '';
    public string $title = '';
    public string $cta_text = 'Learn More';
    public ?string $cta_url = null;
    public int $priority = 0;
    public ?string $starts_at = null;
    public ?string $ends_at = null;
    public bool $is_active = true;
    public $creative = null;
    public ?int $rejectingCampaignId = null;
    public string $rejectionReason = '';
    public array $rateDrafts = [];

    public function mount(): void
    {
        if (! auth()->user()?->hasPermission(\App\Support\AdminCapabilities::MANAGE_ADS)) {
            abort(403);
        }

        $this->loadRateDrafts();
    }

    protected function rules(): array
    {
        return [
            'slot' => ['required', 'string', 'in:' . implode(',', array_keys(AdPlacement::slots()))],
            'title' => ['required', 'string', 'max:255'],
            'cta_text' => ['required', 'string', 'max:60'],
            'cta_url' => ['nullable', 'url', 'max:2048'],
            'priority' => ['integer', 'min:0', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['boolean'],
            // Image required on create; optional on edit (keep existing).
            'creative' => [
                $this->editingId ? 'nullable' : 'required',
                'image',
                'max:5120',
                new AdCreativeDimensions($this->slot),
            ],
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->dispatch('open-ad-modal');
    }

    public function edit(int $id): void
    {
        $ad = AdPlacement::findOrFail($id);

        $this->editingId = $ad->id;
        $this->slot = $ad->slot;
        $this->title = $ad->title;
        $this->cta_text = $ad->cta_text;
        $this->cta_url = $ad->cta_url;
        $this->priority = $ad->priority;
        $this->starts_at = $ad->starts_at?->format('Y-m-d\TH:i');
        $this->ends_at = $ad->ends_at?->format('Y-m-d\TH:i');
        $this->is_active = $ad->is_active;
        $this->creative = null;

        $this->resetValidation();
        $this->dispatch('open-ad-modal');
    }

    public function save(): void
    {
        $data = $this->validate();

        $ad = $this->editingId
            ? AdPlacement::findOrFail($this->editingId)
            : new AdPlacement();

        $ad->fill([
            'slot' => $this->slot,
            'title' => $this->title,
            'cta_text' => $this->cta_text,
            'cta_url' => $this->cta_url,
            'priority' => $this->priority,
            'starts_at' => $this->starts_at ?: null,
            'ends_at' => $this->ends_at ?: null,
            'is_active' => $this->is_active,
        ]);

        if (! $ad->exists) {
            $ad->created_by = auth()->id();
        }

        $ad->save();

        if ($this->creative) {
            $ad->clearMediaCollection('creative');
            $ad->addMedia($this->creative->getRealPath())
                ->usingFileName($this->creative->getClientOriginalName())
                ->toMediaCollection('creative');
        }

        \App\Models\ActivityLog::create([
            'type' => $this->editingId ? 'ad_updated' : 'ad_created',
            'title' => $ad->title,
            'description' => 'Ad ' . ($this->editingId ? 'updated' : 'created') . ' by <strong>' . (auth()->user()->name ?? 'admin') . '</strong>.',
            'status' => 'updated',
            'user_id' => auth()->id(),
        ]);

        $this->dispatch('close-ad-modal');
        session()->flash('success', $this->editingId ? 'Ad updated.' : 'Ad created.');
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $ad = AdPlacement::findOrFail($id);
        $ad->update(['is_active' => ! $ad->is_active]);
    }

    public function delete(int $id): void
    {
        AdPlacement::findOrFail($id)->delete();
        session()->flash('success', 'Ad deleted.');
    }

    public function approveCampaign(int $id): void
    {
        $campaign = AdCampaign::with(['media', 'owner'])->findOrFail($id);

        if ($campaign->status !== AdCampaign::STATUS_PAID_PENDING_REVIEW) {
            session()->flash('success', 'Campaign is not awaiting review.');

            return;
        }

        $startsAt = $campaign->requestedStart();
        $endsAt = $startsAt->copy()->addDays($campaign->duration_days);

        $ad = AdPlacement::create([
            'slot' => $campaign->slot,
            'title' => $campaign->title,
            'cta_text' => $campaign->cta_text,
            'cta_url' => $campaign->cta_url,
            'priority' => 100,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => true,
            'created_by' => $campaign->user_id,
        ]);

        if ($desktop = $campaign->getFirstMedia('desktop_creative')) {
            $ad->addMedia($desktop->getPath())
                ->preservingOriginal()
                ->usingFileName($desktop->file_name)
                ->toMediaCollection('creative');
        }

        if ($mobile = $campaign->getFirstMedia('mobile_creative')) {
            $ad->addMedia($mobile->getPath())
                ->preservingOriginal()
                ->usingFileName($mobile->file_name)
                ->toMediaCollection('mobile_creative');
        }

        $campaign->update([
            'ad_placement_id' => $ad->id,
            'status' => $startsAt->isFuture() ? AdCampaign::STATUS_APPROVED : AdCampaign::STATUS_ACTIVE,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        $campaign->owner?->notify(new AdCampaignApprovedNotification($campaign->fresh()));

        ActivityLog::create([
            'type' => 'ad_campaign_approved',
            'title' => $campaign->title,
            'description' => 'Ad campaign approved by <strong>' . (auth()->user()->name ?? 'admin') . '</strong>.',
            'status' => 'approved',
            'user_id' => auth()->id(),
        ]);

        session()->flash('success', 'Campaign approved and scheduled.');
    }

    public function openRejectCampaign(int $id): void
    {
        $this->rejectingCampaignId = $id;
        $this->rejectionReason = '';
        $this->resetValidation();
        $this->dispatch('open-ad-campaign-reject-modal');
    }

    public function rejectCampaign(): void
    {
        $data = $this->validate([
            'rejectionReason' => ['required', 'string', 'max:2000'],
        ]);

        $campaign = AdCampaign::with('owner')->findOrFail($this->rejectingCampaignId);

        if ($campaign->status !== AdCampaign::STATUS_PAID_PENDING_REVIEW) {
            $this->dispatch('close-ad-campaign-reject-modal');
            session()->flash('success', 'Campaign is not awaiting review.');

            return;
        }

        $campaign->update([
            'status' => AdCampaign::STATUS_REJECTED,
            'rejection_reason' => $data['rejectionReason'],
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
        ]);

        $campaign->owner?->notify(new AdCampaignRejectedNotification($campaign->fresh()));

        ActivityLog::create([
            'type' => 'ad_campaign_rejected',
            'title' => $campaign->title,
            'description' => 'Ad campaign rejected by <strong>' . (auth()->user()->name ?? 'admin') . '</strong>.',
            'status' => 'declined',
            'user_id' => auth()->id(),
        ]);

        $this->reset(['rejectingCampaignId', 'rejectionReason']);
        $this->dispatch('close-ad-campaign-reject-modal');
        session()->flash('success', 'Campaign rejected and advertiser notified.');
    }

    public function updateRateCard(int $id): void
    {
        $rate = AdRateCard::findOrFail($id);
        $draft = $this->rateDrafts[$id] ?? [];

        $price = max(0, (int) ($draft['price'] ?? $rate->price));
        $rate->update([
            'price' => $price,
            'is_active' => (bool) ($draft['is_active'] ?? false),
        ]);

        $this->loadRateDrafts();
        session()->flash('success', 'Rate card updated.');
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editingId', 'slot', 'title', 'cta_text', 'cta_url',
            'priority', 'starts_at', 'ends_at', 'creative',
        ]);
        $this->cta_text = 'Learn More';
        $this->is_active = true;
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'slots' => AdPlacement::slots(),
            'ads' => AdPlacement::with('media')->latest()->get()->groupBy('slot'),
            'campaigns' => AdCampaign::with(['owner', 'media'])
                ->latest()
                ->get(),
            'rateCards' => AdRateCard::query()
                ->orderBy('slot')
                ->orderBy('sort_order')
                ->get()
                ->groupBy('slot'),
        ];
    }

    private function loadRateDrafts(): void
    {
        $this->rateDrafts = AdRateCard::query()
            ->get()
            ->mapWithKeys(fn (AdRateCard $rate): array => [
                $rate->id => [
                    'price' => (string) $rate->price,
                    'is_active' => $rate->is_active,
                ],
            ])
            ->all();
    }
};
?>

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h5 mb-1">Ad Placements</h2>
            <p class="text-muted-soft small mb-0">Manage advertisements across the platform. Each slot has a required creative size.</p>
        </div>
        <button class="btn btn-sm btn-primary rounded-pill" wire:click="create">
            <i class="bi bi-plus-circle me-1"></i> New Ad
        </button>
    </div>

    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="live-ads-tab" data-bs-toggle="tab" data-bs-target="#live-ads" type="button" role="tab">Live Ads</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="campaign-submissions-tab" data-bs-toggle="tab" data-bs-target="#campaign-submissions" type="button" role="tab">Campaign Submissions</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="rate-cards-tab" data-bs-toggle="tab" data-bs-target="#rate-cards" type="button" role="tab">Rate Cards</button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="live-ads" role="tabpanel" aria-labelledby="live-ads-tab">
            @foreach($slots as $key => $cfg)
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center bg-light">
                        <div>
                            <strong>{{ $cfg['label'] }}</strong>
                            <span class="badge bg-secondary-subtle text-secondary ms-2">{{ $cfg['width'] }}×{{ $cfg['height'] }} px</span>
                            <code class="small text-muted ms-2">{{ $key }}</code>
                        </div>
                        <button class="btn btn-sm btn-outline-primary rounded-pill"
                            wire:click="$set('slot', '{{ $key }}'); $dispatch('open-ad-modal')">
                            Add to this slot
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="text-muted small">
                                <tr>
                                    <th>Creative</th><th>Title</th><th>Schedule</th>
                                    <th>Priority</th><th>Impr.</th><th>Clicks</th><th>CTR</th>
                                    <th>Status</th><th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($ads[$key] ?? [] as $ad)
                                    <tr>
                                        <td>
                                            @if($ad->imageUrl())
                                                <img src="{{ $ad->imageUrl() }}" alt="" style="height:32px;border-radius:4px;">
                                            @else <span class="text-muted small">—</span> @endif
                                        </td>
                                        <td class="small">{{ $ad->title }}</td>
                                        <td class="small text-muted">
                                            {{ $ad->starts_at?->format('d M Y') ?? 'Now' }} –
                                            {{ $ad->ends_at?->format('d M Y') ?? '∞' }}
                                        </td>
                                        <td>{{ $ad->priority }}</td>
                                        <td>{{ number_format($ad->impressions) }}</td>
                                        <td>{{ number_format($ad->clicks) }}</td>
                                        <td>{{ $ad->ctr() }}%</td>
                                        <td>
                                            <button class="btn btn-sm p-0 border-0 bg-transparent" wire:click="toggleActive({{ $ad->id }})">
                                                @if($ad->is_active)
                                                    <span class="badge bg-success-subtle text-success">Active</span>
                                                @else
                                                    <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                                                @endif
                                            </button>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-secondary rounded-pill" wire:click="edit({{ $ad->id }})">Edit</button>
                                            <button class="btn btn-sm btn-outline-danger rounded-pill"
                                                wire:click="delete({{ $ad->id }})"
                                                wire:confirm="Delete this ad?">Delete</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="text-center text-muted small py-3">No ads in this slot.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="tab-pane fade" id="campaign-submissions" role="tabpanel" aria-labelledby="campaign-submissions-tab">
            <div class="card">
                <div class="card-body p-0">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="text-muted small">
                            <tr>
                                <th>Creative</th>
                                <th>Campaign</th>
                                <th>Advertiser</th>
                                <th>Placement</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($campaigns as $campaign)
                                <tr>
                                    <td>
                                        @if($campaign->desktopImageUrl())
                                            <img src="{{ $campaign->desktopImageUrl() }}" alt="" style="height:34px;border-radius:4px;">
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        <div class="fw-semibold">{{ $campaign->title }}</div>
                                        <a href="{{ $campaign->cta_url }}" target="_blank" rel="noopener" class="text-muted">{{ $campaign->cta_text }}</a>
                                    </td>
                                    <td class="small">{{ $campaign->owner?->name ?? '—' }}</td>
                                    <td class="small">{{ $campaign->slotConfig()['label'] ?? $campaign->slot }}</td>
                                    <td class="small">
                                        {{ number_format($campaign->price) }} {{ $campaign->currency }}
                                        <div class="text-muted">{{ $campaign->payment_reference ?: 'No payment ref' }}</div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border">{{ str_replace('_', ' ', $campaign->status) }}</span></td>
                                    <td class="text-end">
                                        @if($campaign->status === AdCampaign::STATUS_PAID_PENDING_REVIEW)
                                            <button class="btn btn-sm btn-success rounded-pill" wire:click="approveCampaign({{ $campaign->id }})" wire:confirm="Approve this paid ad campaign?">Approve</button>
                                            <button class="btn btn-sm btn-outline-danger rounded-pill" wire:click="openRejectCampaign({{ $campaign->id }})">Reject</button>
                                        @else
                                            <span class="small text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted small py-3">No campaign submissions yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="rate-cards" role="tabpanel" aria-labelledby="rate-cards-tab">
            @foreach($slots as $key => $cfg)
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <strong>{{ $cfg['label'] }}</strong>
                        <span class="badge bg-secondary-subtle text-secondary ms-2">{{ $cfg['width'] }}×{{ $cfg['height'] }} px</span>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="text-muted small">
                                <tr>
                                    <th>Package</th>
                                    <th>Duration</th>
                                    <th>Price (Espees)</th>
                                    <th>Active</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rateCards[$key] ?? [] as $rate)
                                    <tr>
                                        <td>{{ $rate->label }}</td>
                                        <td>{{ $rate->duration_days }} days</td>
                                        <td style="max-width: 160px;">
                                            <input type="number" min="0" class="form-control form-control-sm" wire:model="rateDrafts.{{ $rate->id }}.price">
                                        </td>
                                        <td>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch" wire:model="rateDrafts.{{ $rate->id }}.is_active">
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary rounded-pill" wire:click="updateRateCard({{ $rate->id }})">Save</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted small py-3">No rates configured.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- AD MODAL -->
    <div class="modal fade" id="adModal" tabindex="-1" aria-labelledby="adModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="save">
                    <div class="modal-header">
                        <h5 class="modal-title" id="adModalLabel">{{ $editingId ? 'Edit Ad' : 'New Ad' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Slot</label>
                                <select class="form-select" wire:model="slot">
                                    <option value="">Choose slot…</option>
                                    @foreach($slots as $k => $c)
                                        <option value="{{ $k }}">{{ $c['label'] }} ({{ $c['width'] }}×{{ $c['height'] }})</option>
                                    @endforeach
                                </select>
                                @error('slot') <span class="text-danger small">{{ $message }}</span> @enderror
                                @if($slot && isset($slots[$slot]))
                                    <div class="form-text">Required image size: <strong>{{ $slots[$slot]['width'] }}×{{ $slots[$slot]['height'] }} px</strong> (exact).</div>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Title</label>
                                <input class="form-control" wire:model="title">
                                @error('title') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CTA text</label>
                                <input class="form-control" wire:model="cta_text">
                                @error('cta_text') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CTA URL</label>
                                <input class="form-control" wire:model="cta_url" placeholder="https://…">
                                @error('cta_url') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Priority</label>
                                <input type="number" class="form-control" wire:model="priority" min="0" max="1000">
                                @error('priority') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Starts at</label>
                                <input type="datetime-local" class="form-control" wire:model="starts_at">
                                @error('starts_at') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Ends at</label>
                                <input type="datetime-local" class="form-control" wire:model="ends_at">
                                @error('ends_at') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Creative image {{ $editingId ? '(leave blank to keep current)' : '' }}</label>
                                <input type="file" class="form-control" wire:model="creative" accept="image/*">
                                <div wire:loading wire:target="creative" class="form-text">Uploading…</div>
                                @error('creative') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" wire:model="is_active" id="adActive">
                                    <label class="form-check-label" for="adActive">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill"
                            wire:loading.attr="disabled" wire:target="save,creative">
                            <span wire:loading.remove wire:target="save">Save Ad</span>
                            <span wire:loading wire:target="save">Saving…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="adCampaignRejectModal" tabindex="-1" aria-labelledby="adCampaignRejectModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="rejectCampaign">
                    <div class="modal-header">
                        <h5 class="modal-title" id="adCampaignRejectModalLabel">Reject Ad Campaign</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Reason for rejection</label>
                        <textarea class="form-control" rows="4" wire:model="rejectionReason" required></textarea>
                        @error('rejectionReason') <span class="text-danger small">{{ $message }}</span> @enderror
                        <div class="form-text">The advertiser will receive this reason by email.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill">Reject Campaign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @script
    <script>
        window.addEventListener('open-ad-modal', () => {
            new bootstrap.Modal(document.getElementById('adModal')).show();
        });
        window.addEventListener('close-ad-modal', () => {
            const el = document.getElementById('adModal');
            const inst = el ? bootstrap.Modal.getInstance(el) : null;
            if (inst) inst.hide();
        });
        window.addEventListener('open-ad-campaign-reject-modal', () => {
            new bootstrap.Modal(document.getElementById('adCampaignRejectModal')).show();
        });
        window.addEventListener('close-ad-campaign-reject-modal', () => {
            const el = document.getElementById('adCampaignRejectModal');
            const inst = el ? bootstrap.Modal.getInstance(el) : null;
            if (inst) inst.hide();
        });
    </script>
    @endscript
</div>
