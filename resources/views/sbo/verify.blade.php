@extends('layouts.app')

@section('title', 'Verify SBO Registration - Loveworld Business Registry')

@section('content')
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-lg-5">
                        @if($isValid && $sbo)
                            <div class="d-flex align-items-start gap-3 mb-4">
                                <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width:56px;height:56px;">
                                    <i class="bi bi-patch-check-fill fs-3"></i>
                                </div>
                                <div>
                                    <div class="small text-uppercase text-muted fw-semibold">Ministry of Commerce Registration</div>
                                    <h1 class="h3 mb-1">Valid SBO Registration</h1>
                                    <p class="text-muted mb-0">This registration number is active and verified on the Loveworld Registry platform.</p>
                                </div>
                            </div>

                            <div class="border rounded p-3 mb-3">
                                <div class="small text-muted">Strategic Business Organization</div>
                                <div class="h5 mb-0">{{ $sbo->name }}</div>
                            </div>

                            @if($sbo->seal?->existsOnDisk())
                                <div class="border rounded p-3 mb-3 d-flex flex-column flex-md-row align-items-md-center gap-3">
                                    <img src="{{ $sbo->seal->previewDataUri() ?? $sbo->seal->url() }}" alt="{{ $sbo->name }} digital SBO seal" style="width:132px;height:132px;object-fit:contain;">
                                    <div>
                                        <div class="small text-muted">Personalized Digital Seal</div>
                                        <div class="h5 mb-1">{{ $sbo->seal->issued_number }}</div>
                                        <p class="text-muted small mb-0">This seal was issued after registration, payment, and required-document validation.</p>
                                    </div>
                                </div>
                            @endif

                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="small text-muted">MOC Registration Number</div>
                                        <div class="fw-semibold">{{ $sbo->moc_registration_number }}</div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="small text-muted">Application ID</div>
                                        <div class="fw-semibold">{{ $sbo->application_id ?? '—' }}</div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="small text-muted">Verified Date</div>
                                        <div class="fw-semibold">{{ optional($sbo->verified_notified_at ?? $sbo->payment_confirmed_at)->format('M j, Y') ?? '—' }}</div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="small text-muted">Status</div>
                                        <div class="fw-semibold text-success">Bonafide registered SBO</div>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="d-flex align-items-start gap-3 mb-4">
                                <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center" style="width:56px;height:56px;">
                                    <i class="bi bi-x-circle-fill fs-3"></i>
                                </div>
                                <div>
                                    <div class="small text-uppercase text-muted fw-semibold">Ministry of Commerce Registration</div>
                                    <h1 class="h3 mb-1">Registration Not Validated</h1>
                                    <p class="text-muted mb-0">No fully verified SBO registration was found for this number.</p>
                                </div>
                            </div>

                            <div class="border rounded p-3">
                                <div class="small text-muted">Searched number</div>
                                <div class="fw-semibold">{{ $searchedNumber }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
