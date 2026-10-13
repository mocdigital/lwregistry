                            <!-- Step indicator -->
                            <div class="d-flex justify-content-between align-items-center mb-3 sbo-steps premium-auth-stepper">
                                @foreach (['Business Info', 'Owner Info', 'Account'] as $i => $label)
                                    @php $step = $i + 1; @endphp
                                    <div class="text-center flex-fill">
                                        <span class="sbo-step-badge {{ $businessStep >= $step ? 'active' : '' }}">{{ $step }}</span>
                                        <div class="small mt-1 {{ $businessStep === $step ? 'fw-bold' : 'text-muted' }}">{{ $label }}</div>
                                    </div>
                                    @if ($step < 3)
                                        <div class="sbo-step-line {{ $businessStep > $step ? 'active' : '' }}"></div>
                                    @endif
                                @endforeach
                            </div>

                            {{-- Registration notice (persists across all steps) --}}
                            <div class="provisional-callout premium-auth-callout mb-3" role="note">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0 mt-1">
                                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                                </svg>
                                <span>
                                    Your application is approved after signup. Full verification depends on verified
                                    payment and compliant registration documents.
                                </span>
                            </div>

                            <form wire:submit.prevent="registerBusiness" class="mb-3 premium-auth-form" data-form-help-skip>
                                {{-- ---------------- PAGE 1: Business Information ---------------- --}}
                                <div class="premium-auth-step-panel" @if($businessStep !== 1) style="display:none" @endif>
                                    <h6 class="fw-bold mb-3">Section A: Business Information</h6>

                                    <div class="mb-3">
                                        <input type="text" wire:model="bizName" class="form-control" placeholder="Business Name" data-form-help="Enter the official or public name customers will recognize for this business.">
                                        @error('bizName') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small text-muted mb-1">Select Business or Entity Type</label>
                                        <select wire:model="bizEntityType" class="form-select" data-form-help="SBO is a Strategic Business Organization; COB is a Citizen Owned Business.">
                                            @foreach(\App\Models\Sbo::ENTITY_TYPES as $entityValue => $entityLabel)
                                                <option value="{{ $entityValue }}">{{ $entityLabel }}</option>
                                            @endforeach
                                        </select>
                                        @error('bizEntityType') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <select wire:model="bizNature" class="form-select" data-form-help="Choose the business category that best describes your main products or services.">
                                            <option value="" selected>Nature of Business (select category)</option>
                                            @foreach(($allCategories ?? []) as $category)
                                                <option value="{{ $category->name }}">{{ $category->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('bizNature') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>

                                    <div class="mb-3">
                                        <select wire:model="bizYearEstablished" class="form-select">
                                            <option value="" selected>Year Established</option>
                                            @for($y = (int) date('Y'); $y >= 1900; $y--)
                                                <option value="{{ $y }}">{{ $y }}</option>
                                            @endfor
                                        </select>
                                        @error('bizYearEstablished') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <input type="number" wire:model="bizEmployees" class="form-control" placeholder="Number of Employees" min="0">
                                        @error('bizEmployees') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <select wire:model="bizLegalStructure" class="form-select">
                                            <option value="" selected>Select Legal Structure</option>
                                            @foreach($legalStructures as $structure)
                                                <option value="{{ $structure }}">{{ $structure }}</option>
                                            @endforeach
                                        </select>
                                        @error('bizLegalStructure') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <select wire:model="bizPremiseType" class="form-select">
                                            <option value="" selected>Is business physical or virtual? (optional)</option>
                                            <option value="physical">Physical</option>
                                            <option value="virtual">Virtual</option>
                                            <option value="both">Both</option>
                                        </select>
                                        @error('bizPremiseType') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        @include('partials.country-select', [
                                            'model' => 'bizCountry',
                                            'value' => $bizCountry,
                                            'countries' => $countries,
                                            'placeholder' => 'Country Location of Business',
                                            'live' => true,
                                            'selectMethod' => 'selectBusinessCountry',
                                        ])
                                        @error('bizCountry') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="row">
                                        <div class="col-12 mb-3">
                                            @php
                                                $statePlaceholder = $bizCountry === ''
                                                    ? 'Select country first'
                                                    : ($availableStates === [] ? 'No states/regions available' : 'Select State / Region');
                                            @endphp
                                            <div wire:key="biz-state-picker-{{ md5($bizCountry . '|' . implode('|', $availableStates)) }}">
                                                @include('partials.state-select', [
                                                    'model' => 'bizState',
                                                    'value' => $bizState,
                                                    'states' => $availableStates,
                                                    'placeholder' => $statePlaceholder,
                                                    'disabled' => $bizCountry === '' || $availableStates === [],
                                                    'selectMethod' => 'selectBusinessState',
                                                ])
                                            </div>
                                            <div wire:loading wire:target="bizCountry" class="form-text small">Loading states…</div>
                                            @error('bizState') <span class="text-danger small">{{ $message }}</span> @enderror
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <input type="text" wire:model="bizAddress" class="form-control" placeholder="Business Address">
                                        @error('bizAddress') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        @include('partials.intl-phone', [
                                            'model' => 'bizPhone',
                                            'value' => $bizPhone,
                                            'inputId' => 'bizPhoneInput',
                                            'mode' => 'local-digits',
                                            'placeholder' => '08012345678',
                                        ])
                                        @error('bizPhone') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <input type="email" wire:model="bizEmail" class="form-control" placeholder="Business Email Address">
                                        @error('bizEmail') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <input type="text" wire:model="bizWebsite" class="form-control" placeholder="Website (if any, e.g. www.example.com)">
                                        @error('bizWebsite') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>

                                    {{-- Social media accounts (dynamic) --}}
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label mb-0 small text-muted">Social Media (if any)</label>
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-circle d-flex align-items-center justify-content-center"
                                                style="width:28px;height:28px;padding:0;line-height:1;"
                                                wire:click="addSocial" title="Add social media account">
                                                <i class="bi bi-plus"></i>
                                            </button>
                                        </div>

                                        @forelse($bizSocials as $i => $social)
                                            <div class="row g-2 mb-2 align-items-start" wire:key="social-{{ $i }}">
                                                <div class="col-4">
                                                    <select class="form-select" wire:model="bizSocials.{{ $i }}.platform">
                                                        <option value="">Platform…</option>
                                                        @foreach($socialPlatforms as $p)
                                                            <option value="{{ $p }}">{{ $p }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('bizSocials.'.$i.'.platform') <span class="text-danger small">{{ $message }}</span> @enderror
                                                </div>
                                                <div class="col-7">
                                                    <div class="input-group">
                                                        <span class="input-group-text">@</span>
                                                        <input type="text" class="form-control"
                                                            wire:model="bizSocials.{{ $i }}.handle"
                                                            placeholder="{{ $social['platform'] ? $social['platform'].' handle' : 'handle' }}">
                                                    </div>
                                                    @error('bizSocials.'.$i.'.handle') <span class="text-danger small">{{ $message }}</span> @enderror
                                                </div>
                                                <div class="col-1 d-flex">
                                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-circle d-flex align-items-center justify-content-center"
                                                        style="width:28px;height:28px;padding:0;line-height:1;"
                                                        wire:click="removeSocial({{ $i }})" title="Remove">
                                                        <i class="bi bi-x"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        @empty
                                            <div class="small text-muted">No social media added. Click the + to add your Instagram, Facebook, X, Kingschat handle and more.</div>
                                        @endforelse
                                    </div>
                                </div>

                                {{-- ---------------- PAGE 2: Owner Information ---------------- --}}
                                <div class="premium-auth-step-panel" @if($businessStep !== 2) style="display:none" @endif>
                                    <h6 class="fw-bold mb-3">Section B: Owner Information</h6>

                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <input type="text" wire:model="ownerSurname" class="form-control" placeholder="Surname">
                                            @error('ownerSurname') <span class="text-danger small">{{ $message }}</span> @enderror
                                        </div>
                                        <div class="col-6 mb-3">
                                            <input type="text" wire:model="ownerFirstName" class="form-control" placeholder="First Name">
                                            @error('ownerFirstName') <span class="text-danger small">{{ $message }}</span> @enderror
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        @include('partials.country-select', [
                                            'model' => 'ownerCountry',
                                            'value' => $ownerCountry,
                                            'countries' => $countries,
                                            'placeholder' => 'Country',
                                        ])
                                        @error('ownerCountry') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <input type="text" wire:model="ownerResidentialAddress" class="form-control" placeholder="Residential Address">
                                        @error('ownerResidentialAddress') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <input type="text" wire:model="ownerKingschat" class="form-control" placeholder="Kingschat Handle">
                                        @error('ownerKingschat') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        @include('partials.intl-phone', [
                                            'model' => 'ownerPhone',
                                            'value' => $ownerPhone,
                                            'inputId' => 'ownerPhoneInput',
                                            'mode' => 'local-digits',
                                            'placeholder' => '08012345678',
                                        ])
                                        @error('ownerPhone') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <input type="email" wire:model="ownerEmail" class="form-control" placeholder="Email (used for your login)">
                                        @error('ownerEmail') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <select wire:model="ownerChurchZone" class="form-select">
                                            <option value="" selected>Church Zone</option>
                                            @foreach($churchZones as $zone)
                                                <option value="{{ $zone }}">{{ $zone }}</option>
                                            @endforeach
                                        </select>
                                        @error('ownerChurchZone') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <input type="text" wire:model="ownerLocalAssembly" class="form-control" placeholder="Local Assembly">
                                        @error('ownerLocalAssembly') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <input type="number" wire:model="ownerYearsAsMember" class="form-control" placeholder="Total Number of Years as Member" min="0">
                                        @error('ownerYearsAsMember') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <input type="text" wire:model="ownerZonalPastor" class="form-control" placeholder="Name of Zonal Pastor">
                                        @error('ownerZonalPastor') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <input type="text" wire:model="ownerLocalPastor" class="form-control" placeholder="Name of Local Pastor">
                                        @error('ownerLocalPastor') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                {{-- ---------------- PAGE 3: Account & Confirm ---------------- --}}
                                <div class="premium-auth-step-panel" @if($businessStep !== 3) style="display:none" @endif>
                                    <h6 class="fw-bold mb-3">Create your account</h6>
                                    <p class="small text-muted">Set a password to manage <strong>{{ $bizName ?: 'your business' }}</strong>. After email verification, you will continue to registration fees.</p>

                                    <div class="row">
                                        <div class="col-12 col-sm-6 mb-3">
                                            <div class="position-relative">
                                                <input id="bizPasswordInput" type="password" wire:model="bizPassword" class="form-control password-toggle-input" placeholder="Password" autocomplete="new-password">
                                                <button type="button" class="btn btn-sm position-absolute end-0 top-50 translate-middle-y password-toggle-btn" data-password-toggle="bizPasswordInput" aria-label="Show password" aria-pressed="false">
                                                    <svg data-password-icon="show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <svg data-password-icon="hide" class="d-none" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                                </button>
                                            </div>
                                            @error('bizPassword') <span class="text-danger small">{{ $message }}</span> @enderror
                                        </div>
                                        <div class="col-12 col-sm-6 mb-3">
                                            <div class="position-relative">
                                                <input id="bizPasswordConfirmationInput" type="password" wire:model="bizPasswordConfirmation" class="form-control password-toggle-input" placeholder="Confirm Password" autocomplete="new-password">
                                                <button type="button" class="btn btn-sm position-absolute end-0 top-50 translate-middle-y password-toggle-btn" data-password-toggle="bizPasswordConfirmationInput" aria-label="Show password" aria-pressed="false">
                                                    <svg data-password-icon="show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <svg data-password-icon="hide" class="d-none" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                                </button>
                                            </div>
                                            @error('bizPasswordConfirmation') <span class="text-danger small">{{ $message }}</span> @enderror
                                        </div>
                                    </div>

                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" wire:model="bizAgreeTerms" id="bizAgreeTerms">
                                        <label class="form-check-label" for="bizAgreeTerms">I agree to the <a href="#">Terms & Privacy Policy.</a></label>
                                    </div>
                                    @error('bizAgreeTerms') <span class="text-danger small d-block mb-2">{{ $message }}</span> @enderror

                                    {{-- ---------------- Kingdom Business Code of Conduct ---------------- --}}
                                    <label class="form-label small fw-bold mb-1">Kingdom Business Code of Conduct</label>
                                    <p class="small text-muted mb-2">Please read the Code below. You must read and agree before submitting your registration.</p>

                                    <div class="code-of-conduct-box mb-2" role="document" aria-label="Loveworld Kingdom Business Code of Conduct" tabindex="0">
                                        <h6 class="fw-bold text-uppercase mb-2">Loveworld Kingdom Business Code of Conduct</h6>
                                        <p class="mb-2">As a registered Kingdom Business under the Ministry of Commerce, Loveworld Kingdom, I commit to:</p>
                                        <ol class="ps-3 mb-2">
                                            <li class="mb-1"><strong>Integrity:</strong> Conduct all business dealings with honesty and transparency.</li>
                                            <li class="mb-1"><strong>Excellence:</strong> Deliver products and services of the highest quality.</li>
                                            <li class="mb-1"><strong>Fair Pricing:</strong> Avoid exploitation, price gouging, or deceptive practices.</li>
                                            <li class="mb-1"><strong>Customer Care:</strong> Treat all customers with respect, dignity, and kindness.</li>
                                            <li class="mb-1"><strong>Ethical Operations:</strong> Refrain from businesses contrary to Christian values (e.g., alcohol, gambling, adult entertainment, fraud).</li>
                                            <li class="mb-1"><strong>Legal Compliance:</strong> Maintain valid government registrations and licenses.</li>
                                            <li class="mb-1"><strong>Accountability:</strong> Submit to the oversight and dispute resolution of the Ministry.</li>
                                            <li class="mb-1"><strong>Testimony:</strong> Allow my business success to be a witness of God's goodness.</li>
                                        </ol>
                                        <p class="mb-0">I understand that violation of this Code may result in suspension or revocation of my registration.</p>
                                    </div>

                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" wire:model="bizAgreeCode" id="bizAgreeCode">
                                        <label class="form-check-label" for="bizAgreeCode">I have read and agree to the <strong>Kingdom Business Code of Conduct</strong>.</label>
                                    </div>
                                    @error('bizAgreeCode') <span class="text-danger small d-block mb-2">{{ $message }}</span> @enderror

                                    <x-turnstile wire-model="turnstileToken" />
                                </div>

                                {{-- ---------------- Navigation buttons ---------------- --}}
                                <div class="premium-action-bar">
                                    <button type="button" class="btn btn-outline-secondary"
                                        wire:click="businessPreviousStep"
                                        @if($businessStep === 1) style="visibility:hidden" @endif>
                                        Back
                                    </button>

                                    @if ($businessStep < 3)
                                        <button type="button" class="btn login-btn rounded-pill" wire:click="businessNextStep">Next</button>
                                    @else
                                        <button type="submit" class="btn login-btn rounded-pill" wire:loading.attr="disabled" wire:target="registerBusiness">
                                            <span wire:loading.remove wire:target="registerBusiness">Submit Registration</span>
                                            <span wire:loading wire:target="registerBusiness">Submitting…</span>
                                        </button>
                                    @endif
                                </div>
                            </form>
