<div>
    <!-- Business Modal (Create/Edit) -->
    <div class="modal fade modal-modern" id="createBusinessModal" tabindex="-1" aria-labelledby="createBusinessModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="save" data-ui-form>
                    <div class="modal-header">
                        <h5 class="modal-title" id="createBusinessModalLabel">
                            {{ $this->isEditMode ? 'Edit Vendor Listing' : 'List Vendor Business' }}
                        </h5>
                        <x-ui.form-state label="Ready to submit" />
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetForm"></button>
                    </div>

                    <div class="modal-body">
                        <x-ui.form-errors />
                        <div class="row g-3">
                            <!-- Business Name & Category -->
                            <div class="col-md-8">
                                <label class="form-label" for="bizName">Business Name</label>
                                <input id="bizName" wire:model="name" class="form-control @error('name') is-invalid @enderror" required placeholder="e.g. KingTrust Financial">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="bizCategory">Category</label>
                                <select id="bizCategory" wire:model="category_id" class="form-select @error('category_id') is-invalid @enderror" required>
                                    <option value="">Select category</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </select>
                                @error('category_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Staff Size & Years Active -->
                            <div class="col-md-6">
                                <label class="form-label" for="bizStaffSize">Number of Employees <small class="small-muted">(optional)</small></label>
                                <select id="bizStaffSize" wire:model="staff_size" class="form-select @error('staff_size') is-invalid @enderror">
                                    <option value="">Select range</option>
                                    <option value="1-10">1-10 employees</option>
                                    <option value="11-50">11-50 employees</option>
                                    <option value="51-200">51-200 employees</option>
                                    <option value="201-500">201-500 employees</option>
                                    <option value="500+">500+ employees</option>
                                </select>
                                @error('staff_size') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="bizYearsActive">Years in Operation <small class="small-muted">(optional)</small></label>
                                <input id="bizYearsActive" wire:model="years_active" type="number" min="0" max="200" class="form-control @error('years_active') is-invalid @enderror" placeholder="e.g. 5">
                                @error('years_active') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Website & Phone -->
                            <div class="col-md-6">
                                <label class="form-label" for="bizWebsite">Website <small class="small-muted">(optional)</small></label>
                                <input id="bizWebsite" wire:model="website" type="url" class="form-control @error('website') is-invalid @enderror" placeholder="https://example.com">
                                @error('website') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="bizPhone">Phone</label>
                                <input id="bizPhone" wire:model="phone" type="tel" class="form-control @error('phone') is-invalid @enderror" placeholder="+234 800 000 000" required>
                                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Email & Address -->
                            <div class="col-md-6">
                                <label class="form-label" for="bizEmail">Email</label>
                                <input id="bizEmail" wire:model="email" type="email" class="form-control @error('email') is-invalid @enderror" placeholder="info@example.com" required>
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="bizAddress">Address</label>
                                <input id="bizAddress" wire:model="address" class="form-control @error('address') is-invalid @enderror" placeholder="Street address" required autocomplete="street-address">
                                @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- City & Country -->
                            <div class="col-md-6">
                                <label class="form-label" for="bizCity">City <small class="small-muted">(optional)</small></label>
                                <input id="bizCity" wire:model="city" class="form-control @error('city') is-invalid @enderror" placeholder="e.g. Lagos, Abuja">
                                @error('city') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Country -->
                            <div class="col-md-6">
                                <label class="form-label" for="bizCountry">Country <small class="small-muted">(optional)</small></label>
                                <select id="bizCountry" wire:model.live="country_id" class="form-select @error('country_id') is-invalid @enderror">
                                    <option value="">Select country</option>
                                    @foreach($countries as $country)
                                        <option value="{{ $country->id }}">{{ $country->name }}</option>
                                    @endforeach
                                </select>
                                @error('country_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- State (Nigeria only) -->
                            @if($this->isNigeriaSelected())
                                <div class="col-md-6">
                                    <label class="form-label" for="bizState">State <small class="small-muted">(optional)</small></label>
                                    <select id="bizState" wire:model="state_id" class="form-select @error('state_id') is-invalid @enderror">
                                        <option value="">Select state</option>
                                        @foreach($states as $state)
                                            <option value="{{ $state->id }}">{{ $state->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('state_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @endif

                            <!-- Description -->
                            <div class="col-12">
                                <label class="form-label" for="bizDescription">Short description</label>
                                <textarea id="bizDescription" wire:model="description" class="form-control @error('description') is-invalid @enderror" rows="3" placeholder="Describe your business in 1–2 sentences" required autocomplete="off"></textarea>
                                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- 24/7 Toggle -->
                            <div class="col-12">
                                <div class="d-flex align-items-center gap-3 p-3 toggle-247-container">
                                    <div class="form-check form-switch m-0">
                                        <input
                                            type="checkbox"
                                            wire:model.live="is_24_7"
                                            class="form-check-input toggle-247"
                                            id="is247Toggle"
                                            role="switch"
                                        >
                                    </div>
                                    <label for="is247Toggle" class="form-label mb-0" style="cursor: pointer;">
                                        <strong>Open 24/7</strong>
                                        <div class="small-muted">Business is open around the clock, every day</div>
                                    </label>
                                </div>
                            </div>

                            <!-- Business hours -->
                            <div class="col-12">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div>
                                        <label class="form-label mb-0">Business hours</label>
                                        <div class="small-muted">Set opening hours for each day</div>
                                    </div>

                                    <div class="hours-preset-group mb-3" role="group" aria-label="Hours presets">
                                        <button type="button" wire:click="setEveryDay" class="btn-hours-preset" {{ $is_24_7 ? 'disabled' : '' }}>Every day</button>
                                        <button type="button" wire:click="setWeekdays" class="btn-hours-preset" {{ $is_24_7 ? 'disabled' : '' }}>Weekdays</button>
                                        <button type="button" wire:click="clearHours" class="btn-hours-preset" {{ $is_24_7 ? 'disabled' : '' }}>Clear</button>
                                    </div>
                                </div>

                                <div class="hours-grid">
                                    @foreach($hours as $index => $hour)
                                        <div class="hours-day">{{ $hour['day'] }}</div>
                                        <div class="hours-controls">
                                            <input type="checkbox" wire:model.live="hours.{{ $index }}.is_open" class="form-check-input me-2 day-open" id="open-{{ $index }}">
                                            <label for="open-{{ $index }}" class="small-muted me-2">Open</label>
                                            <input type="time" wire:model="hours.{{ $index }}.open_time" class="form-control form-control-sm me-1 time-from {{ $hour['is_open'] ? 'time-enabled' : '' }}" {{ !$hour['is_open'] ? 'disabled' : '' }}>
                                            <input type="time" wire:model="hours.{{ $index }}.close_time" class="form-control form-control-sm time-to {{ $hour['is_open'] ? 'time-enabled' : '' }}" {{ !$hour['is_open'] ? 'disabled' : '' }}>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <!-- Products & Services -->
                            <div class="col-12">
                                <label class="form-label">Products & Services</label>
                                <div class="input-group mb-2">
                                    <input id="productInput" wire:model="currentProduct" wire:keydown.enter.prevent="addProduct" type="text" class="form-control" placeholder="Add a product or service and press Enter">
                                    <button class="btn btn-outline-secondary" type="button" wire:click="addProduct" id="addProductBtn" {{ empty(trim($currentProduct ?? '')) ? 'disabled' : '' }}>Add</button>
                                </div>
                                <div class="chips" id="productsList" aria-live="polite" aria-atomic="true">
                                    @foreach($products as $index => $product)
                                        <div class="chip">
                                            <span>{{ $product }}</span>
                                            <button type="button" class="btn btn-sm btn-link p-0 ms-2 remove-product" wire:click="removeProduct({{ $index }})" aria-label="Remove {{ $product }}">&times;</button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer" style="gap:.5rem;padding:18px;">
                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal" wire:click="resetForm">Cancel</button>
                        <button type="submit" class="btn btn-cta" wire:loading.attr="disabled">
                            <span wire:loading.remove>
                                {{ $this->isEditMode ? 'Update Listing' : 'Submit Listing for Verification' }}
                            </span>
                            <span wire:loading>
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                {{ $this->isEditMode ? 'Updating...' : 'Submitting...' }}
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        /* Modal modern styling */
        .modal-modern .modal-content {
            border-radius: 0px !important;
            overflow: hidden;
            box-shadow: 0 22px 60px rgba(20,42,59,0.12);
            border: none;
        }
        .modal-modern .modal-header {
            background: rgba(29,191,115,0.08);
            border-bottom: 1px solid rgba(29,191,115,0.12);
            padding: 18px 20px;
            border-radius: 0 !important;
        }
        .modal-modern .modal-title { font-weight:800; color:var(--dark-color); }
        .modal-modern .modal-body { padding: 18px; background: var(--bg-light-gray); }
        .form-label { font-weight:700; font-size:.95rem; color:var(--dark-color); }
        .small-muted { color: var(--muted); font-size: .88rem; }

        /* 24/7 Toggle styling */
        .toggle-247-container {
            background: rgba(29,191,115,0.08);
            border-radius: 0px;
        }
        .form-switch .toggle-247 {
            width: 46px !important;
            height: 26px !important;
            margin-left: 0 !important;
            background-image: none !important;
            background: linear-gradient(180deg, rgba(20,42,59,0.06), rgba(20,42,59,0.04)) !important;
            border: 1px solid rgba(20,42,59,0.06) !important;
            border-radius: 999px !important;
            position: relative !important;
            cursor: pointer;
        }
        .form-switch .toggle-247::before {
            content: "" !important;
            position: absolute !important;
            top: 2px !important;
            left: 2px !important;
            width: 20px !important;
            height: 20px !important;
            border-radius: 50% !important;
            background: #fff !important;
            box-shadow: 0 2px 8px rgba(20,42,59,0.15) !important;
            transition: left .18s ease, transform .18s ease !important;
        }
        .form-switch .toggle-247:checked {
            background: var(--light-color) !important;
            border-color: var(--light-color) !important;
        }
        .form-switch .toggle-247:checked::before {
            left: 22px !important;
        }

        /* Hours grid */
        .hours-grid { display:grid; grid-template-columns: 1fr auto; gap:8px 12px; align-items:center; }
        .hours-day { display:flex; align-items:center; gap:12px; }

        /* Branded toggle switches for "Open" checkboxes */
        .hours-controls {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .hours-controls .form-check-input {
            width: 46px;
            height: 26px;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            border-radius: 999px;
            background: linear-gradient(180deg, rgba(20,42,59,0.06), rgba(20,42,59,0.04));
            border: 1px solid rgba(20,42,59,0.06);
            position: relative;
            outline: none;
            transition: background .18s ease, box-shadow .12s ease, transform .12s ease;
            cursor: pointer;
            display:inline-block;
            vertical-align: middle;
        }

        .hours-controls .form-check-input::after {
            content: "";
            position: absolute;
            top: 3px;
            left: 3px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 6px 14px rgba(20,42,59,0.08);
            transition: left .18s ease, background .18s ease, transform .12s ease;
        }

        /* Checked state */
        .hours-controls .form-check-input:checked {
            background: var(--light-color);
            border-color: var(--light-color);
            box-shadow: 0 6px 18px rgba(29,191,115,0.15);
        }
        .hours-controls .form-check-input:checked::after {
            left: 23px;
            transform: translateX(0);
        }

        /* Focus outline for keyboard users */
        .hours-controls .form-check-input:focus {
            box-shadow: 0 0 0 4px rgba(27,169,222,0.09);
        }

        /* Label next to toggle */
        .hours-controls label.small-muted {
            margin-right:8px;
            color:var(--muted);
            font-weight:700;
            font-size:.92rem;
        }

        /* Time inputs styling when enabled/disabled */
        .hours-controls .form-control-sm {
            width:110px;
            padding:.35rem .5rem;
            height:auto;
            border-radius:0px;
            border:1px solid rgba(20,42,59,0.06);
            transition: box-shadow .12s ease, border-color .12s ease, opacity .12s ease;
            background: #fff;
        }

        .hours-controls .form-control-sm:disabled {
            opacity: .45;
            background: linear-gradient(180deg, rgba(20,42,59,0.01), rgba(20,42,59,0.01));
            cursor: not-allowed;
        }

        /* provide .time-enabled helper */
        .time-enabled {
            border-color: rgba(27,169,222,0.18) !important;
            box-shadow: 0 8px 20px rgba(27,169,222,0.06);
        }

        /* Hours preset buttons (brand) */
        .hours-preset-group {
            display: inline-flex;
            border: 1.5px solid rgba(20,42,59,0.25);
            border-radius: 0px;
            overflow: hidden;
        }

        .btn-hours-preset {
            background: #fff;
            border: none;
            border-radius: 0;
            padding: 8px 18px;
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--dark-color);
            cursor: pointer;
            transition: all .18s ease;
            font-family: inherit;
        }

        .btn-hours-preset:not(:last-child) {
            border-right: 1.5px solid rgba(20,42,59,0.25);
        }

        /* Hover */
        .btn-hours-preset:hover {
            background: rgba(27,169,222,0.06);
            color: var(--text-blue);
        }

        /* Active selection */
        .btn-hours-preset.active {
            background: var(--light-color);
            color: #fff !important;
            box-shadow: 0 5px 16px rgba(29,191,115,0.2);
        }

        /* Products add button (branded) */
        #addProductBtn {
            border-radius: 0px;
            padding: .48rem .85rem;
            font-weight:800;
            color:#fff;
            background: var(--light-color);
            border: none;
            box-shadow: 0 10px 28px rgba(29,191,115,0.15);
            transition: transform .12s ease, box-shadow .12s ease, opacity .12s ease;
            height: 38px;
        }
        #addProductBtn:hover { transform: translateY(-3px); box-shadow: 0 18px 40px rgba(29,191,115,0.2); background: #17A05C; }
        #addProductBtn:active { transform: translateY(-1px); }

        /* disabled look */
        #addProductBtn:disabled {
            opacity: .55;
            box-shadow: none;
            transform: none;
            cursor: not-allowed;
        }

        /* Chips styling (products list) */
        .chips { gap:10px; display: flex; flex-wrap: wrap; }
        .chip {
            padding:.45rem .7rem;
            display:inline-flex;
            align-items:center;
            gap:.5rem;
            border-radius:0px;
            background: linear-gradient(180deg, #fff, rgba(20,42,59,0.01));
            border:1px solid rgba(20,42,59,0.06);
            box-shadow: 0 8px 20px rgba(20,42,59,0.04);
            font-weight:700;
            color:var(--dark-color);
        }
        .chip button.remove-product {
            background:transparent;
            border:none;
            color:var(--muted);
            font-size:18px;
            line-height:1;
            padding:0 6px;
            cursor:pointer;
            border-radius:0px;
            transition: color .12s ease, background .12s ease;
            text-decoration: none;
        }
        .chip button.remove-product:hover {
            color:#c0392b;
            background: rgba(244,185,66,0.06);
        }

        /* Buttons */
        .btn-cta { background: var(--light-color); color:#fff; border: none; border-radius:0px; padding:.5rem .9rem; font-weight:800; }
        .btn-ghost { background:transparent; border:1px solid rgba(20,42,59,0.06); border-radius:0px; color:var(--dark-color); padding:.45rem .8rem; }

        /* mobile-friendly tap targets */
        @media (max-width:480px) {
            .chip { padding:.6rem .9rem; font-size:.95rem; }
            #addProductBtn { width:100%; }
            .hours-controls .form-control-sm { width:100px; }
        }

        /* responsive tweaks */
        @media (max-width:650px){
            .hours-grid { grid-template-columns: 1fr; }
            .hours-controls { justify-content:flex-end; }
        }

        /* Small screens */
        @media (max-width: 500px) {
            .btn-hours-preset {
                padding: 7px 14px;
                font-size: .9rem;
            }
        }
    </style>
</div>

@script
<script>
    $wire.on('close-modal', () => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('createBusinessModal'));
        if (modal) {
            modal.hide();
        }
    });

    $wire.on('open-modal', () => {
        const modalEl = document.getElementById('createBusinessModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    });

    $wire.on('open-create-business-modal', () => {
        const modalEl = document.getElementById('createBusinessModal');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    $wire.on('close-create-business-modal', () => {
        bootstrap.Modal.getInstance(
            document.getElementById('createBusinessModal')
        )?.hide();
    });


    $wire.on('business-saved', (event) => {
        // If creating a new business, switch to unverified tab, otherwise preserve current tab
        if (event.isNewBusiness) {
            sessionStorage.setItem('activeTab', '#unverified');
        } else {
            // Preserve current tab when editing
            const activeTab = document.querySelector('.nav-tabs .nav-link.active');
            if (activeTab) {
                const tabId = activeTab.getAttribute('data-bs-target');
                if (tabId) {
                    sessionStorage.setItem('activeTab', tabId);
                }
            }
        }
        window.location.reload();
    });

    $wire.on('business-deleted', () => {
        // Preserve current tab when reloading
        const activeTab = document.querySelector('.nav-tabs .nav-link.active');
        if (activeTab) {
            const tabId = activeTab.getAttribute('data-bs-target');
            if (tabId) {
                sessionStorage.setItem('activeTab', tabId);
            }
        }
        window.location.reload();
    });
</script>
@endscript
