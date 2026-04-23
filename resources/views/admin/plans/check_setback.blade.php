@extends('layouts.app')

@section('title', 'Setback Checker')

@section('header_styles')
    <style>
        .plan-preview-frame {
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 0.75rem;
            overflow: hidden;
            background: #ffffff;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.07);
        }
        .plan-preview-frame header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.25);
        }
        .plan-preview-controls {
            padding: 0.65rem 1rem;
            border-top: 1px solid rgba(148, 163, 184, 0.25);
            background: #f8fafc;
        }
        .plan-preview-viewport {
            position: relative;
            overflow: auto;
            background: #0f172a;
            min-height: 420px;
            max-height: 640px;
            --zoom-scale: 1;
        }
        .plan-preview-canvas {
            display: inline-flex;
            transform-origin: top left;
            transform: scale(var(--zoom-scale));
        }
        .plan-preview-media {
            border: none;
            display: block;
            max-width: none;
            min-width: 600px;
        }
        .plan-preview-media img,
        .plan-preview-media object {
            max-width: none;
        }
        .plan-preview-media img {
            display: block;
        }
        @media (max-width: 767px) {
            .plan-preview-viewport {
                min-height: 280px;
            }
        }
    </style>
@endsection

@section('content')
    <div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
        <div>
            <h1>Building Plan Setback Checker</h1>
            <div class="subtitle">
                Upload a building plan PDF, extract attributes, and verify side setbacks (e.g., 5 ft on both sides).
            </div>
        </div>
        @if($result && ($result['status'] ?? null) === 'ok')
            <div class="mt-3 mt-md-0">
                @if($result['meets_requirement'])
                    <span class="badge badge-pill-soft badge-soft-success">
                        ✅ Compliant
                    </span>
                @else
                    <span class="badge badge-pill-soft badge-soft-danger">
                        ⚠️ Possibly Non-compliant
                    </span>
                @endif
            </div>
        @endif
    </div>

    <div class="row g-4">
        {{-- Upload form + quick summary --}}
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">
                    1. Upload Plan & Run Check
                </div>
                <div class="card-body">
                    @if ($errors->any())
                        <div class="alert alert-danger small">
                            <strong>There were some problems with your input:</strong>
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('admin.plan.check-setback.submit') }}"
                          method="POST" enctype="multipart/form-data">
                        @csrf

                        <div class="mb-3">
                            <label for="plan_pdf" class="form-label fw-semibold">
                                Plan PDF file
                            </label>
                            <input type="file"
                                   name="plan_pdf"
                                   id="plan_pdf"
                                   class="form-control form-control-sm"
                                   accept=".pdf,.dwg,application/pdf,application/acad"
                                   required>
                            <div class="form-text">
                                Upload the sanctioned drawing PDF or DWG (max 50 MB).
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="required_setback_ft" class="form-label fw-semibold">
                                Required setback (feet)
                            </label>
                            <input type="number"
                                   step="0.1"
                                   name="required_setback_ft"
                                   id="required_setback_ft"
                                   class="form-control form-control-sm"
                                   value="{{ old('required_setback_ft', $result['required_setback_ft'] ?? 5) }}">
                            <div class="form-text">
                                Default: 5 ft (e.g., 5 ft side space rule).
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            Run Setback Check
                        </button>
                    </form>

                    @if ($result && ($result['status'] ?? null) === 'ok')
                        <hr>
                        <div class="small text-muted">
                            Last run:
                            <strong>
                                Global min: {{ $result['global_min_setback_ft'] }} ft
                            </strong>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Numeric results --}}
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>2. Setback Results</span>
                    @if ($result && ($result['status'] ?? null) === 'ok')
                        @if ($result['meets_requirement'])
                            <span class="badge badge-pill-soft badge-soft-success">Meets rule</span>
                        @else
                            <span class="badge badge-pill-soft badge-soft-danger">Check required</span>
                        @endif
                    @endif
                </div>
                <div class="card-body">
                    @if (!$result)
                        <p class="text-muted small mb-0">
                            Run a check to see measured setbacks here.
                        </p>
                    @elseif (($result['status'] ?? null) !== 'ok')
                        <div class="alert alert-danger small mb-0">
                            <strong>Error:</strong> {{ $result['message'] ?? 'Unknown error' }}
                        </div>
                    @else
                        <div class="row gy-3">
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">Required setback</span>
                                    <span class="fw-semibold">
                                        {{ $result['required_setback_ft'] }} ft
                                    </span>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">Global min setback</span>
                                    @php($globalMin = $result['global_min_setback_ft'] ?? null)
                                    <span class="fw-semibold">
                                        {{ $globalMin !== null ? $globalMin . ' ft' : 'N/A' }}
                                    </span>
                                </div>
                                <div class="form-text">
                                    Closest distance between building and boundary (approx).
                                </div>
                            </div>

                            <div class="col-6">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">Left side</span>
                                    @php($leftVal = $result['left_setback_ft'] ?? null)
                                    <span class="fw-semibold">
                                        {{ $leftVal !== null ? $leftVal . ' ft' : 'N/A' }}
                                    </span>
                                </div>
                            </div>

                            <div class="col-6">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">Right side</span>
                                    @php($rightVal = $result['right_setback_ft'] ?? null)
                                    <span class="fw-semibold">
                                        {{ $rightVal !== null ? $rightVal . ' ft' : 'N/A' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        @if (!empty($result['notes']))
                            <hr>
                            <h6 class="small text-uppercase text-muted mb-2">Analysis notes</h6>
                            <ul class="small mb-0">
                                @foreach ($result['notes'] as $note)
                                    <li>{{ $note }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        {{-- Attributes extracted from map --}}
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">
                    3. Attributes Read from Map
                </div>
                <div class="card-body">
                    @if (!$attributes)
                        <p class="text-muted small mb-0">
                            After you upload a plan, dimension texts and labels detected from the PDF will appear here
                            so you can verify that the map is labeled correctly (no misleading naming).
                        </p>
                    @else
                        @php($floors = $attributes['floors_detected'] ?? [])

                        {{-- Site metrics --}}
                        <h6 class="small text-uppercase text-muted mb-2">Site metrics</h6>
                        <table class="table table-sm table-borderless align-middle small mb-3">
                            <tbody>
                            <tr>
                                <td class="text-muted">Plot width</td>
                                <td class="fw-semibold">{{ $attributes['plot_width_ft'] ?? 'N/A' }} ft</td>
                                <td class="text-muted">Plot depth</td>
                                <td class="fw-semibold">{{ $attributes['plot_depth_ft'] ?? 'N/A' }} ft</td>
                            </tr>
                            <tr>
                                <td class="text-muted">Building width</td>
                                <td class="fw-semibold">{{ $attributes['building_width_ft'] ?? 'N/A' }} ft</td>
                                <td class="text-muted">Building depth</td>
                                <td class="fw-semibold">{{ $attributes['building_depth_ft'] ?? 'N/A' }} ft</td>
                            </tr>
                            <tr>
                                <td class="text-muted">Left side space</td>
                                <td class="fw-semibold">
                                    {{ $attributes['left_setback_measured_ft'] ?? 'N/A' }} ft
                                </td>
                                <td class="text-muted">Right side space</td>
                                <td class="fw-semibold">
                                    {{ $attributes['right_setback_measured_ft'] ?? 'N/A' }} ft
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">Front space</td>
                                <td class="fw-semibold">
                                    {{ $attributes['front_setback_measured_ft'] ?? 'N/A' }} ft
                                </td>
                                <td class="text-muted">Rear space</td>
                                <td class="fw-semibold">
                                    {{ $attributes['rear_setback_measured_ft'] ?? 'N/A' }} ft
                                </td>
                            </tr>
                            </tbody>
                        </table>

                        {{-- Compliance helpers --}}
                        <div class="small mb-3">
                            <div>
                                <strong>Total floors:</strong> {{ $attributes['total_floors'] ?? count($floors) ?: 'N/A' }}
                            </div>
                            <div>
                                <strong>Ground floor car parking:</strong>
                                {{ ($attributes['ground_floor_has_car_parking'] ?? false) ? 'Detected' : 'Missing' }}
                            </div>
                            <div>
                                <strong>Washrooms aligned (1st & 2nd floors):</strong>
                                @php($aligned = $attributes['washrooms_first_second_share_dims'] ?? null)
                                {{ $aligned === null ? 'Unknown' : ($aligned ? 'Likely same vertical stack' : 'Not matched') }}
                            </div>
                        </div>

                        {{-- Rule evaluations --}}
                        @if (!empty($attributes['rule_evaluations']))
                            <h6 class="small text-uppercase text-muted mb-2">Rule evaluations</h6>
                            <div class="list-group mb-3">
                                @foreach ($attributes['rule_evaluations'] as $rule)
                                    <div class="list-group-item small">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="fw-semibold">{{ $rule['title'] ?? $rule['rule_id'] }}</span>
                                            <span class="badge {{ ($rule['status'] ?? '') === 'pass' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">
                                                {{ ucfirst($rule['status'] ?? 'n/a') }}
                                            </span>
                                        </div>
                                        <div class="text-muted">
                                            {{ $rule['explanation'] ?? '' }}
                                        </div>
                                        @if(!empty($rule['note']))
                                            <div class="mt-1 text-muted">Band: {{ $rule['note'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Floor summary --}}
                        <h6 class="small text-uppercase text-muted mb-2">Floor summary</h6>
                        @if (!empty($floors))
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-striped align-middle small">
                                    <thead>
                                    <tr>
                                        <th>Floor</th>
                                        <th class="text-center">Rooms</th>
                                        <th class="text-center">Baths</th>
                                        <th class="text-center">Car parking</th>
                                        <th>Covered area (sft)</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($floors as $floor)
                                        <tr>
                                            <td class="fw-semibold">{{ $floor['name'] }}</td>
                                            <td class="text-center">{{ $floor['room_count'] ?? '—' }}</td>
                                            <td class="text-center">{{ $floor['bathroom_count'] ?? '—' }}</td>
                                            <td class="text-center">
                                                @if (!empty($floor['has_car_parking']))
                                                    <span class="badge bg-success-subtle text-success">Yes</span>
                                                @else
                                                    <span class="badge bg-secondary-subtle text-muted">No</span>
                                                @endif
                                            </td>
                                            <td>{{ $floor['covered_area_sft'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted small">No floor summaries detected.</p>
                        @endif

                        {{-- Violations --}}
                        @if (!empty($attributes['violations']))
                            <h6 class="small text-uppercase text-muted mb-1">Potential violations</h6>
                            <ul class="small mb-3">
                                @foreach ($attributes['violations'] as $violation)
                                    <li>{{ $violation }}</li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- Dimension texts --}}
                        <h6 class="small text-uppercase text-muted mb-1">Dimension texts</h6>
                        @if (!empty($attributes['dimension_texts']))
                            <ul class="small mb-2">
                                @foreach ($attributes['dimension_texts'] as $dim)
                                    <li><code>{{ $dim }}</code></li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-muted small">No dimension strings detected.</p>
                        @endif

                        {{-- Labels / room names --}}
                        <h6 class="small text-uppercase text-muted mb-1 mt-3">
                            Labels / room names
                        </h6>
                        @if (!empty($attributes['labels']))
                            <div class="small mb-2">
                                @foreach ($attributes['labels'] as $label)
                                    <span class="attr-label-badge me-1 mb-1">
                                        <i class="bi bi-tag"></i> {{ $label }}
                                    </span>
                                @endforeach
                            </div>
                        @else
                            <p class="text-muted small">No labels detected.</p>
                        @endif

                        {{-- Raw text (debug) --}}
                        @if (!empty($attributes['raw_texts']))
                            <details class="mt-3">
                                <summary class="small text-uppercase text-muted mb-1">Raw text (debug)</summary>
                                <div class="border rounded-3 p-2 small mt-2"
                                     style="max-height: 200px; overflow-y: auto; background:#f9fafb;">
                                    @foreach ($attributes['raw_texts'] as $txt)
                                        {{ $txt }}<br>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    @endif
                </div>
            </div>
        </div>
</div>
@php($visualizations = $result['visualizations'] ?? [])
@if(!empty($visualizations))
    <div class="card mt-4">
        <div class="card-header">
            4. Visual overlays
        </div>
        <div class="card-body">
            <p class="text-muted small">
                Graphs and previews generated from the uploaded plan. Use them to validate the detected footprint and setbacks.
            </p>
            <div class="row g-4">
                @foreach($visualizations as $viz)
                    <div class="col-lg-6">
                        <div class="plan-preview-frame h-100">
                            <header class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold">{{ $viz['label'] ?? 'Preview' }}</span>
                                @if(!empty($viz['public_url']))
                                    <a href="{{ $viz['public_url'] }}" target="_blank" class="small text-decoration-none">
                                        Open in new tab
                                    </a>
                                @endif
                            </header>
                            @php($previewId = 'plan-preview-' . $loop->index)
                            <div class="plan-preview-viewport" id="{{ $previewId }}">
                                <div class="plan-preview-canvas">
                                    @if(($viz['format'] ?? null) === 'svg')
                                        <object class="plan-preview-media"
                                                data="{{ $viz['public_url'] ?? '#' }}"
                                                type="image/svg+xml"
                                                tabindex="-1"></object>
                                    @else
                                        <img class="plan-preview-media"
                                             src="{{ $viz['public_url'] ?? '#' }}"
                                             alt="{{ $viz['label'] ?? 'Overlay' }}"
                                             loading="lazy">
                                    @endif
                                </div>
                            </div>
                            <div class="plan-preview-controls d-flex align-items-center justify-content-between gap-3 flex-wrap">
                                <div class="d-flex align-items-center gap-2">
                                    <label for="{{ $previewId }}-zoom" class="small text-muted mb-0">Zoom</label>
                                    <input type="range"
                                           id="{{ $previewId }}-zoom"
                                           class="form-range plan-preview-zoom"
                                           min="25" max="300" step="5" value="100"
                                           data-target="{{ $previewId }}">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary plan-preview-reset"
                                            data-target="{{ $previewId }}">
                                        Reset
                                    </button>
                                </div>
                                <small class="text-muted ms-auto">
                                    Scroll to pan • Drag slider to zoom
                                </small>
                            </div>
                            @if(($viz['label'] ?? '') === 'Setback graph (DXF)')
                                <div class="border-top px-3 py-3">
                                    @php($measurements = $attributes['measurement_annotations'] ?? [])
                                    @if(!empty($measurements))
                                        <h6 class="small text-uppercase text-muted mb-2">Measured setbacks</h6>
                                        <table class="table table-sm table-bordered small mb-3">
                                            <tbody>
                                            @foreach($measurements as $m)
                                                <tr>
                                                    <td class="w-25 text-muted">{{ $m['label'] ?? 'Setback' }}</td>
                                                    <td>{{ $m['distance_label'] ?? $m['label'] }}</td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    @endif
                                    @if(!empty($attributes['rule_evaluations']))
                                        <h6 class="small text-uppercase text-muted mb-2">Rule summary</h6>
                                        <ul class="list-unstyled small mb-0">
                                            @foreach($attributes['rule_evaluations'] as $rule)
                                                <li class="mb-2">
                                                    <strong>{{ $rule['title'] ?? $rule['rule_id'] }}</strong>
                                                    <span class="badge {{ ($rule['status'] ?? '') === 'pass' ? 'bg-success-subtle text-success' : (($rule['status'] ?? '') === 'fail' ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-muted') }}">
                                                        {{ ucfirst($rule['status'] ?? 'n/a') }}
                                                    </span>
                                                    <div class="text-muted">
                                                        {{ $rule['explanation'] ?? '' }}
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
@endsection

@section('footer_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const updateZoom = (targetId, value) => {
                const viewport = document.getElementById(targetId);
                if (!viewport) return;
                const canvas = viewport.querySelector('.plan-preview-canvas');
                if (!canvas) return;
                const scale = (parseInt(value, 10) || 100) / 100;
                canvas.style.setProperty('transform', `scale(${scale})`);
                viewport.style.setProperty('--zoom-scale', scale);
            };

            document.querySelectorAll('.plan-preview-zoom').forEach(range => {
                range.addEventListener('input', event => {
                    updateZoom(event.currentTarget.dataset.target, event.currentTarget.value);
                });
            });

            document.querySelectorAll('.plan-preview-reset').forEach(button => {
                button.addEventListener('click', event => {
                    const targetId = event.currentTarget.dataset.target;
                    const slider = document.getElementById(`${targetId}-zoom`);
                    if (slider) {
                        slider.value = 100;
                        updateZoom(targetId, 100);
                    }
                });
            });
        });
    </script>
@endsection
