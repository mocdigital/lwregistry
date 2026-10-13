<div class="container auth-dashboard-shell sbo-support-remake sbo-remake">
    <x-ad-slot name="dashboard_top" />

    <x-ui.page-header
        title="Support"
        description="Submit an enquiry and the Registry team will review it from the admin workspace."
        :breadcrumbs="[
            ['label' => 'Account', 'href' => route('dashboard'), 'wireNavigate' => true],
            ['label' => 'Support', 'current' => true],
        ]"
    />

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body p-4">
                    @if (session('support_success'))
                        <div class="alert alert-success py-2" role="status" aria-live="polite">{{ session('support_success') }}</div>
                    @endif

                    <form wire:submit.prevent="submit" data-ui-form>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="supportFirstName">First Name</label>
                                <input id="supportFirstName" type="text" wire:model.defer="firstName" class="form-control @error('firstName') is-invalid @enderror" @error('firstName') aria-describedby="supportFirstNameError" aria-invalid="true" @enderror>
                                @error('firstName') <div id="supportFirstNameError" class="invalid-feedback" role="alert">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="supportLastName">Last Name</label>
                                <input id="supportLastName" type="text" wire:model.defer="lastName" class="form-control @error('lastName') is-invalid @enderror" @error('lastName') aria-describedby="supportLastNameError" aria-invalid="true" @enderror>
                                @error('lastName') <div id="supportLastNameError" class="invalid-feedback" role="alert">{{ $message }}</div> @enderror
                            </div>

                            @if($managedSbos->isNotEmpty())
                                <div class="col-12">
                                    <label class="form-label" for="supportSbo">Select SBO <span class="text-muted">(optional)</span></label>
                                    <select id="supportSbo" wire:model.live="selectedSboId" class="form-select @error('selectedSboId') is-invalid @enderror" @error('selectedSboId') aria-describedby="supportSboError" aria-invalid="true" @enderror>
                                        <option value="">Enter SBO number manually</option>
                                        @foreach($managedSbos as $sbo)
                                            <option value="{{ $sbo->id }}">
                                                {{ $sbo->name }} — {{ $sbo->moc_registration_number ?: $sbo->application_id }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('selectedSboId') <div id="supportSboError" class="invalid-feedback" role="alert">{{ $message }}</div> @enderror
                                </div>
                            @endif

                            <div class="col-12">
                                <label class="form-label" for="supportSboNumber">SBO Number <span class="text-muted">(Application ID or MOC Number)</span></label>
                                <input id="supportSboNumber" type="text" wire:model.defer="sboNumber" class="form-control @error('sboNumber') is-invalid @enderror" placeholder="e.g. MBN-2026-0001 or MOC-2026-0001" @error('sboNumber') aria-describedby="supportSboNumberError" aria-invalid="true" @enderror>
                                @error('sboNumber') <div id="supportSboNumberError" class="invalid-feedback" role="alert">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="supportSubject">Subject</label>
                                <input id="supportSubject" type="text" wire:model.defer="subject" class="form-control @error('subject') is-invalid @enderror" placeholder="What do you need help with?" @error('subject') aria-describedby="supportSubjectError" aria-invalid="true" @enderror>
                                @error('subject') <div id="supportSubjectError" class="invalid-feedback" role="alert">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="supportMessage">Message</label>
                                <textarea id="supportMessage" wire:model.defer="message" rows="5" class="form-control @error('message') is-invalid @enderror" placeholder="Describe the issue clearly so the team can help quickly." @error('message') aria-describedby="supportMessageError" aria-invalid="true" @enderror></textarea>
                                @error('message') <div id="supportMessageError" class="invalid-feedback" role="alert">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12">
                                <div class="ui-action-bar">
                                    <x-ui.form-state />
                                    <button type="submit" class="btn btn-primary rounded-pill" wire:loading.attr="disabled">
                                        <span wire:loading.remove>Submit enquiry</span>
                                        <span wire:loading>Submitting...</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-body p-4">
                    <h2 class="h6 mb-3">Before submitting</h2>
                    <ul class="small text-muted ps-3 mb-0">
                        <li>Use your application ID if your SBO is still provisional.</li>
                        <li>Use your MOC number if your SBO is fully verified.</li>
                        <li>Include the page or workflow where the issue happened.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
