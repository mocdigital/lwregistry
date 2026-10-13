<?php

use App\Models\ActivityLog;
use App\Models\Country;
use App\Models\SboCapitalType;
use App\Models\SboFeeBand;
use App\Models\SboFeeSetting;
use App\Support\AdminCapabilities;
use App\Support\SboFeeCalculator;
use App\Support\SboFeeStructures;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.admin', ['title' => 'Registration Fees – Admin Dashboard', 'dataPage' => 'fees', 'pageTitle' => 'SBO Registration Fees'])] class extends Component
{
    private const PAYMENT_SETTING_LABELS = [
        'flat_fee' => 'Flat fee',
        'currency' => 'Currency',
        'merchant_name' => 'Espees Merchant code',
        'merchant_app' => 'Payment app',
        'espees_enabled' => 'Enable Espees checkout (advertising + SBO registration)',
        'espees_token' => 'Espees token',
        'espees_merchant_wallet' => 'Espees merchant wallet',
        'espees_test_mode' => 'Espees test mode',
    ];

    /**
     * Country scope currently being edited. null = the GLOBAL DEFAULT schedule
     * (country_id = null); an id edits that country's own OVERRIDE rows. Every
     * read and write on this page is scoped to this value.
     */
    public ?int $countryId = null;

    // --- Payment settings ---
    public string $flatFee = '';

    public string $currency = '';

    public string $merchantName = '';

    public string $merchantApp = '';

    public bool $espeesEnabled = false;

    public string $espeesToken = '';

    public string $espeesMerchantWallet = '';

    public bool $espeesTestMode = false;

    /**
     * New capital-type form state keyed by share structure.
     *
     * @var array<string, array{label: string, key: string}>
     */
    public array $newTypes = [];

    /**
     * New fee-band form state keyed by share structure.
     *
     * @var array<string, array{label: string, max_equity: ?string, fee: string}>
     */
    public array $newBands = [];

    // --- Inline edit state ---
    public ?int $editBandId = null;

    public ?string $editBandMaxEquity = null;

    public string $editBandFee = '';

    public string $editBandLabel = '';

    public bool $editBandActive = true;

    public ?int $editTypeId = null;

    public string $editTypeLabel = '';

    public bool $editTypeActive = true;

    public ?int $editSettingId = null;

    public string $editSettingValue = '';

    public function mount(): void
    {
        $this->authorizeAdmin();

        $this->newBands = $this->defaultNewBands();
        $this->newTypes = $this->defaultNewTypes();
        $this->loadSettings();
    }

    /**
     * When the admin switches country scope, reload settings for that scope and
     * abandon any in-flight inline edit (its ids belong to the old scope).
     */
    public function updatedCountryId(): void
    {
        $this->reset([
            'editBandId', 'editBandMaxEquity', 'editBandFee', 'editBandLabel', 'editBandActive',
            'editTypeId', 'editTypeLabel', 'editTypeActive',
            'editSettingId', 'editSettingValue',
        ]);

        $this->loadSettings();
    }

    /**
     * Load the payment-settings form fields for the current scope. Reads the
     * scope's OWN values (not the inherited global) so the admin edits exactly
     * what this scope stores; blank means "inherits global".
     */
    private function loadSettings(): void
    {
        $own = SboFeeSetting::query()
            ->where(fn ($q) => null === $this->countryId
                ? $q->whereNull('country_id')
                : $q->where('country_id', $this->countryId))
            ->pluck('value', 'key');

        $this->flatFee = (string) ($own['flat_fee'] ?? '');
        $this->currency = (string) ($own['currency'] ?? '');
        $this->merchantName = (string) ($own['merchant_name'] ?? '');
        $this->merchantApp = (string) ($own['merchant_app'] ?? '');
        $this->espeesEnabled = in_array(mb_strtolower((string) ($own['espees_enabled'] ?? '0')), ['1', 'true', 'yes', 'on'], true);
        $this->espeesToken = (string) ($own['espees_token'] ?? '');
        $this->espeesMerchantWallet = (string) ($own['espees_merchant_wallet'] ?? '');
        $this->espeesTestMode = in_array(mb_strtolower((string) ($own['espees_test_mode'] ?? '0')), ['1', 'true', 'yes', 'on'], true);
    }

    // ---- Settings ----
    public function saveSettings(): void
    {
        $this->authorizeAdmin();

        // The global default must be complete; a country scope may leave any
        // field blank to inherit that setting from the global default.
        $required = $this->isGlobalScope() ? 'required' : 'nullable';

        $data = $this->validate([
            'flatFee' => [$required, 'integer', 'min:0'],
            'currency' => [$required, 'string', 'max:50'],
            'merchantName' => [$required, 'string', 'max:255'],
            'merchantApp' => [$required, 'string', 'max:255'],
            'espeesEnabled' => ['boolean'],
            'espeesToken' => [$this->espeesEnabled ? 'required' : 'nullable', 'string', 'max:500'],
            'espeesMerchantWallet' => [$this->espeesEnabled ? 'required' : 'nullable', 'string', 'max:255'],
            'espeesTestMode' => ['boolean'],
        ]);

        $this->saveSetting('flat_fee', '' !== (string) $data['flatFee'] ? (string) $data['flatFee'] : null);
        $this->saveSetting('currency', $data['currency'] ?: null);
        $this->saveSetting('merchant_name', $data['merchantName'] ?: null);
        $this->saveSetting('merchant_app', $data['merchantApp'] ?: null);
        $this->saveSetting('espees_enabled', $data['espeesEnabled'] ? '1' : ($this->isGlobalScope() ? '0' : null));
        $this->saveSetting('espees_token', $data['espeesToken'] ?: null);
        $this->saveSetting('espees_merchant_wallet', $data['espeesMerchantWallet'] ?: null);
        $this->saveSetting('espees_test_mode', $data['espeesTestMode'] ? '1' : ($this->isGlobalScope() ? '0' : null));
        SboFeeCalculator::flushCache();

        $this->log(
            'sbo_fee_settings_updated',
            'Registration fee settings',
            'Fee settings updated for <strong>' . e($this->scopeLabel()) . '</strong> by <strong>' . e(auth()->user()->name ?? 'admin') . '</strong>.'
        );

        session()->flash('success', 'Payment settings saved.');
    }

    public function editSetting(int $id): void
    {
        $setting = $this->scopedSettings()->findOrFail($id);

        $this->editSettingId = $setting->id;
        $this->editSettingValue = (string) $setting->value;
    }

    public function cancelSettingEdit(): void
    {
        $this->reset(['editSettingId', 'editSettingValue']);
    }

    public function updateSetting(): void
    {
        $this->authorizeAdmin();

        $setting = $this->scopedSettings()->findOrFail($this->editSettingId);

        $required = $this->isGlobalScope() ? 'required' : 'nullable';
        $rules = match ($setting->key) {
            'flat_fee' => ['editSettingValue' => [$required, 'integer', 'min:0']],
            'currency' => ['editSettingValue' => [$required, 'string', 'max:50']],
            'merchant_name', 'merchant_app' => ['editSettingValue' => [$required, 'string', 'max:255']],
            'espees_enabled', 'espees_test_mode' => ['editSettingValue' => ['required', 'boolean']],
            'espees_token' => ['editSettingValue' => ['nullable', 'string', 'max:500']],
            'espees_merchant_wallet' => ['editSettingValue' => ['nullable', 'string', 'max:255']],
            default => abort(404),
        };

        $data = $this->validate($rules, [], ['editSettingValue' => self::PAYMENT_SETTING_LABELS[$setting->key] ?? 'setting value']);

        $value = (string) ($data['editSettingValue'] ?? '');

        if ('' === $value && ! $this->isGlobalScope()) {
            $key = $setting->key;
            $setting->delete();

            $this->cancelSettingEdit();
            $this->loadSettings();
            SboFeeCalculator::flushCache();

            $this->log(
                'sbo_fee_setting_deleted',
                self::PAYMENT_SETTING_LABELS[$key] ?? $key,
                'Payment setting <strong>' . e(self::PAYMENT_SETTING_LABELS[$key] ?? $key) . '</strong> removed from ' . e($this->scopeLabel()) . '.',
                'declined'
            );

            session()->flash('success', 'Payment setting removed. This scope now inherits the global value.');

            return;
        }

        $setting->update(['value' => $value]);

        $this->cancelSettingEdit();
        $this->loadSettings();
        SboFeeCalculator::flushCache();

        $this->log(
            'sbo_fee_setting_updated',
            self::PAYMENT_SETTING_LABELS[$setting->key] ?? $setting->key,
            'Payment setting <strong>' . e(self::PAYMENT_SETTING_LABELS[$setting->key] ?? $setting->key) . '</strong> updated for ' . e($this->scopeLabel()) . '.'
        );

        session()->flash('success', 'Payment setting updated.');
    }

    public function deleteSetting(int $id): void
    {
        $this->authorizeAdmin();

        $setting = $this->scopedSettings()->findOrFail($id);
        $key = $setting->key;
        $label = self::PAYMENT_SETTING_LABELS[$key] ?? $key;
        $setting->delete();

        if ($this->editSettingId === $id) {
            $this->cancelSettingEdit();
        }

        $this->loadSettings();
        SboFeeCalculator::flushCache();

        $this->log(
            'sbo_fee_setting_deleted',
            $label,
            'Payment setting <strong>' . e($label) . '</strong> removed from ' . e($this->scopeLabel()) . '.',
            'declined'
        );

        session()->flash('success', $this->isGlobalScope()
            ? 'Payment setting deleted.'
            : 'Payment setting removed. This scope now inherits the global value.');
    }

    /**
     * Persist one setting in the current scope. A blank value in a country
     * scope deletes the override so it inherits the global default again.
     */
    private function saveSetting(string $key, ?string $value): void
    {
        if (null === $value && ! $this->isGlobalScope()) {
            SboFeeSetting::query()
                ->where('country_id', $this->countryId)
                ->where('key', $key)
                ->delete();

            return;
        }

        SboFeeSetting::set($key, $value, $this->countryId);
    }

    // ---- Capital types ----
    public function addCapitalType(string $structure): void
    {
        $this->authorizeAdmin();

        abort_unless(in_array($structure, SboFeeStructures::shareStructures(), true), 404);

        $this->ensureNewTypeState($structure);

        $data = $this->validate([
            "newTypes.{$structure}.key" => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            "newTypes.{$structure}.label" => ['required', 'string', 'max:255'],
        ], [
            "newTypes.{$structure}.key.regex" => 'The key may only contain lowercase letters, numbers and underscores.',
        ]);

        $type = $data['newTypes'][$structure];

        if ($this->scopedTypes()->where('structure', $structure)->where('key', $type['key'])->exists()) {
            $this->addError("newTypes.{$structure}.key", 'That key already exists for this structure.');

            return;
        }

        SboCapitalType::create([
            'country_id' => $this->countryId,
            'structure' => $structure,
            'key' => $type['key'],
            'label' => $type['label'],
            'sort_order' => (int) $this->scopedTypes()->where('structure', $structure)->max('sort_order') + 1,
            'is_active' => true,
        ]);

        $this->log(
            'sbo_capital_type_created',
            $type['label'],
            'Capital type <strong>' . e($type['label']) . '</strong> added to ' . e($structure) . ' (' . e($this->scopeLabel()) . ').'
        );

        $this->newTypes[$structure] = $this->blankNewType();
        session()->flash('success', 'Capital type added.');
    }

    public function editType(int $id): void
    {
        $type = $this->scopedTypes()->findOrFail($id);
        $this->editTypeId = $type->id;
        $this->editTypeLabel = $type->label;
        $this->editTypeActive = $type->is_active;
    }

    public function cancelTypeEdit(): void
    {
        $this->reset(['editTypeId', 'editTypeLabel', 'editTypeActive']);
    }

    public function updateType(): void
    {
        $this->authorizeAdmin();

        $type = $this->scopedTypes()->findOrFail($this->editTypeId);
        $data = $this->validate([
            'editTypeLabel' => ['required', 'string', 'max:255'],
            'editTypeActive' => ['boolean'],
        ]);

        $type->update(['label' => $data['editTypeLabel'], 'is_active' => $this->editTypeActive]);

        $this->log(
            'sbo_capital_type_updated',
            $type->label,
            'Capital type <strong>' . e($type->label) . '</strong> updated.'
        );

        $this->cancelTypeEdit();
        session()->flash('success', 'Capital type updated.');
    }

    public function deleteType(int $id): void
    {
        $this->authorizeAdmin();
        $type = $this->scopedTypes()->findOrFail($id);
        $label = $type->label;
        $type->delete();

        $this->log(
            'sbo_capital_type_deleted',
            $label,
            'Capital type <strong>' . e($label) . '</strong> removed.',
            'declined'
        );

        session()->flash('success', 'Capital type removed.');
    }

    // ---- Bands ----
    public function addBand(string $structure): void
    {
        $this->authorizeAdmin();

        abort_unless(in_array($structure, SboFeeStructures::shareStructures(), true), 404);

        $this->ensureNewBandState($structure);

        $data = $this->validate([
            "newBands.{$structure}.label" => ['required', 'string', 'max:255'],
            "newBands.{$structure}.max_equity" => ['nullable', 'integer', 'min:1'],
            "newBands.{$structure}.fee" => ['required', 'integer', 'min:0'],
        ]);

        $band = $data['newBands'][$structure];

        SboFeeBand::create([
            'country_id' => $this->countryId,
            'structure' => $structure,
            'max_equity' => null !== $band['max_equity'] && '' !== $band['max_equity']
                ? (int) $band['max_equity'] : null,
            'fee' => (int) $band['fee'],
            'label' => $band['label'],
            'sort_order' => (int) $this->scopedBands()->where('structure', $structure)->max('sort_order') + 1,
            'is_active' => true,
        ]);

        $this->log(
            'sbo_fee_band_created',
            $band['label'],
            'Fee band <strong>' . e($band['label']) . '</strong> added to ' . e($structure) . ' (' . e($this->scopeLabel()) . ').'
        );

        $this->newBands[$structure] = $this->blankNewBand();
        session()->flash('success', 'Fee band added.');
    }

    public function editBand(int $id): void
    {
        $band = $this->scopedBands()->findOrFail($id);
        $this->editBandId = $band->id;
        $this->editBandMaxEquity = null !== $band->max_equity ? (string) $band->max_equity : null;
        $this->editBandFee = (string) $band->fee;
        $this->editBandLabel = $band->label;
        $this->editBandActive = $band->is_active;
    }

    public function cancelBandEdit(): void
    {
        $this->reset(['editBandId', 'editBandMaxEquity', 'editBandFee', 'editBandLabel', 'editBandActive']);
    }

    public function updateBand(): void
    {
        $this->authorizeAdmin();

        $band = $this->scopedBands()->findOrFail($this->editBandId);
        $data = $this->validate([
            'editBandMaxEquity' => ['nullable', 'integer', 'min:1'],
            'editBandFee' => ['required', 'integer', 'min:0'],
            'editBandLabel' => ['required', 'string', 'max:255'],
            'editBandActive' => ['boolean'],
        ]);

        $band->update([
            'max_equity' => null !== $data['editBandMaxEquity'] && '' !== $data['editBandMaxEquity']
                ? (int) $data['editBandMaxEquity'] : null,
            'fee' => (int) $data['editBandFee'],
            'label' => $data['editBandLabel'],
            'is_active' => $this->editBandActive,
        ]);

        $this->log(
            'sbo_fee_band_updated',
            $band->label,
            'Fee band <strong>' . e($band->label) . '</strong> updated.'
        );

        $this->cancelBandEdit();
        session()->flash('success', 'Fee band updated.');
    }

    public function deleteBand(int $id): void
    {
        $this->authorizeAdmin();
        $band = $this->scopedBands()->findOrFail($id);
        $label = $band->label;
        $band->delete();

        $this->log(
            'sbo_fee_band_deleted',
            $label,
            'Fee band <strong>' . e($label) . '</strong> removed.',
            'declined'
        );

        session()->flash('success', 'Fee band removed.');
    }

    public function with(): array
    {
        $shareStructures = SboFeeStructures::shareStructures();

        // Whether the current scope has any rows of its own. For a country
        // scope with nothing configured, the UI notes it inherits the global
        // default rather than showing an empty (broken-looking) schedule.
        $inheritsGlobal = ! $this->isGlobalScope()
            && $this->scopedBands()->doesntExist()
            && $this->scopedTypes()->doesntExist()
            && SboFeeSetting::query()->where('country_id', $this->countryId)->doesntExist();

        return [
            'shareStructures' => $shareStructures,
            'structureLabels' => SboFeeStructures::labels(),
            'countries' => Country::query()->get(['id', 'name']),
            'scopeLabel' => $this->scopeLabel(),
            'isGlobalScope' => $this->isGlobalScope(),
            'inheritsGlobal' => $inheritsGlobal,
            'bandsByStructure' => collect($shareStructures)->mapWithKeys(
                fn (string $s): array => [$s => $this->scopedBands()->where('structure', $s)->get()],
            ),
            'typesByStructure' => collect($shareStructures)->mapWithKeys(
                fn (string $s): array => [$s => $this->scopedTypes()->where('structure', $s)->get()],
            ),
            'paymentSettings' => $this->scopedSettings()->get(),
            'paymentSettingLabels' => self::PAYMENT_SETTING_LABELS,
        ];
    }

    /** True when editing the global default (no specific country selected). */
    private function isGlobalScope(): bool
    {
        return null === $this->countryId;
    }

    /** Bands query constrained to the current scope. */
    private function scopedBands(): \Illuminate\Database\Eloquent\Builder
    {
        return SboFeeBand::query()->where(fn ($q) => null === $this->countryId
            ? $q->whereNull('country_id')
            : $q->where('country_id', $this->countryId));
    }

    /** Capital-types query constrained to the current scope. */
    private function scopedTypes(): \Illuminate\Database\Eloquent\Builder
    {
        return SboCapitalType::query()->where(fn ($q) => null === $this->countryId
            ? $q->whereNull('country_id')
            : $q->where('country_id', $this->countryId));
    }

    /** Payment-settings query constrained to the current scope. */
    private function scopedSettings(): \Illuminate\Database\Eloquent\Builder
    {
        return SboFeeSetting::query()
            ->whereIn('key', array_keys(self::PAYMENT_SETTING_LABELS))
            ->where(fn ($q) => null === $this->countryId
                ? $q->whereNull('country_id')
                : $q->where('country_id', $this->countryId))
            ->orderByRaw("CASE `key` WHEN 'flat_fee' THEN 1 WHEN 'currency' THEN 2 WHEN 'merchant_name' THEN 3 WHEN 'merchant_app' THEN 4 WHEN 'espees_enabled' THEN 5 WHEN 'espees_token' THEN 6 WHEN 'espees_merchant_wallet' THEN 7 WHEN 'espees_test_mode' THEN 8 ELSE 9 END");
    }

    /**
     * @return array<string, array{label: string, key: string}>
     */
    private function defaultNewTypes(): array
    {
        return collect(SboFeeStructures::shareStructures())
            ->mapWithKeys(fn (string $structure): array => [$structure => $this->blankNewType()])
            ->all();
    }

    /**
     * @return array{label: string, key: string}
     */
    private function blankNewType(): array
    {
        return [
            'label' => '',
            'key' => '',
        ];
    }

    private function ensureNewTypeState(string $structure): void
    {
        if (! array_key_exists($structure, $this->newTypes)) {
            $this->newTypes[$structure] = $this->blankNewType();

            return;
        }

        $this->newTypes[$structure] = array_merge(
            $this->blankNewType(),
            $this->newTypes[$structure],
        );
    }

    /**
     * @return array<string, array{label: string, max_equity: ?string, fee: string}>
     */
    private function defaultNewBands(): array
    {
        return collect(SboFeeStructures::shareStructures())
            ->mapWithKeys(fn (string $structure): array => [$structure => $this->blankNewBand()])
            ->all();
    }

    /**
     * @return array{label: string, max_equity: ?string, fee: string}
     */
    private function blankNewBand(): array
    {
        return [
            'label' => '',
            'max_equity' => null,
            'fee' => '',
        ];
    }

    private function ensureNewBandState(string $structure): void
    {
        if (! array_key_exists($structure, $this->newBands)) {
            $this->newBands[$structure] = $this->blankNewBand();

            return;
        }

        $this->newBands[$structure] = array_merge(
            $this->blankNewBand(),
            $this->newBands[$structure],
        );
    }

    /** Human label for the current scope, for logs and headings. */
    private function scopeLabel(): string
    {
        return $this->isGlobalScope()
            ? 'Global default'
            : (Country::query()->whereKey($this->countryId)->value('name') ?? 'Unknown country');
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasPermission(AdminCapabilities::APPROVE_SBOS), 403);
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

    /** Validation rule: value must be a share-bearing structure key. */
    private function shareStructureRule(): Closure
    {
        return function (string $attr, $value, $fail): void {
            if ( ! in_array($value, SboFeeStructures::shareStructures(), true)) {
                $fail('Please choose a valid share structure.');
            }
        };
    }
}; ?>

<div class="admin-page admin-fees">
    <x-ui.page-header
        title="Fee schedule"
        eyebrow="Payment operations"
        description="Manage registration fees, Espees checkout settings, fee bands, and capital types without a deploy."
    >
        <x-slot:meta>
            <span>Editing: <strong>{{ $scopeLabel }}</strong></span>
            <span>Changes take effect immediately.</span>
        </x-slot:meta>
    </x-ui.page-header>
    <x-ui.flash />

    {{-- Country switcher --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold mb-1">Editing schedule for</label>
                    <select wire:model.live="countryId" class="form-select form-select-sm">
                        <option value="">🌍 Global default (applies everywhere unless overridden)</option>
                        @foreach($countries as $country)
                            <option value="{{ $country->id }}">{{ $country->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-7">
                    <p class="text-muted small mb-0">
                        You are editing the <strong>{{ $scopeLabel }}</strong> schedule.
                        @if($isGlobalScope)
                            This applies to every country that has no schedule of its own.
                        @else
                            Anything you leave unset here inherits the global default. Add bands,
                            capital types or settings only where this country differs.
                        @endif
                    </p>
                </div>
            </div>
        </div>
    </div>

    @if($inheritsGlobal)
        <div class="alert alert-info py-2 small">
            <strong>{{ $scopeLabel }}</strong> has no schedule of its own yet, so SBOs here use the
            global default. Add bands, capital types or payment settings below to create an override.
        </div>
    @endif

    {{-- Payment settings --}}
    <div class="card mb-4" id="payment-settings">
        <div class="card-body">
            <h6 class="fw-bold mb-3">Payment settings</h6>
            <form wire:submit.prevent="saveSettings">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small">Flat fee (non-share entities)</label>
                        <input type="number" min="0" wire:model="flatFee" class="form-control form-control-sm">
                        @error('flatFee') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Currency</label>
                        <input type="text" wire:model="currency" class="form-control form-control-sm">
                        @error('currency') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Espees Merchant code</label>
                        <input type="text" wire:model="merchantName" class="form-control form-control-sm">
                        @error('merchantName') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Payment app</label>
                        <input type="text" wire:model="merchantApp" class="form-control form-control-sm">
                        @error('merchantApp') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="border rounded p-3 mt-3" id="espees-checkout">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                        <div>
                            <div class="fw-semibold small">Espees checkout</div>
                            <div class="text-muted small">Used for advertising payments and SBO registration fees. The dedicated test account remains fixed at 1 Espee; normal SBOs are charged their calculated registration fee.</div>
                        </div>
                        <div class="d-flex gap-3 flex-wrap">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" wire:model="espeesEnabled" value="1" id="espeesEnabled">
                                <label class="form-check-label small" for="espeesEnabled">Enabled</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" wire:model="espeesTestMode" value="1" id="espeesTestMode">
                                <label class="form-check-label small" for="espeesTestMode">Test mode</label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small">Espees API key / token</label>
                            <input type="text" wire:model="espeesToken" class="form-control form-control-sm" autocomplete="off">
                            @error('espeesToken') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Merchant wallet</label>
                            <input type="text" wire:model="espeesMerchantWallet" class="form-control form-control-sm">
                            @error('espeesMerchantWallet') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm rounded-pill mt-3">Save settings</button>
            </form>

            <div class="mt-4">
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                    <div>
                        <div class="fw-semibold small">Saved payment settings</div>
                        <div class="text-muted small">
                            @if($isGlobalScope)
                                These are the global defaults used by countries without overrides.
                            @else
                                These are the saved overrides for {{ $scopeLabel }}. Delete an override to inherit the global value.
                            @endif
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Setting</th>
                                <th>Value</th>
                                <th>Scope</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($paymentSettings as $setting)
                                <tr wire:key="payment-setting-{{ $setting->id }}">
                                    <td>
                                        <div class="fw-semibold">{{ $paymentSettingLabels[$setting->key] ?? $setting->key }}</div>
                                        <div class="small text-muted">{{ $setting->key }}</div>
                                    </td>
                                    <td>
                                        @if($editSettingId === $setting->id)
                                            <input
                                                type="{{ $setting->key === 'flat_fee' ? 'number' : 'text' }}"
                                                min="{{ $setting->key === 'flat_fee' ? 0 : null }}"
                                                wire:model="editSettingValue"
                                                class="form-control form-control-sm"
                                            >
                                            @error('editSettingValue') <div class="text-danger small">{{ $message }}</div> @enderror
                                        @else
                                            @if($setting->key === 'flat_fee' && is_numeric($setting->value))
                                                {{ number_format((int) $setting->value) }}
                                            @else
                                                {{ $setting->value ?: '—' }}
                                            @endif
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">{{ $scopeLabel }}</span>
                                    </td>
                                    <td class="text-end">
                                        @if($editSettingId === $setting->id)
                                            <button type="button" class="btn btn-sm btn-success" wire:click="updateSetting">Save</button>
                                            <button type="button" class="btn btn-sm btn-link text-muted" wire:click="cancelSettingEdit">Cancel</button>
                                        @else
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="editSetting({{ $setting->id }})">Edit</button>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                wire:click="deleteSetting({{ $setting->id }})"
                                                wire:confirm="Delete this payment setting?"
                                            >
                                                Delete
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">
                                        No payment settings saved for this scope yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Per share-bearing structure: bands + capital types --}}
    @foreach($shareStructures as $structure)
        <div class="card mb-4">
            <div class="card-body">
                <h6 class="fw-bold mb-1">{{ $structureLabels[$structure] }}</h6>

                {{-- Fee bands --}}
                <div class="mt-3">
                    <div class="fw-semibold small mb-2">Fee bands <span class="text-muted fw-normal">(matched by equity size; leave max blank for the open-ended top band)</span></div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-2">
                            <thead class="table-light">
                                <tr><th>Label</th><th>Max equity</th><th>Fee ({{ $currency }})</th><th>Status</th><th class="text-end">Actions</th></tr>
                            </thead>
                            <tbody>
                                @forelse($bandsByStructure[$structure] as $band)
                                    @if($editBandId === $band->id)
                                        <tr>
                                            <td><input type="text" wire:model="editBandLabel" class="form-control form-control-sm">@error('editBandLabel')<div class="text-danger small">{{ $message }}</div>@enderror</td>
                                            <td><input type="number" min="1" wire:model="editBandMaxEquity" class="form-control form-control-sm" placeholder="blank = open-ended">@error('editBandMaxEquity')<div class="text-danger small">{{ $message }}</div>@enderror</td>
                                            <td><input type="number" min="0" wire:model="editBandFee" class="form-control form-control-sm">@error('editBandFee')<div class="text-danger small">{{ $message }}</div>@enderror</td>
                                            <td><div class="form-check"><input class="form-check-input" type="checkbox" wire:model="editBandActive" id="bandactive{{ $band->id }}"><label class="form-check-label small" for="bandactive{{ $band->id }}">Active</label></div></td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-success" wire:click="updateBand">Save</button>
                                                <button type="button" class="btn btn-sm btn-link text-muted" wire:click="cancelBandEdit">Cancel</button>
                                            </td>
                                        </tr>
                                    @else
                                        <tr>
                                            <td>{{ $band->label }}</td>
                                            <td>{{ $band->max_equity !== null ? number_format($band->max_equity) : '— (open-ended)' }}</td>
                                            <td>{{ number_format($band->fee) }}</td>
                                            <td>
                                                @if($band->is_active)
                                                    <span class="badge bg-success-subtle text-success">Active</span>
                                                @else
                                                    <span class="badge bg-secondary-subtle text-secondary">Hidden</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="editBand({{ $band->id }})">Edit</button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="deleteBand({{ $band->id }})" wire:confirm="Delete this fee band?">Delete</button>
                                            </td>
                                        </tr>
                                    @endif
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-3">No bands yet — add one below.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{-- Add band --}}
                    <div class="row g-2 align-items-end" wire:key="addband-{{ $structure }}">
                        <div class="col-md-4"><input type="text" class="form-control form-control-sm" placeholder="Band label"
                            wire:model="newBands.{{ $structure }}.label"></div>
                        <div class="col-md-3"><input type="number" min="1" class="form-control form-control-sm" placeholder="Max equity (blank = open)"
                            wire:model="newBands.{{ $structure }}.max_equity"></div>
                        <div class="col-md-2"><input type="number" min="0" class="form-control form-control-sm" placeholder="Fee"
                            wire:model="newBands.{{ $structure }}.fee"></div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-sm btn-primary" wire:click="addBand('{{ $structure }}')">Add band</button>
                        </div>
                    </div>
                    @error("newBands.{$structure}.label") <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    @error("newBands.{$structure}.fee") <div class="text-danger small">{{ $message }}</div> @enderror
                    @error("newBands.{$structure}.max_equity") <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                {{-- Capital types --}}
                <div class="mt-4">
                    <div class="fw-semibold small mb-2">Share-capital types</div>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        @forelse($typesByStructure[$structure] as $type)
                            @if($editTypeId === $type->id)
                                <div class="input-group input-group-sm" style="width:auto">
                                    <input type="text" wire:model="editTypeLabel" class="form-control form-control-sm">
                                    <div class="input-group-text"><input class="form-check-input mt-0 me-1" type="checkbox" wire:model="editTypeActive"> Active</div>
                                    <button type="button" class="btn btn-success" wire:click="updateType">Save</button>
                                    <button type="button" class="btn btn-outline-secondary" wire:click="cancelTypeEdit">×</button>
                                </div>
                            @else
                                <span class="badge {{ $type->is_active ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary' }} d-inline-flex align-items-center gap-2 p-2">
                                    {{ $type->label }} <span class="text-muted">({{ $type->key }})</span>
                                    <a href="#" wire:click.prevent="editType({{ $type->id }})" class="text-decoration-none">edit</a>
                                    <a href="#" wire:click.prevent="deleteType({{ $type->id }})" wire:confirm="Delete this capital type?" class="text-danger text-decoration-none">×</a>
                                </span>
                            @endif
                        @empty
                            <span class="text-muted small">No capital types yet.</span>
                        @endforelse
                    </div>
                    <div class="row g-2 align-items-end" wire:key="addtype-{{ $structure }}">
                        <div class="col-md-4"><input type="text" class="form-control form-control-sm" placeholder="Label (e.g. Ordinary Shares)"
                            wire:model="newTypes.{{ $structure }}.label"></div>
                        <div class="col-md-3"><input type="text" class="form-control form-control-sm" placeholder="key (e.g. ordinary)"
                            wire:model="newTypes.{{ $structure }}.key"></div>
                        <div class="col-md-3"><button type="button" class="btn btn-sm btn-primary" wire:click="addCapitalType('{{ $structure }}')">Add type</button></div>
                    </div>
                    @error("newTypes.{$structure}.label") <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    @error("newTypes.{$structure}.key") <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>
    @endforeach
</div>
