@props([
    'title',
    'subtitle' => null,
    'icon' => null,
    'metrics' => [],
])

<div>
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="d-flex align-items-center gap-2">
            <div class="metric-badge">
                <i class="bi bi-{{ $icon }}"></i>
            </div>
            <div>
                <div class="fw-semibold small">{{ $title }}</div>
                @if($subtitle)
                    <div class="small text-muted-soft">{{ $subtitle }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-2">
        @forelse($metrics as $label => $value)
            <div class="col-12 col-sm-6 col-lg-4">
                <div class="card border-0 bg-light h-100">
                    <div class="card-body py-2 px-3">
                        <div class="small text-muted-soft">{{ $label }}</div>
                        <div class="fw-semibold">
                            {{ $value !== null && $value !== '' ? $value : '—' }}
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="small text-muted-soft">No metrics available</div>
            </div>
        @endforelse
    </div>
</div>
